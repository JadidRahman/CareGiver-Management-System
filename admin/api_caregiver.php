<?php
// admin/api_caregiver.php
session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'forbidden']);
  exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'bad id']);
  exit;
}

try {
  // main profile
  $stmt = $con->prepare("SELECT * FROM caregivers WHERE id=? LIMIT 1");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $cg = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$cg) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not found']);
    exit;
  }

  // languages
  $langs = [];
  if ($res = $con->query("SELECT language FROM caregiver_languages WHERE caregiver_id=".(int)$id)) {
    while ($r = $res->fetch_assoc()) { $langs[] = $r['language']; }
  }

  // skills (map keys -> labels)
  $labels = [
    "medication_support"=>"Medication support","wound_care"=>"Wound care","catheter_care"=>"Catheter care",
    "stoma_care"=>"Stoma care","feeding_support"=>"Feeding support","mobility_transfer"=>"Mobility & transfer",
    "hygiene_toileting"=>"Hygiene & toileting","dementia_support"=>"Dementia/behavior support",
    "vitals_monitoring"=>"Vitals monitoring","physiotherapy_assist"=>"Physiotherapy assist",
    "child_care"=>"Child care","companionship"=>"Companionship","other"=>"Other"
  ];
  $skills = [];
  if ($res = $con->query("SELECT skill_key FROM caregiver_skills WHERE caregiver_id=".(int)$id)) {
    while ($r = $res->fetch_assoc()) {
      $k = $r['skill_key'];
      $skills[] = ['key'=>$k, 'label'=> ($labels[$k] ?? $k)];
    }
  }

  // availability
  $avail = [];
  if ($res = $con->query("SELECT dow,start_time,end_time FROM caregiver_availability WHERE caregiver_id=".(int)$id." ORDER BY dow ASC")) {
    while ($r = $res->fetch_assoc()) { $avail[] = $r; }
  }

  // references
  $refs = [];
  if ($res = $con->query("SELECT ref_name,ref_relation,ref_phone,ref_email FROM caregiver_references WHERE caregiver_id=".(int)$id)) {
    while ($r = $res->fetch_assoc()) { $refs[] = $r; }
  }

  // certifications
  $certs = [];
  if ($res = $con->query("SELECT cert_name,cert_org,cert_id,valid_till,file_path FROM caregiver_certifications WHERE caregiver_id=".(int)$id)) {
    while ($r = $res->fetch_assoc()) { $certs[] = $r; }
  }

  echo json_encode([
    'ok' => true,
    'caregiver' => $cg,
    'languages' => $langs,
    'skills' => $skills,
    'availability' => $avail,
    'references' => $refs,
    'certifications' => $certs
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'server', 'detail' => $e->getMessage()]);
}
