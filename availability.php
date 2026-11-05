<?php

require_once __DIR__ . '/config.php';

$allowedOrigins = [
    'http://localhost',
    'http://127.0.0.1',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function validDay(string $d): bool
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
}

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d', strtotime('+12 months'));

if (!validDay($from) || !validDay($to) || $to < $from) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid date range']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT check_in_date, check_out_date, status
           FROM bookings
          WHERE status IN ('Pending','Confirmed')
            AND check_out_date > :from_date
            AND check_in_date  < :to_date
          ORDER BY check_in_date"
    );
    $stmt->execute([':from_date' => $from, ':to_date' => $to]);

    $out = [];
    foreach ($stmt as $row) {
        $out[] = [
            'check_in'  => $row['check_in_date'],
            'check_out' => $row['check_out_date'],
            'status'    => $row['status'],
        ];
    }

    echo json_encode([
        'updated'  => date('c'),
        'bookings' => $out,
    ]);
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load availability']);
}