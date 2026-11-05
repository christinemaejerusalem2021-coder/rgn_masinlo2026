<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once 'config.php';

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function validDate(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function peso(float $n): string
{
    return '₱' . number_format($n, 2);
}

/** Regular (no-discount) price of a set of activities for $pax people. */
function bundleRegular(array $items, int $pax): float
{
    $total = 0.0;
    foreach ($items as $k) {
        $a = PAID_ACTIVITIES[$k] ?? null;
        if (!$a) continue;
        $total += $a[3] === 'pax' ? $a[2] * $pax : $a[2];
    }
    return $total;
}

function redirectToBooking(): void
{
    header('Location: booking.php');
    exit;
}

if (empty($_SESSION['booking_token'])) {
    $_SESSION['booking_token'] = bin2hex(random_bytes(32));
}

$today = date('Y-m-d');
$errors = [];
$successMessage = $_SESSION['booking_success'] ?? '';
$bookingReference = $_SESSION['booking_reference'] ?? '';
unset($_SESSION['booking_success'], $_SESSION['booking_reference']);

$form = [
    'customer_name'    => '',
    'email'            => '',
    'contact_number'   => '',
    'address'          => '',
    'check_in_date'    => '',
    'num_guests'       => (string)CAMP_MIN_GUESTS,
    'free_kids'        => '0',
    'dietary_notes'    => '',
    'has_pet'          => '0',
    'pet_details'      => '',
    'special_requests' => '',
    'addon_tent'       => '0',
    'tent_qty'         => '1',
];

$EXTRAS = [
    'addon_tent' => ['Extra Tent Rental', 500.00],
];

$ACTIVITIES   = PAID_ACTIVITIES;
$BUNDLES      = ACTIVITY_BUNDLES;
$bundleSel    = '';
$bundlePax    = (string)CAMP_MIN_GUESTS;
$packagePrice = (float)CAMP_PACKAGE_PRICE;
$capacity     = (int)CAMP_CAPACITY;
$minGuests    = (int)CAMP_MIN_GUESTS;
$nights       = (int)CAMP_NIGHTS;
$mealPlanText = 'Meals included in package';
$maxTents      = 10;
$maxKids       = (int)KID_MAX_QTY;
$kidPrice      = (float)KID_PRICE;
$kidFree       = (int)KID_FREE_QTY;
$REQUESTS     = REQUEST_OPTIONS;
$reqSel       = [];

// Paid activity state (for re-showing the form after an error)
$actState = [];
foreach ($ACTIVITIES as $key => $a) {
    $actState[$key] = ['checked' => false, 'pax' => (string)$minGuests];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $key => $value) {
        $form[$key] = trim((string)($_POST[$key] ?? ''));
    }
    $form['has_pet'] = isset($_POST['has_pet']) && $_POST['has_pet'] === '1' ? '1' : '0';
    foreach ($EXTRAS as $key => $info) {
        $form[$key] = isset($_POST[$key]) && $_POST[$key] === '1' ? '1' : '0';
    }
    foreach ($ACTIVITIES as $key => $a) {
        $actState[$key]['checked'] = isset($_POST['act_' . $key]) && $_POST['act_' . $key] === '1';
        $actState[$key]['pax']     = trim((string)($_POST['pax_' . $key] ?? ''));
    }
    $reqPosted = $_POST['req'] ?? [];
    $reqSel = is_array($reqPosted)
        ? array_values(array_intersect(array_keys($REQUESTS), array_map('strval', $reqPosted)))
        : [];
    $bundleSel = trim((string)($_POST['bundle'] ?? ''));
    $bundlePax = trim((string)($_POST['bundle_pax'] ?? ''));
    $agreedRules = isset($_POST['agreed_rules']);

    $token = $_POST['booking_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['booking_token'], $token)) {
        $errors[] = 'Your session expired. Please refresh the page and try again.';
    }

    if ($form['customer_name'] === '') {
        $errors[] = 'Full name is required.';
    } elseif (mb_strlen($form['customer_name']) > 100) {
        $errors[] = 'Full name must not exceed 100 characters.';
    }
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || strlen($form['email']) > 100) {
        $errors[] = 'Please enter a valid email address (max 100 characters).';
    }
    if ($form['contact_number'] === '') {
        $errors[] = 'Contact number is required.';
    } elseif (mb_strlen($form['contact_number']) > 20) {
        $errors[] = 'Contact number must not exceed 20 characters.';
    }
    if ($form['address'] === '') {
        $errors[] = 'Address (city / province) is required.';
    }

    if (!validDate($form['check_in_date'])) {
        $errors[] = 'Please select a valid check-in date.';
        $checkout = '';
    } else {
        $checkout = (new DateTimeImmutable($form['check_in_date']))
            ->modify('+' . $nights . ' days')->format('Y-m-d');
        if ($form['check_in_date'] < $today) {
            $errors[] = 'Check-in date cannot be in the past.';
        }
    }

    $totalGuests = filter_var($form['num_guests'], FILTER_VALIDATE_INT);
    if ($totalGuests === false || $totalGuests < $minGuests || $totalGuests > $capacity) {
        $errors[] = "Number of guests must be between {$minGuests} and {$capacity}.";
        $totalGuests = 0;
    }

    // Children 3 years old & below: fixed price per head, max quantity, not counted in the guest count
    $kids = filter_var($form['free_kids'] === '' ? '0' : $form['free_kids'], FILTER_VALIDATE_INT);
    if ($kids === false || $kids < 0 || $kids > $maxKids) {
        $errors[] = "Children 3 years old and below must be between 0 and {$maxKids}.";
        $kids = 0;
    }

    // Validate extra tent quantity
    $tentQty = 0;
    if ($form['addon_tent'] === '1') {
        $tentQty = (int)filter_var($form['tent_qty'], FILTER_VALIDATE_INT);
        if ($tentQty < 1 || $tentQty > $maxTents) {
            $errors[] = "Extra tent quantity must be between 1 and {$maxTents}.";
            $tentQty = 0;
        }
    }

    // Validate activity package
    $bundlePaxInt = 0;
    if ($bundleSel !== '') {
        if (!isset($BUNDLES[$bundleSel])) {
            $errors[] = 'Please choose a valid activity package.';
            $bundleSel = '';
        } else {
            $bundlePaxInt = (int)filter_var($bundlePax, FILTER_VALIDATE_INT);
            if ($bundlePaxInt < 1 || ($totalGuests > 0 && $bundlePaxInt > $totalGuests)) {
                $errors[] = $BUNDLES[$bundleSel][0] . ': number of participants must be between 1 and your number of guests.';
            }
        }
    }

    // Validate paid activities
    $chosenActs = [];   // key => participants (1 for per-boat)
    foreach ($ACTIVITIES as $key => $a) {
        if (!$actState[$key]['checked']) continue;
        // Already part of the chosen package: charged through the package only.
        if ($bundleSel !== '' && in_array($key, $BUNDLES[$bundleSel][1], true)) continue;
        if ($a[3] === 'pax') {
            $pax = filter_var($actState[$key]['pax'], FILTER_VALIDATE_INT);
            if ($pax === false || $pax < 1 || ($totalGuests > 0 && $pax > $totalGuests)) {
                $errors[] = $a[0] . ': number of participants must be between 1 and your number of guests.';
                continue;
            }
            $chosenActs[$key] = $pax;
        } else {
            $chosenActs[$key] = 1;
        }
    }

    if (!$agreedRules) $errors[] = 'You must agree to the camp rules and terms.';
    if ($packagePrice <= 0) $errors[] = 'Online booking is temporarily unavailable.';

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $conflict = $pdo->prepare(
                "SELECT booking_id FROM bookings
                 WHERE status IN ('Pending','Confirmed')
                   AND check_in_date <= :checkout
                   AND check_out_date >= :checkin
                 LIMIT 1 FOR UPDATE"
            );
            $conflict->execute([
                ':checkout' => $checkout,
                ':checkin'  => $form['check_in_date'],
            ]);
            if ($conflict->fetch()) {
                throw new RuntimeException(
                    'Those dates are already reserved. Please choose different check-in dates.'
                );
            }

            $userQuery = $pdo->prepare(
                "SELECT user_id FROM users WHERE email = :email
                 ORDER BY user_id ASC LIMIT 1 FOR UPDATE"
            );
            $userQuery->execute([':email' => $form['email']]);
            $existing = $userQuery->fetch();

            if ($existing) {
                $userId = (int)$existing['user_id'];
                $upd = $pdo->prepare(
                    "UPDATE users SET full_name = :name, phone_number = :phone, address = :address
                     WHERE user_id = :id"
                );
                $upd->execute([
                    ':name'    => $form['customer_name'],
                    ':phone'   => $form['contact_number'],
                    ':address' => $form['address'],
                    ':id'      => $userId,
                ]);
            } else {
                $ins = $pdo->prepare(
                    "INSERT INTO users (full_name, email, phone_number, address)
                     VALUES (:name, :email, :phone, :address)"
                );
                $ins->execute([
                    ':name'    => $form['customer_name'],
                    ':email'   => $form['email'],
                    ':phone'   => $form['contact_number'],
                    ':address' => $form['address'],
                ]);
                $userId = (int)$pdo->lastInsertId();
            }

            $baseTotal    = $totalGuests * $packagePrice;
            $extrasTotal  = 0.0;
            $summaryLines = [];

            foreach ($chosenActs as $key => $pax) {
                $a = $ACTIVITIES[$key];
                if ($a[3] === 'pax') {
                    $extrasTotal   += $a[2] * $pax;
                    $summaryLines[] = $a[0] . ' x' . $pax . ' pax (' . peso($a[2] * $pax) . ')';
                } else {
                    $extrasTotal   += $a[2];
                    $summaryLines[] = $a[0] . ' x1 boat (' . peso($a[2]) . ')';
                }
            }
            if ($bundleSel !== '') {
                $b        = $BUNDLES[$bundleSel];
                $regular  = bundleRegular($b[1], $bundlePaxInt);
                $discount = round($regular * $b[2] / 100, 2);
                $extrasTotal   += $regular - $discount;
                $summaryLines[] = $b[0] . ' x' . $bundlePaxInt . ' pax (' . peso($regular - $discount) . ', ' . $b[2] . '% off)';
            }
            if ($form['addon_tent'] === '1') {
                $extrasTotal   += $EXTRAS['addon_tent'][1] * $tentQty;
                $summaryLines[] = $EXTRAS['addon_tent'][0] . ' x' . $tentQty . ' (' . peso($EXTRAS['addon_tent'][1] * $tentQty) . ')';
            }

            foreach ($reqSel as $rName) {
                $r     = $REQUESTS[$rName];
                $rCost = $r[1] === 'pax' ? $r[0] * $totalGuests : (float)$r[0];
                $extrasTotal   += $rCost;
                $summaryLines[] = $rName . ' (' . peso($rCost) . ')';
            }

            if ($kids > 0) {
                $paidKids  = max(0, $kids - $kidFree);
                $kidsCost  = $paidKids * $kidPrice;
                $extrasTotal   += $kidsCost;
                $summaryLines[] = 'Children 3 years old & below x' . $kids . ' ('
                    . min($kids, $kidFree) . ' free'
                    . ($paidKids > 0 ? ', ' . $paidKids . ' x ' . peso($kidPrice) . ' = ' . peso($kidsCost) : '')
                    . ')';
            }

            $totalPrice = $baseTotal + $extrasTotal;

            $specialRequests = $form['special_requests'];
            if ($summaryLines) {
                $specialRequests = trim(
                    $specialRequests . "\n\nPaid extras: " . implode('; ', $summaryLines)
                );
            }

            $insertBooking = $pdo->prepare(
                "INSERT INTO bookings (
                    user_id,
                    check_in_date, check_out_date,
                    num_guests,
                    accommodation_type, meal_plan, dietary_notes,
                    has_pet, pet_details, special_requests,
                    agreed_rules,
                    total_price, status
                 ) VALUES (
                    :user_id,
                    :checkin, :checkout,
                    :num_guests,
                    'Kubo', :meal_plan, :dietary_notes,
                    :has_pet, :pet_details, :special_requests,
                    1,
                    :price, 'Pending'
                 )"
            );
            $insertBooking->execute([
                ':user_id'          => $userId,
                ':checkin'          => $form['check_in_date'],
                ':checkout'         => $checkout,
                ':num_guests'       => $totalGuests,
                ':meal_plan'        => $mealPlanText,
                ':dietary_notes'    => $form['dietary_notes'] !== '' ? $form['dietary_notes'] : null,
                ':has_pet'          => $form['has_pet'],
                ':pet_details'      => $form['pet_details'] !== '' ? $form['pet_details'] : null,
                ':special_requests' => $specialRequests !== '' ? $specialRequests : null,
                ':price'            => $totalPrice,
            ]);

            $bookingId = (int)$pdo->lastInsertId();
            $pdo->commit();

            $_SESSION['booking_token'] = bin2hex(random_bytes(32));
            $_SESSION['booking_success'] = 'Your reservation request was submitted. The camp will review and contact you for confirmation.';
            $_SESSION['booking_reference'] = (string)$bookingId;

            redirectToBooking();

        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Booking submission error: ' . $exception->getMessage());
            $errors[] = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'We could not save your booking. Please try again later.';
        }
    }
}

$checkoutPreview = '';
if (validDate($form['check_in_date'])) {
    $checkoutPreview = (new DateTimeImmutable($form['check_in_date']))
        ->modify('+' . $nights . ' days')->format('l, F j, Y');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Your Stay | <?= e(CAMP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@700;900&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --green: #2D5A27;
            --dark-green: #1F401C;
            --light-green: #EAF0E5;
            --beige: #F7F3E9;
            --cream: #FFFDF8;
            --gold: #C99535;
            --text: #33382F;
            --muted: #6F746B;
            --line: #E2DDCF;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; }
        body { font-family: 'Poppins', Arial, sans-serif; background: var(--beige); color: var(--text); line-height: 1.6; }
        h1, h2, h3 { font-family: 'Lato', 'Poppins', sans-serif; font-weight: 900; color: var(--green); margin: 0; }
        a { color: inherit; text-decoration: none; }

        /* ---------- Top bar & hero ---------- */
        .topbar {
            display: flex; align-items: center; justify-content: space-between; gap: 16px;
            padding: 14px 5%; background: var(--dark-green); color: #fff;
        }
        .brand { display: flex; align-items: center; gap: 12px; }
        .brand img { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; }
        .brand strong { display: block; font-size: 15px; letter-spacing: 1px; line-height: 1.2; }
        .brand small { display: block; color: #F3D99C; font-size: 10px; letter-spacing: 3px; }
        .back { padding: 8px 18px; border: 1px solid rgba(255,255,255,.6); border-radius: 6px; font-size: 13px; font-weight: 600; transition: background .2s, color .2s; }
        .back:hover { background: #fff; color: var(--green); }

        .hero {
            padding: 54px 5% 80px; text-align: center; color: #fff;
            background: linear-gradient(rgba(31,64,28,.78), rgba(31,64,28,.78)), url('images/beach1.jpg') center / cover, var(--green);
        }
        .hero .eyebrow { margin: 0 0 8px; color: #F3D99C; font-size: 12px; font-weight: 600; letter-spacing: 3px; }
        .hero h1 { color: #fff; font-size: clamp(30px, 5vw, 46px); }
        .hero p { margin: 10px 0 0; color: #E8EFE4; font-size: 15px; }
        .hero .badge { display: inline-block; margin-top: 18px; padding: 6px 18px; border-radius: 30px; background: var(--gold); color: #fff; font-size: 13px; font-weight: 600; }

        .wrap { width: min(920px, 92%); margin: -44px auto 60px; }

        /* ---------- Cards ---------- */
        .card { padding: 28px 30px 12px; margin-bottom: 24px; background: var(--cream); border: 1px solid var(--line); border-radius: 14px; box-shadow: 0 6px 22px rgba(31,64,28,.07); }
        .card-title { display: flex; align-items: center; gap: 12px; margin: 0 0 20px; font-size: 20px; }
        .card-title .num { width: 30px; height: 30px; border-radius: 50%; display: grid; place-items: center; background: var(--green); color: #fff; font-family: 'Poppins', sans-serif; font-size: 14px; font-weight: 600; }

        .message { margin-bottom: 22px; padding: 16px 20px; border-radius: 10px; font-size: 14px; }
        .message.success { color: #17683b; background: #e8f8ef; border-left: 4px solid #3cb54a; }
        .message.error { color: #922b21; background: #fff0ef; border-left: 4px solid #c0392b; }
        .message p { margin: 4px 0 0; }
        .message ul { margin: 8px 0 0; padding-left: 20px; }

        /* ---------- Package box ---------- */
        .package { padding: 28px 30px 26px; }
        .package-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline; gap: 8px; margin-bottom: 18px; }
        .package-head h2 { font-size: 22px; }
        .price-tag { font-family: 'Lato', sans-serif; font-weight: 900; font-size: 22px; color: var(--gold); }
        .price-tag small { font-family: 'Poppins', sans-serif; font-weight: 400; font-size: 13px; color: var(--muted); }
        .inc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .inc-box { padding: 16px 18px; border-radius: 10px; background: var(--light-green); }
        .inc-box.paid { background: #FBF3E2; }
        .inc-box h3 { margin-bottom: 10px; font-size: 14px; letter-spacing: .5px; text-transform: uppercase; color: var(--dark-green); }
        .chips { display: flex; flex-wrap: wrap; gap: 8px; }
        .chip { padding: 4px 12px; border-radius: 20px; background: #fff; border: 1px solid #cfdcc8; font-size: 13px; }
        .inc-box.paid .chip { border-color: #ecd9a8; }
        .inc-note { margin: 12px 0 0; color: var(--muted); font-size: 12px; }

        /* ---------- Fields ---------- */
        .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 20px; }
        .form-group { min-width: 0; margin-bottom: 18px; }
        .full-width { grid-column: 1 / -1; }
        label.field { display: block; margin: 0 0 6px 2px; font-size: 13px; font-weight: 600; color: var(--green); }
        input[type=text], input[type=tel], input[type=email], input[type=date], input[type=number], select, textarea {
            width: 100%; min-width: 0; padding: 12px 16px;
            border: 1px solid var(--line); border-radius: 8px; background: #fff;
            font: inherit; font-size: 14px; color: var(--text);
            transition: border-color .2s, box-shadow .2s;
        }
        select { cursor: pointer; }
        textarea { min-height: 96px; resize: vertical; }
        input[readonly] { background: #f3f1ea; color: #666; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--green); box-shadow: 0 0 0 3px rgba(45,90,39,.14); }
        .hint { display: block; margin: 5px 0 0 2px; color: var(--muted); font-size: 12px; }

        .radio-group { display: flex; gap: 12px; flex-wrap: wrap; }
        .radio-group label { display: inline-flex; align-items: center; gap: 8px; padding: 9px 20px; border: 1px solid var(--line); border-radius: 8px; background: #fff; font-size: 14px; cursor: pointer; }
        .radio-group input, .check-row input, .act input[type=checkbox] { accent-color: var(--green); }

        /* ---------- Paid activities ---------- */
        .act-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; margin-bottom: 8px; }
        .act { padding: 14px 16px; border: 1px solid var(--line); border-radius: 10px; background: #fff; transition: border-color .2s, background .2s; }
        .act:has(input[type=checkbox]:checked) { border-color: var(--green); background: var(--light-green); }
        .act-top { display: flex; align-items: flex-start; gap: 10px; cursor: pointer; }
        .act-top input { width: 18px; height: 18px; margin-top: 4px; flex-shrink: 0; }
        .act-name { flex: 1; font-weight: 600; font-size: 14px; color: var(--green); }
        .act-name small { display: block; margin-top: 2px; color: var(--muted); font-weight: 400; font-size: 12px; line-height: 1.4; }
        .act-price { white-space: nowrap; font-weight: 600; font-size: 13px; color: var(--gold); text-align: right; }
        .act-price small { display: block; color: var(--muted); font-weight: 400; font-size: 11px; }
        .act-pax { display: none; align-items: center; gap: 10px; margin-top: 10px; padding-left: 28px; font-size: 13px; }
        .act:has(input[type=checkbox]:checked) .act-pax { display: flex; }
        .act-pax input { width: 80px; padding: 6px 10px; }

        .bundles { margin-bottom: 20px; }
        .bundles h3, .sub-title { font-size: 16px; margin-bottom: 6px; }
        .sub-title { margin-top: 6px; margin-bottom: 12px; }
        .bundle { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; padding: 12px 16px; border: 1px solid var(--line); border-radius: 10px; background: #fff; cursor: pointer; transition: border-color .2s, background .2s; }
        .bundle:has(input:checked) { border-color: var(--gold); background: #FBF3E2; }
        .bundle input { accent-color: var(--green); width: 18px; height: 18px; flex-shrink: 0; }
        .bundle .b-body { flex: 1; font-size: 14px; }
        .bundle .b-body small { display: block; color: var(--muted); font-size: 12px; }
        .bundle .save { margin-left: 8px; padding: 2px 10px; border-radius: 20px; background: var(--gold); color: #fff; font-style: normal; font-size: 11px; font-weight: 600; }
        .bundle .b-price { text-align: right; font-size: 13px; font-weight: 600; color: var(--gold); white-space: nowrap; }
        .bundle .b-price s { display: block; color: var(--muted); font-weight: 400; font-size: 11px; }
        .bundle-pax { display: none; align-items: center; flex-wrap: wrap; gap: 10px; margin: 4px 0 6px; font-size: 13px; }
        .bundle-pax input { width: 80px; padding: 6px 10px; }
        .act.off { opacity: .45; pointer-events: none; }
        .tent-row { flex-wrap: wrap; align-items: center; }
        .tent-row > label { flex: 1; }
        .tent-qty { display: none; align-items: center; gap: 8px; font-size: 13px; }
        .tent-row .tent-qty input { width: 72px; height: auto; margin: 0; padding: 6px 10px; }
        .req-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .req { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border: 1px solid var(--line); border-radius: 8px; background: #fff; font-size: 14px; cursor: pointer; }
        .req:has(input:checked) { border-color: var(--green); background: var(--light-green); }
        .req input { width: 17px; height: 17px; accent-color: var(--green); }
        .req-price { margin-left: auto; font-size: 12px; font-weight: 600; color: var(--gold); white-space: nowrap; }
        .guide { margin: 6px 0 18px; padding: 18px 20px; border: 1px solid #ecd9a8; border-radius: 10px; background: #FBF3E2; }
        .guide h3 { margin-bottom: 8px; font-size: 16px; }
        .guide ul { margin: 6px 0 0; padding-left: 18px; font-size: 13px; }
        .guide li { margin-bottom: 4px; }
        .guide-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px 20px; margin-top: 14px; }
        .guide-item strong { font-size: 13px; color: var(--green); }
        .check-row { display: flex; align-items: flex-start; gap: 12px; padding: 12px 16px; margin-bottom: 18px; border: 1px solid var(--line); border-radius: 8px; background: #fff; }
        .check-row input { width: 18px; height: 18px; margin-top: 3px; flex-shrink: 0; }
        .check-row label { font-size: 14px; cursor: pointer; }

        .rules-box { padding: 16px 20px; margin-bottom: 16px; border: 1px solid var(--line); border-radius: 10px; background: #fff; font-size: 13px; }
        .rules-box > strong { display: block; margin-bottom: 8px; font-family: 'Lato', sans-serif; font-weight: 900; color: var(--green); font-size: 15px; }
        .rules-box ul { margin: 0; padding-left: 20px; }
        .rules-box li { margin-bottom: 6px; }
        .agreement-note { padding: 14px 18px; margin-bottom: 14px; border-left: 4px solid var(--gold); border-radius: 8px; background: #FBF3E2; font-size: 13px; }

        /* ---------- Summary & submit ---------- */
        .summary { padding: 26px 30px; margin-bottom: 24px; background: var(--dark-green); color: #fff; border-radius: 14px; }
        .summary h2 { color: #fff; font-size: 20px; margin-bottom: 14px; }
        .sum-row { display: flex; justify-content: space-between; gap: 12px; padding: 7px 0; font-size: 14px; color: #D8E0D3; }
        .sum-row strong { color: #fff; font-weight: 500; }
        .sum-row.total { margin-top: 8px; padding-top: 14px; border-top: 1px solid rgba(255,255,255,.25); color: #fff; font-size: 16px; }
        .sum-row.total strong { font-family: 'Lato', sans-serif; font-size: 26px; font-weight: 900; color: #F3D99C; }
        .sum-note { margin: 10px 0 0; font-size: 12px; color: #B9C6B3; }
        .submit-button { width: 100%; margin-top: 20px; padding: 15px; border: 0; border-radius: 8px; background: var(--gold); color: #fff; font: inherit; font-size: 16px; font-weight: 600; cursor: pointer; transition: transform .2s, background .2s; }
        .submit-button:hover { background: #b3852d; transform: translateY(-2px); }
        .submit-button:disabled { background: #9aa095; cursor: not-allowed; transform: none; }
        .booking-note { margin: 14px 0 0; color: var(--muted); font-size: 13px; text-align: center; }

        @media (max-width: 720px) {
            .inc-grid, .form-grid, .act-grid, .guide-grid, .req-grid { grid-template-columns: 1fr; }
            .card, .package, .summary { padding-left: 18px; padding-right: 18px; }
            .brand strong { font-size: 13px; }
        }
    
        /* Availability calendar sa loob ng form */
        .form-cal { background: #fff; border: 1px solid var(--line, #e3e0d5); border-radius: 16px; padding: clamp(14px, 3vw, 24px); margin: 4px 0 18px; }
        .form-cal-head { display: grid; grid-template-columns: 42px 1fr 42px; align-items: center; gap: 8px; margin-bottom: 14px; }
        .form-cal-head strong { text-align: center; font-size: clamp(17px, 3vw, 22px); color: var(--green); }
        .form-cal-nav { width: 42px; height: 42px; border: 0; border-radius: 50%; background: #eef4ec; color: var(--green); font-size: 24px; line-height: 1; cursor: pointer; }
        .form-cal-nav:hover:not(:disabled) { background: #dcebd7; }
        .form-cal-nav:disabled { opacity: .3; cursor: default; }
        .form-cal-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: clamp(3px, 1vw, 8px); }
        .form-cal-dow { text-align: center; font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #7a8a76; padding: 4px 0 8px; }
        .fc-day { display: flex; flex-direction: column; align-items: center; justify-content: center; aspect-ratio: 1 / .85; border: 0; border-radius: 12px; font: 600 clamp(14px, 2.4vw, 18px) 'Poppins', sans-serif; padding: 0; }
        .fc-day small { font-size: 10px; font-weight: 600; letter-spacing: .03em; text-transform: uppercase; }
        .fc-day.empty { background: none; }
        .fc-day.past { background: #f6f6f3; color: #c4c4be; font-weight: 500; }
        .fc-day.available { background: #eaf6e7; color: #1f401c; cursor: pointer; }
        .fc-day.available:hover { box-shadow: 0 4px 12px rgba(45,90,39,.25); transform: translateY(-1px); }
        .fc-day.pending { background: #ffe3a3; color: #6d4700; }
        .fc-day.booked { background: #e45b4f; color: #fff; }
        .fc-day.short { background: repeating-linear-gradient(45deg, #f3f3ef, #f3f3ef 6px, #e9e9e3 6px, #e9e9e3 12px); color: #a5a59d; }
        .fc-day.selected { background: var(--green); color: #fff; }
        .fc-day.out { background: var(--green); color: #fff; }
        .fc-day.today { box-shadow: 0 0 0 2px #fff, 0 0 0 4px var(--green); }
        .form-cal-legend { display: flex; flex-wrap: wrap; gap: 8px 14px; justify-content: center; margin: 14px 0 0; padding: 0; list-style: none; font-size: 12px; color: #4a5a46; }
        .form-cal-legend li { display: flex; align-items: center; gap: 6px; }
        .form-cal-legend i { width: 14px; height: 14px; border-radius: 4px; display: inline-block; }
        .form-cal-msg { text-align: center; font-size: 13px; color: #4a5a46; margin: 12px 0 0; }
        .form-cal-msg.error { color: #a33; }
        @media (max-width: 560px) { .fc-day small { display: none; } .fc-day { aspect-ratio: 1 / 1; } }

        /* Calendar sa gilid ng form (desktop); nasa taas ng form sa phone */
        .cal-side { margin-bottom: 24px; }
        .cal-side-title { margin: 0 0 10px; font-size: 18px; color: var(--green); }
        .cal-side .form-cal { margin: 0 0 8px; }
        @media (min-width: 1100px) {
            .wrap { width: min(1280px, 94%); }
            form.has-side { display: grid; grid-template-columns: minmax(0, 1fr) 400px; column-gap: 28px; align-items: start; }
            form.has-side > * { grid-column: 1; }
            form.has-side > .cal-side { grid-column: 2; grid-row: 1 / span 12; position: sticky; top: 20px; margin-bottom: 0; }
            .cal-side .fc-day small { display: none; }
            .cal-side .fc-day { aspect-ratio: 1 / 1; }
            .cal-side .form-cal-legend { justify-content: flex-start; }
        }
    </style>
</head>
<body>

<header class="topbar">
    <a href="index.html" class="brand">
        <img src="images/logo.jpg" alt="<?= e(CAMP_NAME) ?>">
        <span><strong>RGN MASINLO</strong><small>BEACH CAMP</small></span>
    </a>
    <a href="index.html" class="back">← Back to Home</a>
</header>

<section class="hero">
    <p class="eyebrow">RESERVATION</p>
    <h1>Book Your Stay</h1>
    <p><?= e(CAMP_NAME) ?> · <?= e(CAMP_LOCATION) ?></p>
    <span class="badge"><?= $nights + 1 ?> Days / <?= $nights ?> Nights · No Online Payment</span>
</section>

<main class="wrap">

    <?php if ($successMessage !== ''): ?>
        <div class="message success" role="status">
            <strong>Reservation Submitted!</strong>
            <p><?= e($successMessage) ?></p>
            <?php if ($bookingReference !== ''): ?>
                <p>Booking reference: <strong>#<?= e($bookingReference) ?></strong></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="message error" role="alert">
            <strong>Please check the following:</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="card package">
        <div class="package-head">
            <h2><?= $nights + 1 ?> Days / <?= $nights ?> Nights Package</h2>
            <span class="price-tag"><?= e(peso($packagePrice)) ?> <small>per guest · <?= $minGuests ?>–<?= $capacity ?> guests</small></span>
        </div>
        <div class="inc-grid">
            <div class="inc-box">
                <h3>Included in the package</h3>
                <div class="chips">
                    <?php foreach (INCLUDED_MEALS as $m): ?><span class="chip">🍽 <?= e($m) ?></span><?php endforeach; ?>
                    <?php foreach (INCLUDED_ACTIVITIES as $act): ?><span class="chip"><?= e($act) ?></span><?php endforeach; ?>
                </div>
                <p class="inc-note">Meals and the activities above are already covered by the package price.</p>
            </div>
            <div class="inc-box paid">
                <h3>Water activities (extra charge)</h3>
                <div class="chips">
                    <?php foreach ($ACTIVITIES as $a): ?><span class="chip"><?= e($a[0]) ?></span><?php endforeach; ?>
                </div>
                <p class="inc-note">Not part of the package. You can add them in step 3.</p>
            </div>
        </div>
    </section>

    <form action="booking.php" method="POST" novalidate class="has-side">
        <input type="hidden" name="booking_token" value="<?= e($_SESSION['booking_token']) ?>">

        <aside class="cal-side">
            <h2 class="cal-side-title">Availability Calendar</h2>
            <div class="form-cal" id="form-calendar" aria-live="polite"></div>
            <p class="hint">Click an available date to choose your check-in. Each stay is <?= (int)$nights ?> nights.</p>
        </aside>

        <section class="card">
            <h2 class="card-title"><span class="num">1</span> Primary Contact</h2>
            <div class="form-grid">
                <div class="form-group full-width">
                    <label class="field" for="customer_name">Full Name</label>
                    <input type="text" id="customer_name" name="customer_name" maxlength="100"
                           autocomplete="name" value="<?= e($form['customer_name']) ?>"
                           placeholder="e.g. Juan Dela Cruz" required>
                </div>
                <div class="form-group">
                    <label class="field" for="contact_number">Contact Number</label>
                    <input type="tel" id="contact_number" name="contact_number" maxlength="20"
                           autocomplete="tel" value="<?= e($form['contact_number']) ?>"
                           placeholder="e.g. 09171234567" required>
                </div>
                <div class="form-group">
                    <label class="field" for="email">Email Address</label>
                    <input type="email" id="email" name="email" maxlength="100"
                           autocomplete="email" value="<?= e($form['email']) ?>"
                           placeholder="e.g. juan@email.com" required>
                </div>
                <div class="form-group full-width">
                    <label class="field" for="address">Address (City / Province)</label>
                    <input type="text" id="address" name="address" maxlength="255"
                           autocomplete="address-level2" value="<?= e($form['address']) ?>"
                           placeholder="e.g. Quezon City, Metro Manila" required>
                </div>
            </div>
        </section>

        <section class="card">
            <h2 class="card-title"><span class="num">2</span> Stay Details</h2>
            <div class="form-grid">
                <div class="form-group">
                    <label class="field" for="check_in_date">Check-in Date</label>
                    <input type="date" id="check_in_date" name="check_in_date"
                           min="<?= e($today) ?>" value="<?= e($form['check_in_date']) ?>" required>
                </div>
                <div class="form-group">
                    <label class="field" for="check_out_preview">Check-out Date (Auto)</label>
                    <input type="text" id="check_out_preview" value="<?= e($checkoutPreview) ?>"
                           placeholder="Auto-set after check-in" readonly>
                    <span class="hint">Set automatically, <?= $nights ?> nights after check-in.</span>
                </div>
                <div class="form-group full-width">
                    <label class="field" for="num_guests">Number of Guests</label>
                    <select id="num_guests" name="num_guests" required>
                        <?php for ($i = $minGuests; $i <= $capacity; $i++): ?>
                            <option value="<?= $i ?>" <?= (string)$i === $form['num_guests'] ? 'selected' : '' ?>><?= $i ?></option>
                        <?php endfor; ?>
                    </select>
                    <span class="hint">Minimum <?= $minGuests ?>, maximum <?= $capacity ?> guests.</span>
                </div>
                <div class="form-group full-width">
                    <label class="field" for="free_kids">Children 3 years old &amp; below <span style="font-weight:400;color:var(--muted)">(first <?= $kidFree ?> free, then <?= e(peso($kidPrice)) ?> per child)</span></label>
                    <select id="free_kids" name="free_kids">
                        <?php for ($i = 0; $i <= $maxKids; $i++): ?>
                            <option value="<?= $i ?>" <?= (string)$i === $form['free_kids'] ? 'selected' : '' ?>><?= $i ?></option>
                        <?php endfor; ?>
                    </select>
                    <span class="hint">The first <?= $kidFree ?> kids aged 3 and below are free. Each child beyond <?= $kidFree ?> is <?= e(peso($kidPrice)) ?>. They are not counted in the number of guests above.</span>
                </div>
            </div>
        </section>

        <section class="card">
            <h2 class="card-title"><span class="num">3</span> Water Activities (Optional)</h2>
            <p class="hint" style="margin:-10px 0 16px;">Not included in the package. Check the ones you want. Rates are added to your total.</p>
            <div class="bundles">
                <h3>Save with an Activity Package</h3>
                <p class="hint" style="margin:0 0 10px;">Choose a package to get a discount on the activities inside it.</p>
                <label class="bundle">
                    <input type="radio" name="bundle" value="" <?= $bundleSel === '' ? 'checked' : '' ?>>
                    <span class="b-body"><strong>No package</strong><small>Choose activities one by one below.</small></span>
                </label>
                <?php foreach ($BUNDLES as $bKey => $b): ?>
                    <label class="bundle">
                        <input type="radio" name="bundle" value="<?= e($bKey) ?>" <?= $bundleSel === $bKey ? 'checked' : '' ?>>
                        <span class="b-body">
                            <strong><?= e($b[0]) ?> <em class="save">Save <?= (int)$b[2] ?>%</em></strong>
                            <small><?= e(implode(' + ', array_map(fn($k) => $ACTIVITIES[$k][0], $b[1]))) ?></small>
                        </span>
                        <span class="b-price" data-bundle-price="<?= e($bKey) ?>"></span>
                    </label>
                <?php endforeach; ?>
                <div class="bundle-pax" id="bundlePax">
                    <label for="bundle_pax">Participants</label>
                    <input type="number" id="bundle_pax" name="bundle_pax" min="1" max="<?= $capacity ?>" value="<?= e($bundlePax) ?>">
                    <span class="hint" style="margin:0;">Cannot be more than your number of guests.</span>
                </div>
            </div>

            <h3 class="sub-title">Or choose activities one by one</h3>
            <div class="act-grid">
                <?php foreach ($ACTIVITIES as $key => $a): ?>
                    <div class="act">
                        <label class="act-top">
                            <input type="checkbox" name="act_<?= e($key) ?>" value="1"
                                   data-act="<?= e($key) ?>" <?= $actState[$key]['checked'] ? 'checked' : '' ?>>
                            <span class="act-name"><?= e($a[0]) ?><small><?= e($a[1]) ?></small></span>
                            <span class="act-price"><?= e(peso((float)$a[2])) ?><small><?= $a[3] === 'boat' ? 'per boat' : 'per person' ?></small></span>
                        </label>
                        <?php if ($a[3] === 'pax'): ?>
                            <div class="act-pax">
                                <label for="pax_<?= e($key) ?>">Participants</label>
                                <input type="number" id="pax_<?= e($key) ?>" name="pax_<?= e($key) ?>"
                                       min="1" max="<?= $capacity ?>" value="<?= e($actState[$key]['pax']) ?>">
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <span class="hint" style="margin-bottom:18px;">Sample rates. The camp confirms the final amount and schedule.</span>

            <div class="guide">
                <h3>Activity Requirements &amp; Safety Guidelines</h3>
                <ul class="guide-general">
                    <?php foreach (GENERAL_SAFETY as $g): ?><li><?= e($g) ?></li><?php endforeach; ?>
                </ul>
                <div class="guide-grid">
                    <?php foreach (ACTIVITY_GUIDELINES as $gName => $gList): ?>
                        <div class="guide-item">
                            <strong><?= e($gName) ?></strong>
                            <ul><?php foreach ($gList as $g): ?><li><?= e($g) ?></li><?php endforeach; ?></ul>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="card">
            <h2 class="card-title"><span class="num">4</span> Extras &amp; Requests</h2>
            <div class="form-grid">
                <div class="form-group full-width">
                    <div class="check-row tent-row">
                        <input type="checkbox" id="addon_tent" name="addon_tent" value="1" data-tent
                            <?= $form['addon_tent'] === '1' ? 'checked' : '' ?>>
                        <label for="addon_tent">Extra Tent Rental (+ <?= e(peso($EXTRAS['addon_tent'][1])) ?> each)</label>
                        <span class="tent-qty" id="tentQtyBox">
                            <label for="tent_qty">Quantity</label>
                            <input type="number" id="tent_qty" name="tent_qty" min="1" max="<?= $maxTents ?>" value="<?= e($form['tent_qty']) ?>">
                        </span>
                    </div>
                </div>
                <div class="form-group full-width">
                    <label class="field">Additional Requests <span style="font-weight:400;color:var(--muted)">(subject to availability)</span></label>
                    <div class="req-grid">
                        <?php foreach ($REQUESTS as $reqName => $r): ?>
                            <label class="req">
                                <input type="checkbox" name="req[]" value="<?= e($reqName) ?>" data-req="<?= e($reqName) ?>" <?= in_array($reqName, $reqSel, true) ? 'checked' : '' ?>>
                                <span><?= e($reqName) ?></span>
                                <span class="req-price"><?= e(peso((float)$r[0])) ?> <?= $r[1] === 'pax' ? '/ guest' : 'flat' ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-group full-width">
                    <label class="field" for="dietary_notes">Dietary Restrictions</label>
                    <input type="text" id="dietary_notes" name="dietary_notes" maxlength="255"
                           value="<?= e($form['dietary_notes']) ?>"
                           placeholder="e.g. Halal, vegetarian, allergies (leave blank if none)">
                </div>
                <div class="form-group full-width">
                    <label class="field">Bringing a pet?</label>
                    <div class="radio-group">
                        <label><input type="radio" name="has_pet" value="1" <?= $form['has_pet'] === '1' ? 'checked' : '' ?>> Yes</label>
                        <label><input type="radio" name="has_pet" value="0" <?= $form['has_pet'] === '0' ? 'checked' : '' ?>> No</label>
                    </div>
                </div>
                <div class="form-group full-width">
                    <label class="field" for="pet_details">Pet Type / Breed</label>
                    <input type="text" id="pet_details" name="pet_details" maxlength="255"
                           value="<?= e($form['pet_details']) ?>"
                           placeholder="e.g. Shih Tzu, Labrador (leave blank if none)">
                </div>
                <div class="form-group full-width">
                    <label class="field" for="special_requests">Other Requests / Notes</label>
                    <textarea id="special_requests" name="special_requests" maxlength="2000"
                              placeholder="Anything else we should know? e.g. arrival time, quiet area, special occasion"><?= e($form['special_requests']) ?></textarea>
                </div>
            </div>
        </section>

        <section class="card">
            <h2 class="card-title"><span class="num">5</span> Camp Rules &amp; Agreement</h2>
            <div class="rules-box">
                <strong>Camp Rules</strong>
                <ul>
                    <li><strong>Quiet Hours:</strong> 10:00 PM to 6:00 AM. Please respect other guests.</li>
                    <li><strong>Leave No Trace / CLAYGO:</strong> Clean as you go. Waste segregation is strictly enforced.</li>
                    <li><strong>Safety &amp; Liability:</strong> The camp is not liable for loss of personal items or accidents resulting from guest negligence.</li>
                </ul>
            </div>
            <div class="agreement-note">
                <strong>Before you submit:</strong> Please make sure your check-in date, number of guests,
                and contact details are correct. Once submitted, the camp will review your request and
                contact you for confirmation. Your reservation is <strong>not final</strong> until the camp
                confirms it.
            </div>
            <div class="check-row">
                <input type="checkbox" id="agreed_rules" name="agreed_rules" value="1"
                    <?= isset($_POST['agreed_rules']) ? 'checked' : '' ?> required>
                <label for="agreed_rules">I have read and agree to the camp rules, regulations, and activity safety guidelines.</label>
            </div>
        </section>

        <section class="summary" aria-live="polite">
            <h2>Estimated Total</h2>
            <div class="sum-row"><span>Guests</span><strong id="estGuests">0</strong></div>
            <div class="sum-row"><span>Children 3 &amp; below</span><strong id="estKids">0</strong></div>
            <div class="sum-row"><span>Package (meals &amp; activities included)</span><strong id="estBase">₱0.00</strong></div>
            <div class="sum-row"><span>Water activities &amp; extras</span><strong id="estExtras">₱0.00</strong></div>
            <div class="sum-row total"><span>Total</span><strong id="estTotal">₱0.00</strong></div>
            <p class="sum-note">Estimate only. The camp confirms the final amount.</p>

            <button type="submit" class="submit-button" <?= $packagePrice <= 0 ? 'disabled' : '' ?>>
                <?= $packagePrice <= 0 ? 'Booking Temporarily Unavailable' : 'Submit Reservation Request' ?>
            </button>
        </section>

        <p class="booking-note">
            This is a booking request only. No online payment is required.<br>
            The camp will contact you to confirm your reservation.
        </p>
    </form>
</main>

<script>
const checkin         = document.getElementById('check_in_date');
const checkoutPreview = document.getElementById('check_out_preview');

function updateCheckoutPreview() {
    if (!checkin || !checkoutPreview) return;
    if (!checkin.value) { checkoutPreview.value = ''; return; }

    const parts = checkin.value.split('-').map(Number);
    const d = new Date(parts[0], parts[1] - 1, parts[2]);
    d.setDate(d.getDate() + <?= $nights ?>);

    const days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    checkoutPreview.value = days[d.getDay()] + ', ' + months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
}
if (checkin) {
    checkin.addEventListener('change', updateCheckoutPreview);
    updateCheckoutPreview();
}

/* Live price estimate (display only; the server calculates the real total) */
(function () {
    const PRICE = <?= json_encode($packagePrice) ?>;
    const TENT  = <?= json_encode($EXTRAS['addon_tent'][1]) ?>;
    const KID_PRICE = <?= json_encode($kidPrice) ?>;
    const KID_FREE  = <?= json_encode($kidFree) ?>;
    const REQS  = <?= json_encode(array_map(fn($r) => ['price' => $r[0], 'unit' => $r[1]], $REQUESTS)) ?>;
    const ACTS  = <?= json_encode(array_map(fn($a) => ['price' => $a[2], 'unit' => $a[3]], $ACTIVITIES)) ?>;
    const BUNDLES = <?= json_encode(array_map(fn($b) => ['items' => $b[1], 'disc' => $b[2]], $BUNDLES)) ?>;
    const guestsSel = document.getElementById('num_guests');
    const peso = n => '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function regular(items, pax) {
        return items.reduce((t, k) => t + (ACTS[k].unit === 'pax' ? ACTS[k].price * pax : ACTS[k].price), 0);
    }

    function update() {
        const guests = parseInt(guestsSel.value, 10) || 0;
        let extras = 0;

        // Activity package
        const picked = document.querySelector('input[name=bundle]:checked');
        const bKey = picked ? picked.value : '';
        const bPaxInput = document.getElementById('bundle_pax');
        bPaxInput.max = guests;
        if ((parseInt(bPaxInput.value, 10) || 0) > guests) bPaxInput.value = guests;
        const bPax = Math.min(Math.max(parseInt(bPaxInput.value, 10) || guests, 1), guests);
        document.getElementById('bundlePax').style.display = bKey ? 'flex' : 'none';

        Object.keys(BUNDLES).forEach(function (k) {
            const pax = (k === bKey) ? bPax : guests;
            const reg = regular(BUNDLES[k].items, pax);
            const disc = reg * (1 - BUNDLES[k].disc / 100);
            const el = document.querySelector('[data-bundle-price="' + k + '"]');
            if (el) el.innerHTML = '<s>' + peso(reg) + '</s>' + peso(disc);
        });
        const covered = bKey ? BUNDLES[bKey].items : [];
        if (bKey) extras += regular(BUNDLES[bKey].items, bPax) * (1 - BUNDLES[bKey].disc / 100);

        document.querySelectorAll('[data-act]').forEach(function (cb) {
            const off = covered.indexOf(cb.dataset.act) !== -1;
            if (off) cb.checked = false;
            cb.disabled = off;
            cb.closest('.act').classList.toggle('off', off);
        });

        document.querySelectorAll('[data-act]').forEach(function (cb) {
            const a = ACTS[cb.dataset.act];
            const paxInput = document.getElementById('pax_' + cb.dataset.act);
            if (paxInput) {
                paxInput.max = guests;
                if ((parseInt(paxInput.value, 10) || 0) > guests) paxInput.value = guests;
            }
            if (!cb.checked || !a) return;
            if (a.unit === 'pax') {
                let pax = parseInt(paxInput ? paxInput.value : guests, 10) || 0;
                pax = Math.min(Math.max(pax, 0), guests);
                extras += a.price * pax;
            } else {
                extras += a.price;
            }
        });

        const tent = document.querySelector('[data-tent]');
        const tentQtyInput = document.getElementById('tent_qty');
        document.getElementById('tentQtyBox').style.display = (tent && tent.checked) ? 'inline-flex' : 'none';
        if (tent && tent.checked) {
            let q = parseInt(tentQtyInput.value, 10) || 1;
            q = Math.min(Math.max(q, 1), parseInt(tentQtyInput.max, 10));
            extras += TENT * q;
        }

        document.querySelectorAll('[data-req]').forEach(function (cb) {
            const r = REQS[cb.dataset.req];
            if (cb.checked && r) extras += r.unit === 'pax' ? r.price * guests : r.price;
        });

        const base = guests * PRICE;
        document.getElementById('estGuests').textContent = guests;
        const kidsSel = document.getElementById('free_kids');
        const kids = kidsSel ? parseInt(kidsSel.value, 10) || 0 : 0;
        const paidKids = Math.max(0, kids - KID_FREE);
        extras += paidKids * KID_PRICE;
        document.getElementById('estKids').textContent = kids + (paidKids ? ' (' + paidKids + ' x ' + peso(KID_PRICE) + ')' : (kids ? ' (FREE)' : ''));
        document.getElementById('estBase').textContent   = peso(base);
        document.getElementById('estExtras').textContent = peso(extras);
        document.getElementById('estTotal').textContent  = peso(base + extras);
    }

    if (guestsSel) guestsSel.addEventListener('change', update);
    const kidsEl = document.getElementById('free_kids');
    if (kidsEl) kidsEl.addEventListener('change', update);
    document.querySelectorAll('[data-act], [data-tent], [data-req], .act-pax input, input[name=bundle], #bundle_pax, #tent_qty').forEach(el => {
        el.addEventListener('change', update);
        el.addEventListener('input', update);
    });
    update();
})();
</script>
<script>
(function () {
    var root = document.getElementById('form-calendar');
    var input = document.getElementById('check_in_date');
    if (!root || !input) return;

    var NIGHTS = <?= (int)$nights ?>;
    var MAX_AHEAD = 11;
    var MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    var DOW = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

    var now = new Date();
    var view = new Date(now.getFullYear(), now.getMonth(), 1);
    var days = {}, failed = false, loaded = false;

    function pad(n) { return String(n).padStart(2, '0'); }
    function keyOf(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }
    function parse(s) { var p = String(s).split('-'); return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2])); }
    function fmt(dt) { return dt.getUTCFullYear() + '-' + pad(dt.getUTCMonth() + 1) + '-' + pad(dt.getUTCDate()); }
    function addDays(key, n) { var d = parse(key); d.setUTCDate(d.getUTCDate() + n); return fmt(d); }
    function monthIndex(d) { return d.getFullYear() * 12 + d.getMonth(); }

    function build(list) {
        var map = {};
        list.forEach(function (b) {
            var d = parse(b.check_in), end = parse(b.check_out);
            if (isNaN(d) || isNaN(end)) return;
            while (d <= end) {
                var k = fmt(d);
                if (map[k] !== 'Confirmed') map[k] = b.status;
                d.setUTCDate(d.getUTCDate() + 1);
            }
        });
        return map;
    }

    /* Puwede lang ang check-in kung libre ang lahat ng gabi ng stay. */
    function stayFree(key) {
        for (var i = 0; i <= NIGHTS; i++) { if (days[addDays(key, i)]) return false; }
        return true;
    }

    function render() {
        var y = view.getFullYear(), m = view.getMonth();
        var first = new Date(y, m, 1).getDay();
        var total = new Date(y, m + 1, 0).getDate();
        var todayKey = keyOf(now.getFullYear(), now.getMonth(), now.getDate());
        var diff = monthIndex(view) - monthIndex(now);
        var sel = /^\d{4}-\d{2}-\d{2}$/.test(input.value) ? input.value : '';
        var selOut = sel ? addDays(sel, NIGHTS) : '';

        var h = '<div class="form-cal-head">' +
            '<button type="button" class="form-cal-nav" data-dir="-1" aria-label="Previous month"' + (diff <= 0 ? ' disabled' : '') + '>&#8249;</button>' +
            '<strong>' + MONTHS[m] + ' ' + y + '</strong>' +
            '<button type="button" class="form-cal-nav" data-dir="1" aria-label="Next month"' + (diff >= MAX_AHEAD ? ' disabled' : '') + '>&#8250;</button></div>';

        h += '<div class="form-cal-grid">';
        DOW.forEach(function (d) { h += '<span class="form-cal-dow">' + d + '</span>'; });
        for (var i = 0; i < first; i++) h += '<span class="fc-day empty"></span>';

        for (var d = 1; d <= total; d++) {
            var k = keyOf(y, m, d);
            var cls, tag = '', clickable = false, title = '';
            if (k < todayKey) { cls = 'past'; title = 'Past'; }
            else if (days[k] === 'Confirmed') { cls = 'booked'; tag = 'Booked'; title = 'Booked'; }
            else if (days[k] === 'Pending') { cls = 'pending'; tag = 'Reserved'; title = 'Reserved (pending)'; }
            else if (!stayFree(k)) { cls = 'short'; title = 'Not enough free days for this stay'; }
            else { cls = 'available'; clickable = true; title = 'Available - click to choose'; }

            if (sel && k >= sel && k < selOut) { cls = 'selected'; tag = k === sel ? 'Check-in' : ''; clickable = false; title = 'Your stay'; }
            else if (sel && k === selOut) { cls = 'out'; tag = 'Check-out'; clickable = false; title = 'Check-out day'; }
            if (k === todayKey) cls += ' today';

            var inner = '<span>' + d + '</span>' + (tag ? '<small>' + tag + '</small>' : '');
            if (clickable) h += '<button type="button" class="fc-day ' + cls + '" data-date="' + k + '" title="' + title + '">' + inner + '</button>';
            else h += '<span class="fc-day ' + cls + '" title="' + title + '">' + inner + '</span>';
        }
        h += '</div>';

        h += '<ul class="form-cal-legend">' +
            '<li><i style="background:#eaf6e7;border:1px solid #b9dcb2"></i>Available</li>' +
            '<li><i style="background:#ffe3a3"></i>Reserved (pending)</li>' +
            '<li><i style="background:#e45b4f"></i>Booked</li>' +
            '<li><i style="background:#e9e9e3"></i>Too short for ' + (NIGHTS + 1) + ' days</li>' +
            '<li><i style="background:#2D5A27"></i>Your stay</li></ul>';

        if (failed) h += '<p class="form-cal-msg error">Availability could not be loaded. You can still pick a date in the field above.</p>';
        else if (!loaded) h += '<p class="form-cal-msg">Loading availability...</p>';

        root.innerHTML = h;
    }

    function load() {
        fetch('availability.php?t=' + Date.now(), { cache: 'no-store' })
            .then(function (r) { if (!r.ok) throw new Error('bad'); return r.json(); })
            .then(function (data) { days = build(data.bookings || []); failed = false; loaded = true; render(); })
            .catch(function () { failed = true; loaded = true; render(); });
    }

    root.addEventListener('click', function (e) {
        var nav = e.target.closest('.form-cal-nav');
        if (nav && !nav.disabled) {
            view = new Date(view.getFullYear(), view.getMonth() + parseInt(nav.getAttribute('data-dir'), 10), 1);
            render();
            return;
        }
        var day = e.target.closest('[data-date]');
        if (day) {
            input.value = day.getAttribute('data-date');
            input.dispatchEvent(new Event('change', { bubbles: true }));
            render();
        }
    });

    input.addEventListener('change', function () {
        if (/^\d{4}-\d{2}-\d{2}$/.test(input.value)) {
            var p = input.value.split('-');
            view = new Date(+p[0], +p[1] - 1, 1);
        }
        render();
    });

    document.addEventListener('visibilitychange', function () { if (!document.hidden) load(); });

    if (/^\d{4}-\d{2}-\d{2}$/.test(input.value)) {
        var p = input.value.split('-');
        view = new Date(+p[0], +p[1] - 1, 1);
    }
    render();
    load();
    setInterval(load, 30000);
})();
</script>
</body>
</html>