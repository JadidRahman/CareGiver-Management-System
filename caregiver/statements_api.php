<?php
session_start();
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if (empty($_SESSION['user_role']) || $_SESSION['user_role'] !== 'caregiver') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$uid = (int) $_SESSION['user_id'];
// find caregiver id
$stmt = $con->prepare("SELECT id FROM caregivers WHERE user_id=? LIMIT 1");
$stmt->bind_param("i", $uid);
$stmt->execute();
$cgId = (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
$stmt->close();
if (!$cgId) {
    echo json_encode(['ok' => false, 'error' => 'no caregiver']);
    exit;
}

// last 6 months totals (by payments)
$labels = [];
$series = [];
for ($i = 5; $i >= 0; $i--) {
    $ym = (new DateTime("first day of -$i month"))->format('Y-m');
    $labels[] = (new DateTime($ym . '-01'))->format('M Y');

    $q = $con->prepare("
    SELECT COALESCE(SUM(p.amount),0) s
    FROM payments p
    LEFT JOIN service_assignments sa ON sa.id=p.assignment_id
    WHERE (p.caregiver_id=? OR sa.caregiver_id=?) 
      AND DATE_FORMAT(p.created_at,'%Y-%m')=? 
      AND p.status IN ('paid','completed','success')
  ");
    $q->bind_param("iis", $cgId, $cgId, $ym);
    $q->execute();
    $series[] = (float) ($q->get_result()->fetch_row()[0] ?? 0);
    $q->close();
}

// statements (latest 30)
$rows = [];
$sql = "
  SELECT p.id, p.created_at, p.amount, p.status, p.method, p.reference,
         COALESCE(pa.full_name, CONCAT('Patient #',p.patient_id)) patient
  FROM payments p
  LEFT JOIN service_assignments sa ON sa.id=p.assignment_id
  LEFT JOIN patients pa ON pa.id = COALESCE(p.patient_id, sa.patient_id)
  WHERE (p.caregiver_id=? OR sa.caregiver_id=?)
  ORDER BY p.created_at DESC
  LIMIT 30";
$q = $con->prepare($sql);
$q->bind_param("ii", $cgId, $cgId);
$q->execute();
$r = $q->get_result();
while ($row = $r->fetch_assoc())
    $rows[] = $row;
$q->close();

echo json_encode(['ok' => true, 'labels' => $labels, 'series' => $series, 'statements' => $rows]);
