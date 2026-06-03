<?php
session_start();
require_once __DIR__ . '/../config.php';
if (empty($_SESSION['user_role']) || $_SESSION['user_role'] !== 'caregiver') {
    header('Location: ' . url('login.php'));
    exit;
}
$uid = (int) $_SESSION['user_id'];
$stmt = $con->prepare("SELECT id FROM caregivers WHERE user_id=? LIMIT 1");
$stmt->bind_param("i", $uid);
$stmt->execute();
$cgId = (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
$stmt->close();
if (!$cgId) {
    die('No caregiver');
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=earnings_' . date('Ymd_His') . '.csv');

$out = fopen('php://output', 'w');
fputcsv($out, ['Date', 'Patient', 'Amount', 'Status', 'Method', 'Reference', 'Payment ID']);

$sql = "
  SELECT p.id, p.created_at, p.amount, p.status, p.method, p.reference,
         COALESCE(pa.full_name, CONCAT('Patient #',p.patient_id)) patient
  FROM payments p
  LEFT JOIN service_assignments sa ON sa.id=p.assignment_id
  LEFT JOIN patients pa ON pa.id = COALESCE(p.patient_id, sa.patient_id)
  WHERE (p.caregiver_id=? OR sa.caregiver_id=?)
  ORDER BY p.created_at DESC";
$q = $con->prepare($sql);
$q->bind_param("ii", $cgId, $cgId);
$q->execute();
$r = $q->get_result();
while ($row = $r->fetch_assoc()) {
    fputcsv($out, [
        (new DateTime($row['created_at']))->format('Y-m-d'),
        $row['patient'],
        $row['amount'],
        $row['status'],
        $row['method'],
        $row['reference'],
        $row['id']
    ]);
}
fclose($out);
