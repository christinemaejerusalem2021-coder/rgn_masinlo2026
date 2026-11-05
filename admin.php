<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once 'config.php';

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): bool
{
    return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && is_string($_POST['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

function redirectTo(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function authenticateAdmin($pdo, string $login, string $password): ?array
{
    $stmt = $pdo->prepare(
        "SELECT admin_id, username, email, full_name,
                password_hash, role, status
         FROM admin_users
         WHERE username = :username
            OR email = :email
         LIMIT 1"
    );
    $stmt->execute(['username' => $login, 'email' => $login]);
    $admin = $stmt->fetch();

    if (
        $admin
        && $admin['status'] === 'active'
        && in_array($admin['role'], ['admin', 'staff', 'owner'], true)
        && password_verify($password, $admin['password_hash'])
    ) {
        return $admin;
    }
    return null;
}

function startAdminSession(array $admin): void
{
    $known = $_SESSION['known_accounts'] ?? [];

    session_regenerate_id(true);
    $_SESSION['admin_id']       = (int)$admin['admin_id'];
    $_SESSION['admin_username'] = $admin['username'];
    $_SESSION['admin_name']     = $admin['full_name'];
    $_SESSION['admin_role']     = $admin['role'];

    // Remember who has signed in on this browser session (no passwords stored).
    $known[(int)$admin['admin_id']] = [
        'username' => $admin['username'],
        'name'     => $admin['full_name'],
        'role'     => $admin['role'],
    ];
    $_SESSION['known_accounts'] = $known;

    unset($_SESSION['csrf_token']);
    csrfToken();
}

function assignableRoles(string $role): array
{
    return match ($role) {
        'owner' => ['owner', 'admin', 'staff'],
        'admin' => ['admin', 'staff'],
        default => [],
    };
}

function validYmd(string $date): bool
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

function csvSafe($value): string
{
    $v = (string)($value ?? '');
    if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) {
        return "'" . $v;
    }
    return $v;
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
    redirectTo('admin.php');
}

$error = '';
$success = '';
$dlgErr = ['add' => '', 'switch' => ''];
$reopen = '';
$addForm = ['full_name' => '', 'username' => '', 'email' => '', 'role' => 'staff'];

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'login'
) {
    if (!verifyCsrf()) {
        $error = 'Invalid request. Please refresh the page.';
    } else {
        $login = trim((string)($_POST['login'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($login === '' || $password === '') {
            $error = 'Enter your username/email and password.';
        } else {
            try {
                $admin = authenticateAdmin($pdo, $login, $password);

                if ($admin) {
                    startAdminSession($admin);
                    redirectTo('admin.php');
                }

                $error = 'Incorrect username/email or password, or the account is inactive.';
            } catch (Throwable $ex) {
                error_log('Admin login error: ' . $ex->getMessage());
                $error = 'Login failed. Please check the database configuration.';
            }
        }
    }
}

if (empty($_SESSION['admin_id'])):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | <?= e(CAMP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@700;900&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh;
            display: flex; justify-content: center; align-items: center;
            padding: 20px; font-family: 'Poppins', Arial, sans-serif;
            background: #222; color: #1d1d1d;
        }
        .login-card {
            width: 100%; max-width: 420px; overflow: hidden;
            background: #fff; border-radius: 22px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, .35);
        }
        .login-brand { padding: 26px 20px 18px; background: #222; text-align: center; }
        .login-brand img { width: 190px; max-width: 80%; margin: 0 auto; display: block; }
        .login-body { padding: 30px 32px 34px; }
        h1 { margin: 0 0 4px; font-family: 'Lato', sans-serif; font-weight: 900; font-size: 26px; color: #0b3a6b; }
        .subtitle { margin: 0 0 22px; color: #777; font-size: 14px; }
        label { display: block; margin: 16px 0 7px; font-size: 13px; font-weight: 600; color: #0b3a6b; }
        input {
            width: 100%; padding: 13px 18px; border: 1px solid #e0e4ea;
            border-radius: 40px; font: inherit; font-size: 14px; background: #fff;
        }
        input:focus { outline: none; border-color: #1ea5e6; box-shadow: 0 0 0 3px rgba(30, 165, 230, .15); }
        button {
            width: 100%; margin-top: 24px; padding: 14px; border: 0;
            border-radius: 40px; color: #fff; font: inherit; font-size: 15px; font-weight: 600;
            background: linear-gradient(90deg, #1ea5e6, #4a56c9); cursor: pointer;
            transition: transform .2s, box-shadow .2s;
        }
        button:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(74, 86, 201, .3); }
        .error {
            padding: 12px 14px; margin-bottom: 8px; border-radius: 12px;
            background: #ffeded; color: #a40000; font-size: 13px;
        }
    </style>
</head>
<body>
    <main class="login-card">
        <div class="login-brand">
            <img src="images/logo.jpg" alt="<?= e(CAMP_NAME) ?>">
        </div>
        <div class="login-body">
            <h1>Admin Login</h1>
            <p class="subtitle">Beach Camp Management System</p>

            <?php if ($error !== ''): ?>
                <div class="error"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="admin.php">
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

                <label for="login">Username or Email</label>
                <input type="text" id="login" name="login" autocomplete="username" required>

                <label for="password">Password</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required>

                <button type="submit">Log In</button>
            </form>
        </div>
    </main>
</body>
</html>
<?php
exit;
endif;

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'add_account'
) {
    $reopen = 'dlg-add-account';
    foreach ($addForm as $k => $_) {
        $addForm[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['password_confirm'] ?? '');
    $allowedRoles = assignableRoles((string)($_SESSION['admin_role'] ?? ''));

    if (!verifyCsrf()) {
        $dlgErr['add'] = 'Invalid request. Refresh the page and try again.';
    } elseif (!$allowedRoles) {
        $dlgErr['add'] = 'Your role cannot add accounts.';
    } elseif ($addForm['full_name'] === '' || mb_strlen($addForm['full_name']) > 100) {
        $dlgErr['add'] = 'Enter a full name (up to 100 characters).';
    } elseif (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $addForm['username'])) {
        $dlgErr['add'] = 'Username must be 3-50 characters: letters, numbers, dot, dash or underscore.';
    } elseif (!filter_var($addForm['email'], FILTER_VALIDATE_EMAIL) || strlen($addForm['email']) > 100) {
        $dlgErr['add'] = 'Enter a valid email address.';
    } elseif (!in_array($addForm['role'], $allowedRoles, true)) {
        $dlgErr['add'] = 'Choose a role you are allowed to assign.';
    } elseif (strlen($password) < 8) {
        $dlgErr['add'] = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $dlgErr['add'] = 'Passwords do not match.';
    } else {
        try {
            $dup = $pdo->prepare(
                "SELECT 1 FROM admin_users WHERE username = :u OR email = :e LIMIT 1"
            );
            $dup->execute([':u' => $addForm['username'], ':e' => $addForm['email']]);

            if ($dup->fetch()) {
                $dlgErr['add'] = 'That username or email is already in use.';
            } else {
                $ins = $pdo->prepare(
                    "INSERT INTO admin_users (username, email, full_name, password_hash, role, status)
                     VALUES (:username, :email, :full_name, :hash, :role, 'active')"
                );
                $ins->execute([
                    ':username'  => $addForm['username'],
                    ':email'     => $addForm['email'],
                    ':full_name' => $addForm['full_name'],
                    ':hash'      => password_hash($password, PASSWORD_DEFAULT),
                    ':role'      => $addForm['role'],
                ]);
                redirectTo('admin.php?view=settings&account_added=1');
            }
        } catch (Throwable $ex) {
            error_log('Add account error: ' . $ex->getMessage());
            $dlgErr['add'] = 'Unable to create the account. Check the admin_users table.';
        }
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'switch_account'
) {
    $reopen = 'dlg-switch-account';
    $swLogin = trim((string)($_POST['login'] ?? ''));
    $swPass  = (string)($_POST['password'] ?? '');

    if (!verifyCsrf()) {
        $dlgErr['switch'] = 'Invalid request. Refresh the page and try again.';
    } elseif ($swLogin === '' || $swPass === '') {
        $dlgErr['switch'] = 'Enter the username/email and password of the account.';
    } else {
        try {
            $target = authenticateAdmin($pdo, $swLogin, $swPass);
            if (!$target) {
                $dlgErr['switch'] = 'Incorrect username/email or password, or the account is inactive.';
            } elseif ((int)$target['admin_id'] === (int)$_SESSION['admin_id']) {
                $dlgErr['switch'] = 'You are already signed in as that account.';
            } else {
                startAdminSession($target);
                redirectTo('admin.php?switched=1');
            }
        } catch (Throwable $ex) {
            error_log('Switch account error: ' . $ex->getMessage());
            $dlgErr['switch'] = 'Unable to switch accounts. Check the database configuration.';
        }
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'update_booking'
) {
    if (!verifyCsrf()) {
        $error = 'Invalid request. Refresh the page and try again.';
    } else {
        $bookingId = filter_var($_POST['booking_id'] ?? null, FILTER_VALIDATE_INT);
        $newStatus = (string)($_POST['status'] ?? '');
        $notes     = trim((string)($_POST['notes'] ?? ''));

        $allowedStatuses = ['Pending', 'Confirmed', 'Cancelled', 'Completed'];

        if (!$bookingId || !in_array($newStatus, $allowedStatuses, true)) {
            $error = 'Invalid booking or status.';
        } else {
            try {
                $pdo->beginTransaction();

                $oldStmt = $pdo->prepare(
                    "SELECT status FROM bookings WHERE booking_id = :id FOR UPDATE"
                );
                $oldStmt->execute([':id' => $bookingId]);
                $oldRow = $oldStmt->fetch();
                $oldStatus = $oldRow ? $oldRow['status'] : null;

                $stmt = $pdo->prepare(
                    "UPDATE bookings SET status = :status WHERE booking_id = :booking_id"
                );
                $stmt->execute([
                    ':status'     => $newStatus,
                    ':booking_id' => $bookingId,
                ]);

                if ($oldStatus !== $newStatus) {
                    $hist = $pdo->prepare(
                        "INSERT INTO booking_status_history
                            (booking_id, old_status, new_status, changed_by_admin_id, notes)
                         VALUES
                            (:booking_id, :old_status, :new_status, :admin_id, :notes)"
                    );
                    $hist->execute([
                        ':booking_id' => $bookingId,
                        ':old_status' => $oldStatus,
                        ':new_status' => $newStatus,
                        ':admin_id'   => $_SESSION['admin_id'] ?? null,
                        ':notes'      => $notes !== '' ? $notes : null,
                    ]);
                }

                $pdo->commit();
                $retMonth = (string)($_POST['return_month'] ?? '');
                if (
                    ($_POST['return_view'] ?? '') === 'calendar'
                    && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $retMonth)
                ) {
                    redirectTo('admin.php?view=calendar&month=' . $retMonth . '&updated=1');
                }
                redirectTo('admin.php?view=reservations&updated=1');

            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Booking update error: ' . $ex->getMessage());
                $error = 'Unable to update the booking. Check the database.';
            }
        }
    }
}

if (isset($_GET['updated'])) {
    $success = 'Booking status updated successfully.';
}
if (isset($_GET['account_added'])) {
    $success = 'Account created. They can now sign in with their username or email.';
}
if (isset($_GET['switched'])) {
    $success = 'Switched to ' . ($_SESSION['admin_name'] ?? 'the new account') . '.';
}

$stats = [
    'bookings'  => 0,
    'pending'   => 0,
    'confirmed' => 0,
    'cancelled' => 0,
    'completed' => 0,
    'users'     => 0,
];

$bookings = [];
$recentActivity = [];
$dashboardError = '';

try {
    $stmt = $pdo->query(
        "SELECT
            COUNT(*) AS total_bookings,
            COALESCE(SUM(status = 'Pending'), 0)   AS pending_bookings,
            COALESCE(SUM(status = 'Confirmed'), 0) AS confirmed_bookings,
            COALESCE(SUM(status = 'Cancelled'), 0) AS cancelled_bookings,
            COALESCE(SUM(status = 'Completed'), 0) AS completed_bookings
         FROM bookings"
    );
    $r = $stmt->fetch();
    $stats['bookings']  = (int)$r['total_bookings'];
    $stats['pending']   = (int)$r['pending_bookings'];
    $stats['confirmed'] = (int)$r['confirmed_bookings'];
    $stats['cancelled'] = (int)$r['cancelled_bookings'];
    $stats['completed'] = (int)$r['completed_bookings'];

    $stats['users'] = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

    $sql = "
        SELECT
            b.booking_id, b.user_id,
            b.check_in_date, b.check_in_time,
            b.check_out_date, b.check_out_time,
            b.num_guests, b.num_adults, b.num_kids, b.kids_ages,
            b.total_price, b.status, b.created_at,
            b.accommodation_type, b.meal_plan, b.dietary_notes,
            b.has_pet, b.pet_details, b.special_requests,
            b.guest_signature, b.signed_at,
            u.full_name, u.email, u.phone_number, u.address
        FROM bookings b
        LEFT JOIN users u ON u.user_id = b.user_id
        ORDER BY b.created_at DESC, b.booking_id DESC
    ";
    $bookings = $pdo->query($sql)->fetchAll();

    $recentActivity = $pdo->query(
        "SELECT h.*, b.booking_id, u.full_name
         FROM booking_status_history h
         JOIN bookings b ON b.booking_id = h.booking_id
         LEFT JOIN users u ON u.user_id = b.user_id
         ORDER BY h.changed_at DESC
         LIMIT 10"
    )->fetchAll();

} catch (Throwable $ex) {
    error_log('Dashboard database error: ' . $ex->getMessage());
    $dashboardError = 'Dashboard database error. Check that the required tables and columns exist.';
}

/* ---------- Reports: date range + CSV export ---------- */

$repFrom = (string)($_GET['from'] ?? '');
$repTo   = (string)($_GET['to'] ?? '');
if (!validYmd($repFrom)) $repFrom = date('Y-01-01');
if (!validYmd($repTo))   $repTo   = date('Y-12-31');
if ($repFrom > $repTo) {
    [$repFrom, $repTo] = [$repTo, $repFrom];
}

$repBookings = array_values(array_filter(
    $bookings,
    fn($b) => $b['check_in_date'] >= $repFrom && $b['check_in_date'] <= $repTo
));

if (
    ($_GET['view'] ?? '') === 'reports'
    && ($_GET['export'] ?? '') === 'csv'
    && $dashboardError === ''
) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="reservations-' . $repFrom . '-to-' . $repTo . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Ref #', 'Guest', 'Email', 'Phone', 'Check-in', 'Check-out',
        'Guests', 'Adults', 'Kids', 'Accommodation', 'Meal plan',
        'Total price', 'Status', 'Submitted',
    ], ',', '"', '\\');
    foreach ($repBookings as $b) {
        fputcsv($out, [
            $b['booking_id'],
            csvSafe($b['full_name']),
            csvSafe($b['email']),
            csvSafe($b['phone_number']),
            $b['check_in_date'],
            $b['check_out_date'],
            (int)$b['num_guests'],
            (int)$b['num_adults'],
            (int)$b['num_kids'],
            csvSafe($b['accommodation_type']),
            csvSafe($b['meal_plan']),
            number_format((float)$b['total_price'], 2, '.', ''),
            $b['status'],
            $b['created_at'],
        ], ',', '"', '\\');
    }
    fclose($out);
    exit;
}

/* ---------- Staff accounts (owner/admin only) ---------- */

$adminAccounts = [];
if (assignableRoles((string)($_SESSION['admin_role'] ?? '')) !== []) {
    try {
        $adminAccounts = $pdo->query(
            "SELECT admin_id, username, email, full_name, role, status
             FROM admin_users
             ORDER BY full_name ASC"
        )->fetchAll();
    } catch (Throwable $ex) {
        error_log('Admin accounts list error: ' . $ex->getMessage());
    }
}

/* ---------- View helpers & derived data (UI only) ---------- */

$ICONS = [
    'home'     => '<path d="M3 11l9-8 9 8v9a1 1 0 01-1 1h-5v-6H9v6H4a1 1 0 01-1-1z"/>',
    'list'     => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/>',
    'users'    => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
    'settings' => '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
    'logout'   => '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/>',
    'checkin'  => '<path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4M10 17l5-5-5-5M15 12H3"/>',
    'search'   => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
    'bell'     => '<path d="M6 8a6 6 0 0112 0c0 7 3 9 3 9H3s3-2 3-9M10 21a2 2 0 004 0"/>',
    'calendar' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 10h18M8 2v4M16 2v4M8 14h2M12 14h2M16 14h0M8 17h2M12 17h2"/>',
    'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'menu'     => '<path d="M3 6h18M3 12h18M3 18h18"/>',
    'chevron'  => '<path d="M6 9l6 6 6-6"/>',
    'close'    => '<path d="M6 6l12 12M18 6L6 18"/>',
    'camp'     => '<path d="M2 20L12 4l10 16z"/><path d="M12 20l-3-6h6z"/>',
    'reports'  => '<path d="M5 20V11M12 20V4M19 20v-6"/>',
    'userplus' => '<circle cx="10" cy="8" r="4"/><path d="M2 21c0-4 3.5-6 8-6s8 2 8 6M19 8v6M16 11h6"/>',
    'switch'   => '<path d="M7 4L3 8l4 4M3 8h14M17 20l4-4-4-4M21 16H7"/>',
];

function icon(string $name): string
{
    global $ICONS;
    return '<svg class="i" viewBox="0 0 24 24" aria-hidden="true">' . ($ICONS[$name] ?? '') . '</svg>';
}

function fmtDate($date): string
{
    if (empty($date)) return '—';
    $ts = strtotime((string)$date);
    return $ts ? date('M j, Y', $ts) : e($date);
}

function statusPill($status): string
{
    $s = (string)$status;
    return '<span class="pill pill-' . e(strtolower($s)) . '">' . e($s) . '</span>';
}

function bookingDialog(array $b, string $returnView = 'reservations', string $returnMonth = ''): string
{
    ob_start();
?>
<dialog class="modal" id="dlg-<?= (int)$b['booking_id'] ?>">
    <div class="modal-head">
        <h3>Reservation #<?= (int)$b['booking_id'] ?></h3>
        <button type="button" class="modal-close" data-close aria-label="Close"><?= icon('close') ?></button>
    </div>
    <div class="modal-body">
        <div class="detail-grid">
            <div><span>Guest</span><p><?= e($b['full_name'] ?? 'Unknown') ?></p></div>
            <div><span>Status</span><p><?= statusPill($b['status']) ?></p></div>
            <div><span>Email</span><p><?= e($b['email'] ?? '—') ?></p></div>
            <div><span>Phone</span><p><?= e($b['phone_number'] ?? '—') ?></p></div>
            <div class="full"><span>Address</span><p><?= e($b['address'] ?? '—') ?></p></div>

            <div><span>Check-in</span><p><?= fmtDate($b['check_in_date']) ?><?= $b['check_in_time'] ? ' · ETA ' . e($b['check_in_time']) : '' ?></p></div>
            <div><span>Check-out</span><p><?= fmtDate($b['check_out_date']) ?></p></div>

            <div>
                <span>Guests</span>
                <p><?= (int)$b['num_guests'] ?> total (<?= (int)$b['num_adults'] ?> adults, <?= (int)$b['num_kids'] ?> kids)<?= !empty($b['kids_ages']) ? ' · Ages: ' . e($b['kids_ages']) : '' ?></p>
            </div>
            <div><span>Total Price</span><p>₱<?= number_format((float)$b['total_price'], 2) ?></p></div>

            <div><span>Accommodation</span><p><?= e($b['accommodation_type'] ?? '—') ?></p></div>
            <div><span>Meal Plan</span><p><?= e($b['meal_plan'] ?? 'None') ?></p></div>

            <?php if (!empty($b['dietary_notes'])): ?>
                <div class="full"><span>Dietary Notes</span><p><?= e($b['dietary_notes']) ?></p></div>
            <?php endif; ?>
            <?php if ((int)$b['has_pet'] === 1): ?>
                <div class="full"><span>Pet</span><p><?= e($b['pet_details'] ?? 'Yes') ?></p></div>
            <?php endif; ?>
            <div class="full"><span>Special Requests</span><p><?= !empty($b['special_requests']) ? e($b['special_requests']) : '—' ?></p></div>
            <div class="full"><span>Submitted</span><p><?= e($b['created_at']) ?></p></div>
        </div>

        <form class="update-box" method="POST" action="admin.php?view=<?= e($returnView) ?><?= $returnMonth !== '' ? '&amp;month=' . e($returnMonth) : '' ?>">
            <h4>Update Status</h4>
            <input type="hidden" name="action" value="update_booking">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="booking_id" value="<?= (int)$b['booking_id'] ?>">
                <input type="hidden" name="return_view" value="<?= e($returnView) ?>">
                <input type="hidden" name="return_month" value="<?= e($returnMonth) ?>">

            <select name="status" required>
                <?php foreach (['Pending', 'Confirmed', 'Cancelled', 'Completed'] as $opt): ?>
                    <option value="<?= e($opt) ?>" <?= $b['status'] === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="notes" placeholder="Note (optional)" maxlength="255">
            <button class="btn-primary" type="submit">Save Changes</button>
        </form>
    </div>
</dialog>
<?php
    return (string)ob_get_clean();
}

$view  = (string)($_GET['view'] ?? 'dashboard');
$views = ['dashboard', 'reservations', 'calendar', 'camp', 'customers', 'reports', 'settings'];
if (!in_array($view, $views, true)) {
    $view = 'dashboard';
}

$today          = date('Y-m-d');
$todayCheckins  = 0;
$todayCheckouts = 0;
$upcoming       = [];
$customers      = [];

foreach ($bookings as $b) {
    $isActive = $b['status'] !== 'Cancelled';

    if ($isActive && $b['check_in_date'] === $today)  $todayCheckins++;
    if ($isActive && $b['check_out_date'] === $today) $todayCheckouts++;

    if (in_array($b['status'], ['Pending', 'Confirmed'], true) && $b['check_out_date'] >= $today) {
        $upcoming[] = $b;
    }

    $key = $b['user_id'] ?? ('b' . $b['booking_id']);
    if (!isset($customers[$key])) {
        $customers[$key] = [
            'name'     => $b['full_name'] ?? 'Unknown',
            'email'    => $b['email'] ?? '',
            'phone'    => $b['phone_number'] ?? '',
            'address'  => $b['address'] ?? '',
            'bookings' => 0,
            'spent'    => 0.0,
            'last'     => $b['check_in_date'],
        ];
    }
    $customers[$key]['bookings']++;
    if ($isActive) $customers[$key]['spent'] += (float)$b['total_price'];
    if ($b['check_in_date'] > $customers[$key]['last']) $customers[$key]['last'] = $b['check_in_date'];
}

usort($upcoming, fn($a, $b) => strcmp((string)$a['check_in_date'], (string)$b['check_in_date']));
$upcomingShort = array_slice($upcoming, 0, 5);

$adminName = (string)($_SESSION['admin_name'] ?? 'Administrator');
$adminRole = (string)($_SESSION['admin_role'] ?? 'admin');
$initial   = strtoupper(mb_substr($adminName !== '' ? $adminName : 'A', 0, 1));
$canAddAccount = assignableRoles($adminRole) !== [];

$titles = [
    'dashboard'    => 'Dashboard',
    'reservations' => 'Reservations',
    'calendar'     => 'Calendar',
    'camp'        => 'Camp',
    'customers'    => 'Customers',
    'reports'      => 'Reports',
    'settings'     => 'Settings',
];

$prefillQ      = trim((string)($_GET['q'] ?? ''));
$prefillStatus = (string)($_GET['status'] ?? '');
if (!in_array($prefillStatus, ['Pending', 'Confirmed', 'Cancelled', 'Completed'], true)) {
    $prefillStatus = '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($titles[$view]) ?> | <?= e(CAMP_NAME) ?> Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@700;900&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #0b3a6b;
            --side: #222222;
            --side-text: #9a9a9a;
            --line: #e4e7ec;
            --grid: #ececec;
            --blue-1: #1ea5e6;
            --blue-2: #4a56c9;
            --sidebar-w: 270px;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; }
        body { font-family: 'Poppins', Arial, sans-serif; background: #fff; color: #1d1d1d; }
        h1, h2, h3, th { font-family: 'Lato', 'Poppins', sans-serif; font-weight: 900; color: var(--navy); }
        a { color: inherit; text-decoration: none; }
        svg.i { width: 1em; height: 1em; fill: none; stroke: currentColor; stroke-width: 2;
                stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }

        /* ---------- Sidebar ---------- */
        .sidebar {
            position: fixed; inset: 0 auto 0 0; z-index: 50;
            width: var(--sidebar-w); padding: 26px 22px;
            display: flex; flex-direction: column;
            background: var(--side); overflow-y: auto;
            transition: transform .25s ease;
        }
        .side-logo { display: block; width: 190px; max-width: 100%; margin: 0 auto 34px; }
        .side-nav { display: flex; flex-direction: column; gap: 6px; }
        .side-nav a, .side-bottom a {
            display: flex; align-items: center; gap: 14px;
            padding: 12px 18px; border-radius: 40px;
            color: var(--side-text); font-size: 17px; font-weight: 600;
            transition: color .2s, background .2s;
        }
        .side-nav a svg, .side-bottom a svg { font-size: 20px; }
        .side-nav a:hover, .side-bottom a:hover { color: #fff; }
        .side-nav a.active {
            color: #fff;
            background: linear-gradient(90deg, var(--blue-1), var(--blue-2));
            box-shadow: 0 6px 16px rgba(30, 165, 230, .25);
        }
        .side-bottom { margin-top: auto; padding-top: 22px; border-top: 1px solid #555; }
        .side-bottom a { font-size: 16px; }
        .backdrop { display: none; }

        /* ---------- Main ---------- */
        .main { margin-left: var(--sidebar-w); padding: 34px 40px 50px; min-height: 100vh; }
        .topbar { display: flex; align-items: center; gap: 18px; margin-bottom: 28px; }
        .topbar h1 { margin: 0; font-size: 30px; flex: 1; }
        .menu-btn { display: none; border: 0; background: none; color: var(--navy); font-size: 28px; cursor: pointer; padding: 4px; }

        .top-search {
            display: flex; align-items: center; gap: 10px;
            width: 280px; padding: 9px 16px;
            background: #fff; border-radius: 40px; box-shadow: 0 2px 12px rgba(0,0,0,.08);
            color: var(--blue-1);
        }
        .top-search input { width: 100%; border: 0; outline: 0; font: inherit; font-size: 13px; color: #333; background: transparent; }
        .icon-btn { position: relative; display: grid; place-items: center; font-size: 24px; color: #8a8f98; }
        .icon-btn .dot {
            position: absolute; top: -3px; right: -4px; min-width: 15px; height: 15px; padding: 0 4px;
            border-radius: 10px; background: #e53935; color: #fff; font-size: 9px; font-weight: 600;
            display: grid; place-items: center; line-height: 1;
        }
        .profile { display: flex; align-items: center; gap: 12px; }
        .profile .who { text-align: right; line-height: 1.25; }
        .profile .who strong { display: block; font-size: 15px; font-weight: 500; }
        .profile .who span { font-size: 12px; color: #555; text-transform: capitalize; }
        .avatar {
            width: 38px; height: 38px; border-radius: 50%;
            display: grid; place-items: center; color: #fff; font-weight: 600;
            background: linear-gradient(135deg, var(--blue-1), var(--blue-2));
        }

        /* ---------- Cards ---------- */
        .stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 30px; margin-bottom: 34px; }
        .stat-card {
            padding: 22px 20px 20px; background: #fff;
            border: 2px solid #eceff3; border-radius: 22px;
            box-shadow: 0 2px 8px rgba(0,0,0,.03);
        }
        .stat-card .s-icon { font-size: 46px; color: var(--navy); line-height: 1; margin-bottom: 14px; }
        .stat-card .s-icon.gold { color: #e5b800; }
        .stat-card .num { font-size: 34px; font-weight: 600; line-height: 1.1; color: #111; }
        .stat-card .lbl { font-family: 'Lato', sans-serif; font-weight: 700; color: var(--navy); font-size: 15px; margin-top: 4px; }

        .panel { padding: 22px; margin-bottom: 28px; background: #fff; border: 2px solid #eceff3; border-radius: 22px; }
        .panel-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }
        .panel-head h2 { margin: 0; font-size: 22px; }
        .panel-head a { font-family: 'Lato', sans-serif; font-weight: 900; color: var(--navy); font-size: 15px; }
        .panel-head a:hover { color: var(--blue-1); }

        /* ---------- Tables ---------- */
        .table-wrap { width: 100%; overflow-x: auto; }
        table.grid { width: 100%; border-collapse: collapse; border: 3px solid var(--grid); }
        table.grid th, table.grid td {
            padding: 14px 12px; text-align: center; vertical-align: middle;
            border: 2px solid var(--grid); font-size: 14px; white-space: nowrap;
        }
        table.grid th { font-size: 16px; background: #fff; }
        table.grid td.left, table.grid th.left { text-align: left; }
        table.grid td small { display: block; color: #8a8f98; font-size: 12px; }
        table.grid tbody tr:hover td { background: #fafcff; }
        .empty { padding: 30px; text-align: center; color: #8a8f98; }

        /* ---------- Pills & buttons ---------- */
        .pill {
            display: inline-block; min-width: 108px; padding: 3px 14px;
            border-radius: 20px; border: 2px solid; font-size: 14px; font-weight: 500; text-align: center;
        }
        .pill-confirmed { background: #d6f5d6; border-color: #3cb54a; color: #2fa84a; }
        .pill-pending   { background: #ffe600; border-color: #b59a00; color: #a38600; }
        .pill-cancelled { background: #ffdede; border-color: #d9534f; color: #c0392b; }
        .pill-completed { background: #dbeafe; border-color: #3b82f6; color: #1e40af; }

        .btn-view {
            display: inline-block; min-width: 128px; padding: 5px 14px;
            border: 2px solid var(--navy); border-radius: 3px; background: #fff;
            color: var(--navy); font: inherit; font-size: 15px; font-weight: 500; cursor: pointer;
            transition: background .2s, color .2s;
        }
        .btn-view:hover { background: var(--navy); color: #fff; }
        .btn-primary {
            padding: 12px 26px; border: 0; border-radius: 40px; color: #fff;
            background: linear-gradient(90deg, var(--blue-1), var(--blue-2));
            font: inherit; font-size: 14px; font-weight: 600; cursor: pointer;
            transition: transform .2s, box-shadow .2s;
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 18px rgba(74, 86, 201, .3); }

        /* ---------- Filters ---------- */
        .search-big {
            display: flex; align-items: center; gap: 12px; margin-bottom: 14px;
            padding: 16px 26px; border: 2px solid #eceff3; border-radius: 50px; background: #fff;
            color: var(--blue-1); font-size: 22px;
        }
        .search-big input { width: 100%; border: 0; outline: 0; font: inherit; font-size: 15px; color: #333; }
        .filters { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 24px; }
        .select-pill { position: relative; }
        .select-pill select {
            width: 100%; padding: 17px 54px 17px 30px; appearance: none; -webkit-appearance: none;
            border: 2px solid #eceff3; border-radius: 50px; background: #fff;
            font: inherit; font-size: 15px; color: var(--navy); cursor: pointer;
        }
        .select-pill svg { position: absolute; right: 26px; top: 50%; transform: translateY(-50%); font-size: 22px; color: var(--navy); pointer-events: none; }

        /* ---------- Messages ---------- */
        .message { padding: 13px 18px; margin-bottom: 20px; border-radius: 14px; font-size: 14px; }
        .message.success { background: #e8f8ef; color: #17683b; border-left: 4px solid #3cb54a; }
        .message.error { background: #fff0ef; color: #922b21; border-left: 4px solid #c0392b; }

        /* ---------- Activity ---------- */
        .activity-list { list-style: none; padding: 0; margin: 0; }
        .activity-list li { padding: 13px 0; border-bottom: 1px solid #eee; font-size: 14px; line-height: 1.7; }
        .activity-list li:last-child { border-bottom: 0; }
        .activity-list .pill { min-width: 0; padding: 0 10px; font-size: 12px; }
        .activity-list .time { color: #8a8f98; font-size: 12px; }

        .info-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px 30px; font-size: 14px; }
        .info-grid div span { display: block; color: #8a8f98; font-size: 12px; }

        /* ---------- Modal ---------- */
        dialog.modal {
            width: min(640px, 94vw); max-height: 90vh; padding: 0; border: 0; border-radius: 22px;
            box-shadow: 0 20px 60px rgba(0,0,0,.3); overflow: hidden;
        }
        dialog.modal::backdrop { background: rgba(0,0,0,.55); }
        .modal-head { display: flex; justify-content: space-between; align-items: center; padding: 20px 26px; border-bottom: 1px solid var(--line); }
        .modal-head h3 { margin: 0; font-size: 20px; }
        .modal-close { border: 0; background: none; font-size: 24px; color: #8a8f98; cursor: pointer; }
        .modal-body { padding: 22px 26px 26px; max-height: calc(90vh - 70px); overflow-y: auto; }
        .detail-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px 22px; margin-bottom: 20px; font-size: 14px; }
        .detail-grid .full { grid-column: 1 / -1; }
        .detail-grid span { display: block; color: #8a8f98; font-size: 12px; }
        .detail-grid p { margin: 0; overflow-wrap: anywhere; white-space: pre-line; }
        .update-box { padding: 18px; background: #f6f9fc; border-radius: 16px; }
        .update-box h4 { margin: 0 0 12px; font-family: 'Lato', sans-serif; color: var(--navy); font-size: 16px; }
        .update-box select, .update-box input[type=text] {
            width: 100%; margin-bottom: 10px; padding: 12px 18px; border: 2px solid #e4e9f0;
            border-radius: 40px; background: #fff; font: inherit; font-size: 14px;
        }

        footer { margin-top: 20px; color: #8a8f98; font-size: 12px; text-align: center; }

        /* ---------- Sidebar buttons (Add / Switch account) ---------- */
        .side-bottom button {
            display: flex; align-items: center; gap: 14px; width: 100%;
            padding: 12px 18px; border: 0; border-radius: 40px; background: none;
            color: var(--side-text); font: inherit; font-size: 16px; font-weight: 600;
            text-align: left; cursor: pointer; transition: color .2s;
        }
        .side-bottom button svg { font-size: 20px; }
        .side-bottom button:hover { color: #fff; }

        .btn-ghost {
            display: inline-block; padding: 10px 22px; border: 2px solid var(--navy);
            border-radius: 40px; background: #fff; color: var(--navy);
            font: inherit; font-size: 14px; font-weight: 600; cursor: pointer;
            transition: background .2s, color .2s;
        }
        .btn-ghost:hover { background: var(--navy); color: #fff; }

        /* ---------- Calendar ---------- */
        .cal-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 18px; }
        .cal-toolbar h2 { margin: 0; flex: 1; min-width: 150px; font-size: 22px; }
        .cal-wrap { overflow-x: auto; }
        .cal { display: grid; grid-template-columns: repeat(7, minmax(110px, 1fr)); min-width: 780px; border: 3px solid var(--grid); }
        .cal-dow { padding: 10px; text-align: center; border: 1px solid var(--grid); font-family: 'Lato', sans-serif; font-weight: 900; color: var(--navy); }
        .cal-day { display: flex; flex-direction: column; gap: 4px; min-height: 108px; padding: 6px; border: 1px solid var(--grid); background: #fff; }
        .cal-day.out { background: #f8f9fb; }
        .cal-day.today { box-shadow: inset 0 0 0 2px var(--blue-1); }
        .cal-num { font-size: 12px; font-weight: 600; color: #555; }
        .cal-chip {
            display: flex; justify-content: space-between; align-items: center; gap: 6px; width: 100%;
            padding: 3px 8px; border: 1px solid; border-radius: 8px; font: inherit; font-size: 12px;
            text-align: left; cursor: pointer; white-space: nowrap; overflow: hidden;
        }
        .cal-chip span { overflow: hidden; text-overflow: ellipsis; }
        .cal-chip em { font-style: normal; font-size: 10px; font-weight: 600; opacity: .85; }
        .chip-confirmed { background: #d6f5d6; border-color: #3cb54a; color: #1f7a33; }
        .chip-pending   { background: #fff3a8; border-color: #b59a00; color: #7a6600; }
        .chip-completed { background: #dbeafe; border-color: #3b82f6; color: #1e40af; }
        .legend { display: flex; flex-wrap: wrap; gap: 18px; margin-top: 16px; font-size: 13px; color: #555; }
        .legend i { display: inline-block; width: 12px; height: 12px; margin-right: 6px; border: 2px solid; border-radius: 4px; vertical-align: -1px; }

        /* ---------- Reports ---------- */
        .rep-form { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; margin-bottom: 14px; }
        .rep-form label { display: block; margin-bottom: 4px; color: #8a8f98; font-size: 12px; }
        .rep-form input[type=date] {
            padding: 10px 18px; border: 2px solid #eceff3; border-radius: 40px;
            font: inherit; font-size: 14px; color: var(--navy);
        }
        .rep-note { margin: 0 0 24px; color: #8a8f98; font-size: 13px; }
        td.bar-cell { min-width: 180px; text-align: left; }
        .bar { height: 10px; min-width: 2px; border-radius: 6px; background: linear-gradient(90deg, var(--blue-1), var(--blue-2)); }

        /* ---------- Account dialogs ---------- */
        .form-stack label { display: block; margin: 12px 0 4px; color: #8a8f98; font-size: 12px; }
        .form-stack input, .form-stack select {
            width: 100%; padding: 12px 18px; border: 2px solid #e4e9f0; border-radius: 40px;
            background: #fff; font: inherit; font-size: 14px;
        }
        .form-stack input:focus, .form-stack select:focus { outline: none; border-color: var(--blue-1); }
        .form-stack .btn-primary { width: 100%; margin-top: 18px; }
        .acct-list { display: grid; gap: 8px; margin: 14px 0 6px; }
        .acct-btn {
            display: flex; align-items: center; gap: 12px; width: 100%; padding: 10px 14px;
            border: 2px solid #eceff3; border-radius: 16px; background: #fff;
            font: inherit; text-align: left; cursor: pointer;
        }
        .acct-btn:hover { border-color: var(--blue-1); }
        .acct-btn small { display: block; color: #8a8f98; text-transform: capitalize; }

        @media print {
            .sidebar, .topbar, .no-print, footer { display: none !important; }
            .main { margin: 0; padding: 0; }
            .panel, .stat-card { break-inside: avoid; }
        }

        /* ---------- Responsive ---------- */
        @media (max-width: 1150px) {
            .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
        }
        @media (max-width: 900px) {
            .sidebar { transform: translateX(-100%); }
            body.nav-open .sidebar { transform: none; }
            body.nav-open .backdrop { display: block; position: fixed; inset: 0; z-index: 40; background: rgba(0,0,0,.5); }
            .main { margin-left: 0; padding: 22px 18px 40px; }
            .menu-btn { display: block; }
            .top-search, .profile .who { display: none; }
            .topbar h1 { font-size: 24px; }
        }
        @media (max-width: 560px) {
            .stats { grid-template-columns: 1fr; }
            .filters { grid-template-columns: 1fr; }
            .detail-grid, .info-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>

<body>
<aside class="sidebar" id="sidebar">
    <img class="side-logo" src="images/logo.jpg" alt="<?= e(CAMP_NAME) ?>">

    <nav class="side-nav">
        <a href="admin.php?view=dashboard"    class="<?= $view === 'dashboard'    ? 'active' : '' ?>"><?= icon('home') ?> Dashboard</a>
        <a href="admin.php?view=reservations" class="<?= $view === 'reservations' ? 'active' : '' ?>"><?= icon('list') ?> Reservations</a>
        <a href="admin.php?view=calendar"     class="<?= $view === 'calendar'     ? 'active' : '' ?>"><?= icon('calendar') ?> Calendar</a>
        <a href="admin.php?view=camp"        class="<?= $view === 'camp'        ? 'active' : '' ?>"><?= icon('camp') ?> Camp</a>
        <a href="admin.php?view=customers"    class="<?= $view === 'customers'    ? 'active' : '' ?>"><?= icon('users') ?> Customers</a>
        <a href="admin.php?view=reports"      class="<?= $view === 'reports'      ? 'active' : '' ?>"><?= icon('reports') ?> Reports</a>
        <a href="admin.php?view=settings"     class="<?= $view === 'settings'     ? 'active' : '' ?>"><?= icon('settings') ?> Settings</a>
    </nav>

    <div class="side-bottom">
        <?php if ($canAddAccount): ?>
            <button type="button" data-open="dlg-add-account"><?= icon('userplus') ?> Add Account</button>
        <?php endif; ?>
        <button type="button" data-open="dlg-switch-account"><?= icon('switch') ?> Switch Account</button>
        <a href="admin.php?logout=1"><?= icon('logout') ?> Log Out</a>
    </div>
</aside>
<div class="backdrop" id="backdrop"></div>

<main class="main">
    <header class="topbar">
        <button class="menu-btn" id="menuBtn" type="button" aria-label="Open menu"><?= icon('menu') ?></button>
        <h1><?= e($titles[$view]) ?></h1>

        <?php if ($view !== 'reservations'): ?>
            <form class="top-search" method="GET" action="admin.php" role="search">
                <?= icon('search') ?>
                <input type="hidden" name="view" value="reservations">
                <input type="text" name="q" placeholder="Search Here" aria-label="Search reservations">
            </form>
        <?php endif; ?>

        <a class="icon-btn" href="admin.php?view=reservations&amp;status=Pending" title="Pending requests" aria-label="Pending requests">
            <?= icon('bell') ?>
            <?php if ($stats['pending'] > 0): ?><span class="dot"><?= (int)$stats['pending'] ?></span><?php endif; ?>
        </a>

        <div class="profile">
            <div class="who">
                <strong><?= e($adminName) ?></strong>
                <span><?= e($adminRole) ?></span>
            </div>
            <div class="avatar"><?= e($initial) ?></div>
        </div>
    </header>

    <?php if ($success !== ''): ?>
        <div class="message success"><?= e($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="message error"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($dashboardError !== ''): ?>
        <div class="message error"><?= e($dashboardError) ?></div>
    <?php endif; ?>

<?php /* =================== DASHBOARD =================== */ ?>
<?php if ($view === 'dashboard'): ?>

    <section class="stats">
        <article class="stat-card">
            <div class="s-icon"><?= icon('calendar') ?></div>
            <div class="num"><?= (int)$stats['bookings'] ?></div>
            <div class="lbl">Total Reservations</div>
        </article>
        <article class="stat-card">
            <div class="s-icon"><?= icon('checkin') ?></div>
            <div class="num"><?= (int)$todayCheckins ?></div>
            <div class="lbl">Check-in Today</div>
        </article>
        <article class="stat-card">
            <div class="s-icon"><?= icon('logout') ?></div>
            <div class="num"><?= (int)$todayCheckouts ?></div>
            <div class="lbl">Check-out Today</div>
        </article>
        <article class="stat-card">
            <div class="s-icon gold"><?= icon('clock') ?></div>
            <div class="num"><?= (int)$stats['pending'] ?></div>
            <div class="lbl">Pending Requests</div>
        </article>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Upcoming Reservations</h2>
            <a href="admin.php?view=reservations">View All</a>
        </div>

        <?php if (empty($upcomingShort)): ?>
            <div class="empty">No upcoming reservations.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="grid">
                    <thead>
                        <tr>
                            <th>Guest Name</th>
                            <th>Accommodation</th>
                            <th>Check-in</th>
                            <th>Check-out</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($upcomingShort as $b): ?>
                        <tr>
                            <td><?= e($b['full_name'] ?? 'Unknown') ?></td>
                            <td><?= e($b['accommodation_type'] ?? '—') ?></td>
                            <td><?= fmtDate($b['check_in_date']) ?></td>
                            <td><?= fmtDate($b['check_out_date']) ?></td>
                            <td><?= statusPill($b['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Recent Activity</h2></div>
        <?php if (empty($recentActivity)): ?>
            <div class="empty">No activity yet. Update a reservation status to start tracking.</div>
        <?php else: ?>
            <ul class="activity-list">
                <?php foreach ($recentActivity as $act): ?>
                    <li>
                        <strong>#<?= (int)$act['booking_id'] ?></strong>
                        — <?= e($act['full_name'] ?? 'Unknown') ?>:
                        <?= statusPill($act['old_status'] ?? 'Pending') ?>
                        →
                        <?= statusPill($act['new_status']) ?>
                        <?php if (!empty($act['notes'])): ?>
                            <br><em><?= e($act['notes']) ?></em>
                        <?php endif; ?>
                        <div class="time">
                            <?= e($act['changed_at']) ?>
                            <?php if (!empty($act['changed_by_admin_id'])): ?>
                                · Admin #<?= (int)$act['changed_by_admin_id'] ?>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

<?php /* =================== RESERVATIONS =================== */ ?>
<?php elseif ($view === 'reservations'): ?>

    <div class="search-big">
        <?= icon('search') ?>
        <input type="text" id="resSearch" value="<?= e($prefillQ) ?>"
               placeholder="Search guest name, email, or reference no." autocomplete="off">
    </div>

    <div class="filters">
        <div class="select-pill">
            <select id="resWhen" aria-label="Filter by date">
                <option value="">All Dates</option>
                <option value="upcoming">Upcoming</option>
                <option value="past">Past</option>
            </select>
            <?= icon('chevron') ?>
        </div>
        <div class="select-pill">
            <select id="resStatus" aria-label="Filter by status">
                <option value="">All Status</option>
                <?php foreach (['Pending', 'Confirmed', 'Cancelled', 'Completed'] as $opt): ?>
                    <option value="<?= e($opt) ?>" <?= $prefillStatus === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                <?php endforeach; ?>
            </select>
            <?= icon('chevron') ?>
        </div>
    </div>

    <?php if (empty($bookings) && $dashboardError === ''): ?>
        <div class="empty">No reservation records found.</div>
    <?php elseif (!empty($bookings)): ?>
        <div class="table-wrap">
            <table class="grid">
                <thead>
                    <tr>
                        <th>Ref #</th>
                        <th>Guest Name</th>
                        <th>Guests</th>
                        <th>Check-in</th>
                        <th>Check-out</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="resBody">
                <?php foreach ($bookings as $b): ?>
                    <?php
                    $search = strtolower(
                        '#' . $b['booking_id'] . ' ' . $b['booking_id'] . ' '
                        . ($b['full_name'] ?? '') . ' ' . ($b['email'] ?? '') . ' ' . ($b['phone_number'] ?? '')
                    );
                    $when = $b['check_out_date'] >= $today ? 'upcoming' : 'past';
                    ?>
                    <tr data-search="<?= e($search) ?>" data-status="<?= e($b['status']) ?>" data-when="<?= e($when) ?>">
                        <td>#<?= (int)$b['booking_id'] ?></td>
                        <td><?= e($b['full_name'] ?? 'Unknown') ?></td>
                        <td><?= (int)$b['num_guests'] ?></td>
                        <td><?= fmtDate($b['check_in_date']) ?></td>
                        <td><?= fmtDate($b['check_out_date']) ?></td>
                        <td><?= statusPill($b['status']) ?></td>
                        <td>
                            <button type="button" class="btn-view" data-open="dlg-<?= (int)$b['booking_id'] ?>">View</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="empty" id="resEmpty" hidden>No reservations match your search.</div>

        <?php foreach ($bookings as $b) { echo bookingDialog($b, 'reservations'); } ?>
    <?php endif; ?>

<?php /* =================== CUSTOMERS =================== */ ?>
<?php elseif ($view === 'customers'): ?>

    <section class="stats" style="grid-template-columns:repeat(2,minmax(0,1fr));max-width:560px;">
        <article class="stat-card">
            <div class="s-icon"><?= icon('users') ?></div>
            <div class="num"><?= (int)$stats['users'] ?></div>
            <div class="lbl">Registered Customers</div>
        </article>
        <article class="stat-card">
            <div class="s-icon"><?= icon('calendar') ?></div>
            <div class="num"><?= (int)$stats['completed'] ?></div>
            <div class="lbl">Completed Stays</div>
        </article>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Customer List</h2></div>
        <?php if (empty($customers)): ?>
            <div class="empty">No customers yet.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="grid">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Address</th>
                            <th>Reservations</th>
                            <th>Total Value</th>
                            <th>Last Check-in</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($customers as $c): ?>
                        <tr>
                            <td class="left"><?= e($c['name']) ?></td>
                            <td class="left"><?= e($c['email']) ?></td>
                            <td><?= e($c['phone']) ?></td>
                            <td class="left"><?= e($c['address']) ?></td>
                            <td><?= (int)$c['bookings'] ?></td>
                            <td>₱<?= number_format($c['spent'], 2) ?></td>
                            <td><?= fmtDate($c['last']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

<?php /* =================== CALENDAR =================== */ ?>
<?php elseif ($view === 'calendar'): ?>
<?php
    $calMonth = (string)($_GET['month'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $calMonth)) {
        $calMonth = date('Y-m');
    }
    $calFirst    = new DateTimeImmutable($calMonth . '-01');
    $calLast     = $calFirst->modify('last day of this month');
    $calDays     = (int)$calFirst->format('t');
    $calStartDow = (int)$calFirst->format('w');
    $calPrev     = $calFirst->modify('-1 month')->format('Y-m');
    $calNext     = $calFirst->modify('+1 month')->format('Y-m');
    $firstStr    = $calFirst->format('Y-m-d');
    $lastStr     = $calLast->format('Y-m-d');

    $calMap = [];
    $calIds = [];
    foreach ($bookings as $b) {
        if ($b['status'] === 'Cancelled') continue;
        if ($b['check_out_date'] < $firstStr || $b['check_in_date'] > $lastStr) continue;
        $calIds[(int)$b['booking_id']] = $b;
        $cur = new DateTimeImmutable(max($b['check_in_date'], $firstStr));
        $end = new DateTimeImmutable(min($b['check_out_date'], $lastStr));
        for (; $cur <= $end; $cur = $cur->modify('+1 day')) {
            $calMap[$cur->format('Y-m-d')][] = $b;
        }
    }
    $calTrail = (7 - (($calStartDow + $calDays) % 7)) % 7;
?>
    <section class="panel">
        <div class="cal-toolbar">
            <h2><?= e($calFirst->format('F Y')) ?></h2>
            <a class="btn-ghost" href="admin.php?view=calendar&amp;month=<?= e($calPrev) ?>">Previous</a>
            <a class="btn-ghost" href="admin.php?view=calendar">Today</a>
            <a class="btn-ghost" href="admin.php?view=calendar&amp;month=<?= e($calNext) ?>">Next</a>
        </div>

        <div class="cal-wrap">
            <div class="cal">
                <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dow): ?>
                    <div class="cal-dow"><?= e($dow) ?></div>
                <?php endforeach; ?>

                <?php for ($i = 0; $i < $calStartDow; $i++): ?><div class="cal-day out"></div><?php endfor; ?>

                <?php for ($dayNo = 1; $dayNo <= $calDays; $dayNo++): ?>
                    <?php $ds = $calFirst->format('Y-m-') . sprintf('%02d', $dayNo); ?>
                    <div class="cal-day<?= $ds === $today ? ' today' : '' ?>">
                        <span class="cal-num"><?= $dayNo ?></span>
                        <?php foreach ($calMap[$ds] ?? [] as $b): ?>
                            <?php
                            $tag = $ds === $b['check_in_date'] ? 'In' : ($ds === $b['check_out_date'] ? 'Out' : '');
                            ?>
                            <button type="button"
                                    class="cal-chip chip-<?= e(strtolower($b['status'])) ?>"
                                    data-open="dlg-<?= (int)$b['booking_id'] ?>"
                                    title="<?= e(($b['full_name'] ?? 'Unknown') . ' · ' . $b['status']) ?>">
                                <span><?= e($b['full_name'] ?? 'Unknown') ?></span>
                                <?php if ($tag !== ''): ?><em><?= e($tag) ?></em><?php endif; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endfor; ?>

                <?php for ($i = 0; $i < $calTrail; $i++): ?><div class="cal-day out"></div><?php endfor; ?>
            </div>
        </div>

        <div class="legend">
            <span><i style="background:#d6f5d6;border-color:#3cb54a"></i>Confirmed</span>
            <span><i style="background:#fff3a8;border-color:#b59a00"></i>Pending</span>
            <span><i style="background:#dbeafe;border-color:#3b82f6"></i>Completed</span>
            <span>In = check-in day, Out = check-out day. Cancelled reservations are hidden.</span>
        </div>
    </section>

    <?php foreach ($calIds as $b) { echo bookingDialog($b, 'calendar', $calMonth); } ?>

<?php /* =================== CAMP =================== */ ?>
<?php elseif ($view === 'camp'): ?>
<?php
    $byAccommodation = [];
    foreach ($bookings as $b) {
        if ($b['status'] === 'Cancelled') continue;
        $k = trim((string)($b['accommodation_type'] ?? '')) ?: 'Unspecified';
        $byAccommodation[$k] ??= ['count' => 0, 'guests' => 0, 'revenue' => 0.0];
        $byAccommodation[$k]['count']++;
        $byAccommodation[$k]['guests'] += (int)$b['num_guests'];
        if (in_array($b['status'], ['Confirmed', 'Completed'], true)) {
            $byAccommodation[$k]['revenue'] += (float)$b['total_price'];
        }
    }
?>
    <section class="panel">
        <div class="panel-head"><h2><?= e(CAMP_NAME) ?></h2></div>
        <div class="info-grid">
            <div><span>Location</span><?= e(CAMP_LOCATION) ?></div>
            <div><span>Package Price</span>₱<?= e(number_format((float)CAMP_PACKAGE_PRICE, 2)) ?> / guest</div>
            <div><span>Capacity</span><?= (int)CAMP_CAPACITY ?> guests</div>
            <div><span>Minimum Guests</span><?= (int)CAMP_MIN_GUESTS ?> guests</div>
            <div><span>Length of Stay</span><?= (int)CAMP_NIGHTS ?> night(s)</div>
            <div><span>Booking Rule</span>One booking per date range</div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Reservations by Accommodation</h2></div>
        <?php if (empty($byAccommodation)): ?>
            <div class="empty">No reservations yet.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="grid">
                    <thead>
                        <tr><th class="left">Accommodation</th><th>Reservations</th><th>Guests</th><th>Revenue</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($byAccommodation as $name => $row): ?>
                        <tr>
                            <td class="left"><?= e($name) ?></td>
                            <td><?= (int)$row['count'] ?></td>
                            <td><?= (int)$row['guests'] ?></td>
                            <td>₱<?= number_format($row['revenue'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

<?php /* =================== REPORTS =================== */ ?>
<?php elseif ($view === 'reports'): ?>
<?php
    $rep = ['count' => 0, 'guests' => 0, 'revenue' => 0.0, 'paid' => 0];
    $byStatus = [];
    foreach (['Pending', 'Confirmed', 'Completed', 'Cancelled'] as $st) {
        $byStatus[$st] = ['count' => 0, 'value' => 0.0];
    }

    $months = [];
    $cur = new DateTimeImmutable(substr($repFrom, 0, 7) . '-01');
    $endM = new DateTimeImmutable(substr($repTo, 0, 7) . '-01');
    for ($n = 0; $cur <= $endM && $n < 36; $n++, $cur = $cur->modify('+1 month')) {
        $months[$cur->format('Y-m')] = ['label' => $cur->format('M Y'), 'count' => 0, 'guests' => 0, 'revenue' => 0.0];
    }

    $top = [];
    foreach ($repBookings as $b) {
        $st = $b['status'];
        if (isset($byStatus[$st])) {
            $byStatus[$st]['count']++;
            $byStatus[$st]['value'] += (float)$b['total_price'];
        }
        if ($st === 'Cancelled') continue;

        $rep['count']++;
        $rep['guests'] += (int)$b['num_guests'];
        $mk = substr((string)$b['check_in_date'], 0, 7);
        if (isset($months[$mk])) {
            $months[$mk]['count']++;
            $months[$mk]['guests'] += (int)$b['num_guests'];
        }

        if (in_array($st, ['Confirmed', 'Completed'], true)) {
            $price = (float)$b['total_price'];
            $rep['revenue'] += $price;
            $rep['paid']++;
            if (isset($months[$mk])) $months[$mk]['revenue'] += $price;

            $ck = $b['user_id'] ?? ('b' . $b['booking_id']);
            $top[$ck] ??= ['name' => $b['full_name'] ?? 'Unknown', 'count' => 0, 'value' => 0.0];
            $top[$ck]['count']++;
            $top[$ck]['value'] += $price;
        }
    }
    uasort($top, fn($a, $b) => $b['value'] <=> $a['value']);
    $top = array_slice($top, 0, 5, true);

    $avgValue = $rep['paid'] > 0 ? $rep['revenue'] / $rep['paid'] : 0.0;
    $maxRev = 0.0;
    foreach ($months as $m) $maxRev = max($maxRev, $m['revenue']);
    $csvUrl = 'admin.php?view=reports&from=' . urlencode($repFrom) . '&to=' . urlencode($repTo) . '&export=csv';
?>
    <form class="rep-form no-print" method="GET" action="admin.php">
        <input type="hidden" name="view" value="reports">
        <div>
            <label for="repFrom">From</label>
            <input type="date" id="repFrom" name="from" value="<?= e($repFrom) ?>">
        </div>
        <div>
            <label for="repTo">To</label>
            <input type="date" id="repTo" name="to" value="<?= e($repTo) ?>">
        </div>
        <button class="btn-primary" type="submit">Apply</button>
        <a class="btn-ghost" href="<?= e($csvUrl) ?>">Export CSV</a>
        <button class="btn-ghost" type="button" onclick="window.print()">Print</button>
    </form>
    <p class="rep-note">
        Reservations checking in from <?= fmtDate($repFrom) ?> to <?= fmtDate($repTo) ?>.
        Revenue counts Confirmed and Completed reservations only.
    </p>

    <section class="stats">
        <article class="stat-card">
            <div class="s-icon"><?= icon('reports') ?></div>
            <div class="num">₱<?= number_format($rep['revenue']) ?></div>
            <div class="lbl">Revenue</div>
        </article>
        <article class="stat-card">
            <div class="s-icon"><?= icon('calendar') ?></div>
            <div class="num"><?= (int)$rep['count'] ?></div>
            <div class="lbl">Reservations</div>
        </article>
        <article class="stat-card">
            <div class="s-icon"><?= icon('users') ?></div>
            <div class="num"><?= (int)$rep['guests'] ?></div>
            <div class="lbl">Guests</div>
        </article>
        <article class="stat-card">
            <div class="s-icon gold"><?= icon('clock') ?></div>
            <div class="num">₱<?= number_format($avgValue) ?></div>
            <div class="lbl">Avg. Booking Value</div>
        </article>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Monthly Summary</h2></div>
        <div class="table-wrap">
            <table class="grid">
                <thead>
                    <tr><th class="left">Month</th><th>Reservations</th><th>Guests</th><th>Revenue</th><th>&nbsp;</th></tr>
                </thead>
                <tbody>
                <?php foreach ($months as $m): ?>
                    <tr>
                        <td class="left"><?= e($m['label']) ?></td>
                        <td><?= (int)$m['count'] ?></td>
                        <td><?= (int)$m['guests'] ?></td>
                        <td>₱<?= number_format($m['revenue'], 2) ?></td>
                        <td class="bar-cell">
                            <?php if ($maxRev > 0 && $m['revenue'] > 0): ?>
                                <div class="bar" style="width:<?= round($m['revenue'] / $maxRev * 100) ?>%"></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>By Status</h2></div>
        <div class="table-wrap">
            <table class="grid">
                <thead>
                    <tr><th>Status</th><th>Reservations</th><th>Total Value</th></tr>
                </thead>
                <tbody>
                <?php foreach ($byStatus as $st => $row): ?>
                    <tr>
                        <td><?= statusPill($st) ?></td>
                        <td><?= (int)$row['count'] ?></td>
                        <td>₱<?= number_format($row['value'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Top Customers</h2></div>
        <?php if (empty($top)): ?>
            <div class="empty">No confirmed or completed reservations in this date range.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="grid">
                    <thead>
                        <tr><th class="left">Customer</th><th>Reservations</th><th>Total Value</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($top as $c): ?>
                        <tr>
                            <td class="left"><?= e($c['name']) ?></td>
                            <td><?= (int)$c['count'] ?></td>
                            <td>₱<?= number_format($c['value'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

<?php /* =================== SETTINGS =================== */ ?>
<?php else: ?>

    <section class="panel">
        <div class="panel-head"><h2>System Information</h2></div>
        <div class="info-grid">
            <div><span>Camp</span><?= e(CAMP_NAME) ?></div>
            <div><span>Location</span><?= e(CAMP_LOCATION) ?></div>
            <div><span>Package Price</span>₱<?= e(number_format(CAMP_PACKAGE_PRICE, 2)) ?> / guest</div>
            <div><span>Signed-in Account</span><?= e($_SESSION['admin_username'] ?? '') ?></div>
            <div><span>Role</span><?= e($adminRole) ?></div>
            <div><span>Server Date</span><?= e(date('M j, Y')) ?></div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Status Summary</h2></div>
        <div class="info-grid">
            <div><span>Pending</span><?= statusPill('Pending') ?> &nbsp;<?= (int)$stats['pending'] ?></div>
            <div><span>Confirmed</span><?= statusPill('Confirmed') ?> &nbsp;<?= (int)$stats['confirmed'] ?></div>
            <div><span>Completed</span><?= statusPill('Completed') ?> &nbsp;<?= (int)$stats['completed'] ?></div>
            <div><span>Cancelled</span><?= statusPill('Cancelled') ?> &nbsp;<?= (int)$stats['cancelled'] ?></div>
        </div>
    </section>

    <?php if (!empty($adminAccounts)): ?>
    <section class="panel">
        <div class="panel-head"><h2>Staff Accounts</h2></div>
        <div class="table-wrap">
            <table class="grid">
                <thead>
                    <tr><th class="left">Name</th><th class="left">Username</th><th class="left">Email</th><th>Role</th><th>Status</th></tr>
                </thead>
                <tbody>
                <?php foreach ($adminAccounts as $a): ?>
                    <tr>
                        <td class="left"><?= e($a['full_name']) ?></td>
                        <td class="left"><?= e($a['username']) ?></td>
                        <td class="left"><?= e($a['email']) ?></td>
                        <td><?= e(ucfirst((string)$a['role'])) ?></td>
                        <td><span class="pill pill-<?= $a['status'] === 'active' ? 'confirmed' : 'cancelled' ?>"><?= e(ucfirst((string)$a['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

<?php endif; ?>

    <footer><?= e(CAMP_NAME) ?> Management System</footer>
</main>

<?php if ($canAddAccount): ?>
<dialog class="modal" id="dlg-add-account">
    <div class="modal-head">
        <h3>Add Account</h3>
        <button type="button" class="modal-close" data-close aria-label="Close"><?= icon('close') ?></button>
    </div>
    <div class="modal-body">
        <?php if ($dlgErr['add'] !== ''): ?>
            <div class="message error"><?= e($dlgErr['add']) ?></div>
        <?php endif; ?>
        <form class="form-stack" method="POST" action="admin.php?view=<?= e($view) ?>" autocomplete="off">
            <input type="hidden" name="action" value="add_account">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

            <label for="addName">Full name</label>
            <input type="text" id="addName" name="full_name" maxlength="100" value="<?= e($addForm['full_name']) ?>" required>

            <label for="addUser">Username</label>
            <input type="text" id="addUser" name="username" maxlength="50" value="<?= e($addForm['username']) ?>" required>

            <label for="addEmail">Email</label>
            <input type="email" id="addEmail" name="email" maxlength="100" value="<?= e($addForm['email']) ?>" required>

            <label for="addRole">Role</label>
            <select id="addRole" name="role">
                <?php foreach (assignableRoles($adminRole) as $r): ?>
                    <option value="<?= e($r) ?>" <?= $addForm['role'] === $r ? 'selected' : '' ?>><?= e(ucfirst($r)) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="addPass">Password (at least 8 characters)</label>
            <input type="password" id="addPass" name="password" minlength="8" autocomplete="new-password" required>

            <label for="addPass2">Confirm password</label>
            <input type="password" id="addPass2" name="password_confirm" minlength="8" autocomplete="new-password" required>

            <button class="btn-primary" type="submit">Create account</button>
        </form>
    </div>
</dialog>
<?php endif; ?>

<?php
$knownAccounts = array_filter(
    (array)($_SESSION['known_accounts'] ?? []),
    fn($id) => (int)$id !== (int)($_SESSION['admin_id'] ?? 0),
    ARRAY_FILTER_USE_KEY
);
?>
<dialog class="modal" id="dlg-switch-account">
    <div class="modal-head">
        <h3>Switch Account</h3>
        <button type="button" class="modal-close" data-close aria-label="Close"><?= icon('close') ?></button>
    </div>
    <div class="modal-body">
        <?php if ($dlgErr['switch'] !== ''): ?>
            <div class="message error"><?= e($dlgErr['switch']) ?></div>
        <?php endif; ?>

        <p style="margin:0 0 6px;font-size:14px;">
            Signed in as <strong><?= e($adminName) ?></strong> (<?= e($_SESSION['admin_username'] ?? '') ?>)
        </p>

        <?php if (!empty($knownAccounts)): ?>
            <div class="acct-list">
                <?php foreach ($knownAccounts as $acct): ?>
                    <button type="button" class="acct-btn" data-switch-user="<?= e($acct['username']) ?>">
                        <span class="avatar"><?= e(strtoupper(mb_substr((string)$acct['name'], 0, 1))) ?></span>
                        <span><?= e($acct['name']) ?><small><?= e($acct['role']) ?> · <?= e($acct['username']) ?></small></span>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form class="form-stack" method="POST" action="admin.php?view=<?= e($view) ?>">
            <input type="hidden" name="action" value="switch_account">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

            <label for="swLogin">Username or email</label>
            <input type="text" id="swLogin" name="login" autocomplete="username" required>

            <label for="swPass">Password</label>
            <input type="password" id="swPass" name="password" autocomplete="current-password" required>

            <button class="btn-primary" type="submit">Switch account</button>
        </form>
    </div>
</dialog>

<?php if ($reopen !== ''): ?>
<script>window.REOPEN_DIALOG = <?= json_encode($reopen, JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<?php endif; ?>

<script>
(function () {
    // Mobile sidebar
    var body = document.body;
    var btn = document.getElementById('menuBtn');
    var backdrop = document.getElementById('backdrop');
    if (btn) btn.addEventListener('click', function () { body.classList.toggle('nav-open'); });
    if (backdrop) backdrop.addEventListener('click', function () { body.classList.remove('nav-open'); });

    // Reservation modals
    document.querySelectorAll('[data-open]').forEach(function (b) {
        b.addEventListener('click', function () {
            var d = document.getElementById(b.getAttribute('data-open'));
            if (d && d.showModal) d.showModal();
        });
    });
    document.querySelectorAll('dialog.modal').forEach(function (d) {
        d.addEventListener('click', function (e) { if (e.target === d) d.close(); });
        var c = d.querySelector('[data-close]');
        if (c) c.addEventListener('click', function () { d.close(); });
    });

    // Reservation filters
    var search = document.getElementById('resSearch');
    var status = document.getElementById('resStatus');
    var when   = document.getElementById('resWhen');
    var rows   = document.querySelectorAll('#resBody tr');
    var empty  = document.getElementById('resEmpty');

    function applyFilters() {
        var q = search ? search.value.trim().toLowerCase() : '';
        var s = status ? status.value : '';
        var w = when ? when.value : '';
        var shown = 0;
        rows.forEach(function (r) {
            var ok = (!q || r.dataset.search.indexOf(q) !== -1)
                  && (!s || r.dataset.status === s)
                  && (!w || r.dataset.when === w);
            r.hidden = !ok;
            if (ok) shown++;
        });
        if (empty) empty.hidden = shown !== 0;
    }

    [search, status, when].forEach(function (el) {
        if (el) el.addEventListener('input', applyFilters);
    });
    if (rows.length) applyFilters();

    // Close the mobile menu when a dialog opens from the sidebar
    document.querySelectorAll('[data-open]').forEach(function (b) {
        b.addEventListener('click', function () { body.classList.remove('nav-open'); });
    });

    // Switch Account: tap a remembered account to fill in its username
    document.querySelectorAll('[data-switch-user]').forEach(function (b) {
        b.addEventListener('click', function () {
            var l = document.getElementById('swLogin');
            var p = document.getElementById('swPass');
            if (l) l.value = b.getAttribute('data-switch-user');
            if (p) p.focus();
        });
    });

    // Re-open an account dialog after a validation error
    if (window.REOPEN_DIALOG) {
        var rd = document.getElementById(window.REOPEN_DIALOG);
        if (rd && rd.showModal) rd.showModal();
    }
})();
</script>
</body>
</html>