<?php
// patients/ajax_check_email.php
require_once __DIR__ . '/../config.php'; // must provide $con (mysqli)

header('Content-Type: application/json; charset=utf-8');

$exists = false;
$email  = isset($_GET['email']) ? trim($_GET['email']) : '';

if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    if (isset($con) && method_exists($con, 'set_charset')) {
        @$con->set_charset('utf8mb4');
    }
    if ($stmt = $con->prepare("SELECT id FROM users WHERE email = ? LIMIT 1")) {
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
    }
}

echo json_encode(['exists' => $exists]);
