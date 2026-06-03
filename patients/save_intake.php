<?php
session_start();
require_once __DIR__ . '/../config.php'; // must define $con (mysqli) and url()

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: ' . url('patients/intake.php'));
  exit;
}

/* ---------- helpers ---------- */
function s($k, $default = null) {
  return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $default;
}
function sa($k) {
  return isset($_POST[$k]) && is_array($_POST[$k]) ? $_POST[$k] : [];
}
function toNull($v) { $v = trim((string)$v); return ($v === '' ? null : $v); }
function toInt($v, $min = null, $max = null) {
  if ($v === '' || $v === null) return null;
  $n = (int)$v;
  if ($min !== null && $n < $min) $n = $min;
  if ($max !== null && $n > $max) $n = $max;
  return $n;
}
function pickEnum($val, array $allowed, $map = []) {
  if ($val === null || $val === '') return null;
  $v = strtolower(trim((string)$val));
  if (isset($map[$v])) $v = $map[$v];
  return in_array($v, $allowed, true) ? $v : null;
}
function safe_name($s){ return preg_replace('/[^a-zA-Z0-9_.-]/', '_', $s); }

/* ---------- who is the patient? (by logged-in user) ---------- */
$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
  http_response_code(403);
  echo "<p>Not authorized.</p>";
  exit;
}
$st = $con->prepare("SELECT id FROM patients WHERE user_id = ? LIMIT 1");
$st->bind_param("i", $uid);
$st->execute();
$patient = $st->get_result()->fetch_assoc();
$st->close();
if (!$patient) {
  http_response_code(400);
  echo "<p>No patient record found for this account. Please register again.</p>";
  exit;
}
$pid = (int)$patient['id'];

/* ---------- map enums to your schema (from patients.sql) ---------- */
$gender = pickEnum(s('gender'), ['male','female','other']);

$mobility = pickEnum(s('mobility'),
  ['independent','assist','bedbound'],
  ['needs_assistance' => 'assist']
);

$swallowing = pickEnum(s('swallowing'),
  ['none','soft','ryles','peg'],
  [
    'soft_diet' => 'soft',
    'ryles_peg' => 'ryles',       // if your UI sends combined, keep ryles
    'ryles tube' => 'ryles',
    'peg tube'  => 'peg'
  ]
);

$fall_risk = pickEnum(s('fall_risk'),
  ['low','medium','high']
);

$bedsore_risk = pickEnum(s('bedsore_risk'),
  ['none','at_risk','existing'],
  [
    'low'    => 'none',
    'medium' => 'at_risk',
    'high'   => 'existing'
  ]
);

$shift_type = pickEnum(s('shift_type'),
  ['day','night','24h','hourly'],
  ['24-hr' => '24h', '24hr' => '24h']
);

$cg_gender_pref = pickEnum(s('caregiver_gender_pref'),
  ['any','male','female'],
  ['' => 'any']
);

$payment_mode = pickEnum(s('payment_mode'), ['cash','bank','mfs']);

$registrant_relation = pickEnum(s('registrant_relation'),
  ['self','spouse','parent','sibling','child','guardian','friend','other'],
  ['relative'=>'other']
);

$registrant_pref = pickEnum(s('registrant_preferred_contact'),
  ['call','sms','whatsapp','email'],
  ['whatsApp'=>'whatsapp','phone'=>'call']
);

/* ---------- collect / shape arrays to JSON ---------- */
$equipment_json = null;
$eq = sa('equipment');
if (!$eq && isset($_POST['equipment_json'])) {
  $decoded = json_decode($_POST['equipment_json'], true);
  if (is_array($decoded)) $eq = $decoded;
}
if ($eq) $equipment_json = json_encode(array_values(array_unique(array_map('strtolower',$eq))), JSON_UNESCAPED_UNICODE);

$comorbidities_json = null;
$co = sa('comorbidities');
if (!$co && isset($_POST['comorbidities_json'])) {
  $decoded = json_decode($_POST['comorbidities_json'], true);
  if (is_array($decoded)) $co = $decoded;
}
if ($co) $comorbidities_json = json_encode(array_values(array_unique(array_map('strtolower',$co))), JSON_UNESCAPED_UNICODE);

$medications_json = null;
if (isset($_POST['medication_name'])) {
  $names = $_POST['medication_name'] ?? [];
  $doses = $_POST['medication_dose'] ?? [];
  $freqs = $_POST['medication_freq'] ?? [];
  $rows = [];
  $cnt = max(count($names), count($doses), count($freqs));
  for ($i = 0; $i < $cnt; $i++) {
    $n = trim((string)($names[$i] ?? ''));
    if ($n === '') continue;
    $rows[] = [
      'name'      => $n,
      'dose'      => trim((string)($doses[$i] ?? '')),
      'frequency' => trim((string)($freqs[$i] ?? '')),
    ];
  }
  if ($rows) $medications_json = json_encode($rows, JSON_UNESCAPED_UNICODE);
}

$adls_json = null;
$adls = sa('adls');
if ($adls) $adls_json = json_encode(array_values(array_unique(array_map('strtolower',$adls))), JSON_UNESCAPED_UNICODE);

$infection_risks_json = null;
$inf = sa('infection_risks');
if ($inf) $infection_risks_json = json_encode(array_values(array_unique(array_map('strtolower',$inf))), JSON_UNESCAPED_UNICODE);

$behavior_risks_json = null;
$br = sa('behavior_risks');
if (!$br && isset($_POST['behavior_risks_json'])) {
  $decoded = json_decode($_POST['behavior_risks_json'], true);
  if (is_array($decoded)) $br = $decoded;
}
if ($br) $behavior_risks_json = json_encode(array_values(array_unique($br)), JSON_UNESCAPED_UNICODE);

/* ---------- numeric, booleans, dates ---------- */
$lift_available = isset($_POST['lift_available']) ? 1 : (toInt(s('lift_available')) ? 1 : null);
$stairs_count   = toInt(s('stairs_count'), 0, 255); // TINYINT UNSIGNED
$height_cm      = toInt(s('height_cm'), 0, 400);
$weight_kg      = toInt(s('weight_kg'), 0, 400);
$bmi            = toNull(s('bmi'));
$pain_score     = toInt(s('pain_score'), 0, 10);
$hours_per_day  = toNull(s('hours_per_day'));
$days_per_week  = toNull(s('days_per_week'));

$recent_hosp_yn = strtolower(trim((string)s('recent_hosp_yn')));
$recent_hospitalization = ($recent_hosp_yn === 'yes' || $recent_hosp_yn === '1') ? 1 : 0;

$consent_data_privacy        = isset($_POST['consent_data_privacy']) ? 1 : 0;
$consent_treatment           = isset($_POST['consent_treatment']) ? 1 : 0;
$consent_home_visit          = isset($_POST['consent_home_visit']) ? 1 : 0;
$consent_photo               = isset($_POST['consent_photo']) ? 1 : 0;
$consent_emergency_escalation= isset($_POST['consent_emergency_escalation']) ? 1 : 0;
$consent_signed_by           = s('signed_by') ?: 'patient';
$consent_signed_name         = s('signed_name');
$consent_signed_relation     = s('signed_relation');
$consent_signed_at           = date('Y-m-d H:i:s');

/* ---------- basic text fields ---------- */
$full_name          = s('full_name');
$dob                = toNull(s('dob'));
$nid_passport       = s('nid_passport');
$marital_status     = s('marital_status');
$phone              = s('phone');
$email              = s('email');
$present_address    = s('present_address');
$permanent_address  = s('permanent_address');

$service_address    = s('service_address');
$landmark           = s('landmark');
$access_notes       = s('access_notes');

$emg_name           = s('emergency_name');
$emg_relation       = s('emergency_relation');
$emg_phone          = s('emergency_phone');

$doctor_name        = s('doctor_name');
$doctor_contact     = s('doctor_contact');

$continence         = s('continence'); // VARCHAR in your schema
$communication      = s('communication');
$communication_lang = s('communication_language');
$cognition          = s('cognition');
$bedsore_details    = s('bedsore_details');

$bp_str             = s('bp');   // keep as text
$pulse              = s('pulse');
$spo2               = s('spo2');
$rbs                = s('rbs');

$caregiver_type     = s('caregiver_type');
$start_date         = toNull(s('start_date'));
$duration_text      = s('duration_text');
$language_pref      = s('language_pref');
$religion_diet_prefs= s('religion_diet_prefs');
$privacy_notes      = s('privacy_notes');

$ppe_required       = isset($_POST['ppe_required']) ? 1 : 0;
$isolation_notes    = s('isolation_notes');

$payer_name         = s('payer_name');
$payer_phone        = s('payer_phone');
$payer_email        = s('payer_email');
$billing_address    = s('billing_address');
$insurance_provider = s('insurance_provider');
$insurance_policy_no= s('insurance_policy_no');
$budget_note        = s('budget_note');
$invoice_emails     = s('invoice_emails');

$registrant_name            = s('reg_name') ?: s('registrant_name');
$registrant_phone1          = s('reg_phone1') ?: s('registrant_phone1');
$registrant_phone2          = s('reg_phone2') ?: s('registrant_phone2');
$registrant_email           = s('reg_email')  ?: s('registrant_email');
$registrant_nid_passport    = s('reg_nid_passport') ?: s('registrant_nid_passport');
$registrant_address         = s('reg_address') ?: s('registrant_address');
$is_legal_guardian          = isset($_POST['reg_is_guardian']) || isset($_POST['is_legal_guardian']) ? 1 : 0;
$decision_maker_name        = s('decision_maker_name');
$decision_maker_phone       = s('decision_maker_phone');

/* ---------- file uploads ---------- */
$uploadDir = dirname(__DIR__).'/uploads/patients/'.$pid;
$relBase   = 'uploads/patients/'.$pid;
if (!is_dir($uploadDir)) {
  @mkdir($uploadDir, 0775, true);
  @file_put_contents($uploadDir.'/.htaccess', "php_flag engine off\n");
}
function save_upload($field, $targetNameBase, $dir, $relBase) {
  if (empty($_FILES[$field]['name'])) return null;
  $name = $_FILES[$field]['name'];
  $tmp  = $_FILES[$field]['tmp_name'];
  if (!is_uploaded_file($tmp)) return null;
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  $allowed = ['pdf','jpg','jpeg','png','webp','doc','docx'];
  if (!in_array($ext, $allowed, true)) return null;
  $file = $targetNameBase.'_'.time().'_'.safe_name($name);
  $dest = rtrim($dir,'/').'/'.$file;
  if (@move_uploaded_file($tmp, $dest)) {
    return rtrim($relBase,'/').'/'.$file;
  }
  return null;
}

$patient_id_doc_url     = save_upload('doc_patient_id',     'id_doc',        $uploadDir, $relBase);
$discharge_summary_url  = save_upload('doc_discharge',      'discharge',     $uploadDir, $relBase);
$medical_fitness_url    = save_upload('doc_medical_fitness','medical_fitness',$uploadDir, $relBase);
$patient_photo_url      = save_upload('doc_patient_photo',  'patient_photo', $uploadDir, $relBase);

/* multiple wound images */
$wound_images = [];
if (!empty($_FILES['doc_wound_photos']['name']) && is_array($_FILES['doc_wound_photos']['name'])) {
  $count = count($_FILES['doc_wound_photos']['name']);
  for ($i = 0; $i < $count; $i++) {
    if (!is_uploaded_file($_FILES['doc_wound_photos']['tmp_name'][$i])) continue;
    $name = $_FILES['doc_wound_photos']['name'][$i];
    $tmp  = $_FILES['doc_wound_photos']['tmp_name'][$i];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) continue;
    $file = 'wound_'.time().'_'.$i.'_'.safe_name($name);
    $dest = $uploadDir.'/'.$file;
    if (@move_uploaded_file($tmp, $dest)) {
      $wound_images[] = $relBase.'/'.$file;
    }
  }
}
$wound_images_json = $wound_images ? json_encode($wound_images, JSON_UNESCAPED_UNICODE) : null;

/* ---------- build UPDATE (flattened into patients) ---------- */
$cols = [
  // identity
  'full_name' => $full_name,
  'dob' => $dob,
  'gender' => $gender,
  'nid_passport' => $nid_passport,
  'marital_status' => $marital_status,
  'phone' => $phone,
  'email' => $email,
  // addresses
  'present_address' => $present_address,
  'permanent_address' => $permanent_address,
  // emergency
  'emergency_contact_name' => $emg_name,
  'emergency_contact_relation' => $emg_relation,
  'emergency_contact_phone' => $emg_phone,
  // home context
  'service_address' => $service_address,
  'landmark' => $landmark,
  'lift_available' => $lift_available,
  'stairs_count' => $stairs_count,
  'access_notes' => $access_notes,
  'equipment_json' => $equipment_json,
  // medical
  'primary_dx' => s('primary_dx'),
  'comorbidities_json' => $comorbidities_json,
  'allergies' => s('allergies'),
  'medications_json' => $medications_json,
  'recent_hospitalization' => $recent_hospitalization,
  'recent_hosp_where' => s('recent_hosp_where') ?: s('recent_hosp_info'),
  'recent_hosp_when' => s('recent_hosp_when'),
  'recent_hosp_reason' => s('recent_hosp_reason'),
  'infection_risks_json' => $infection_risks_json,
  'doctor_name' => $doctor_name,
  'doctor_contact' => $doctor_contact,
  // functional
  'mobility' => $mobility,
  'adls_json' => $adls_json,
  'continence' => $continence,
  'swallowing' => $swallowing,
  'cognition' => $cognition,
  'communication' => $communication,
  'communication_language' => $communication_lang,
  'fall_risk' => $fall_risk,
  'bedsore_risk' => $bedsore_risk,
  'bedsore_details' => $bedsore_details,
  'behavior_risks_json' => $behavior_risks_json,
  // vitals
  'height_cm' => $height_cm,
  'weight_kg' => $weight_kg,
  'bmi' => $bmi,
  'bp' => $bp_str,
  'pulse' => $pulse,
  'spo2' => $spo2,
  'rbs' => $rbs,
  'pain_score' => $pain_score,
  // care req
  'caregiver_type' => $caregiver_type,
  'tasks_json' => (function(){
      $t = sa('tasks');
      if (!$t && isset($_POST['tasks_json'])) {
        $d = json_decode($_POST['tasks_json'], true);
        if (is_array($d)) $t = $d;
      }
      return $t ? json_encode(array_values(array_unique($t)), JSON_UNESCAPED_UNICODE) : null;
  })(),
  'start_date' => $start_date,
  'shift_type' => $shift_type,
  'hours_per_day' => $hours_per_day,
  'days_per_week' => $days_per_week,
  'duration_text' => $duration_text,
  'language_pref' => $language_pref,
  'caregiver_gender_pref' => $cg_gender_pref,
  'religion_diet_prefs' => $religion_diet_prefs,
  'privacy_notes' => $privacy_notes,
  'ppe_required' => $ppe_required,
  'isolation_notes' => $isolation_notes,
  // billing
  'payer_name' => $payer_name,
  'payer_phone' => $payer_phone,
  'payer_email' => $payer_email,
  'billing_address' => $billing_address,
  'payment_mode' => $payment_mode,
  'insurance_provider' => $insurance_provider,
  'insurance_policy_no' => $insurance_policy_no,
  'budget_note' => $budget_note,
  'invoice_emails' => $invoice_emails,
  // registrant (snapshot)
  'registrant_name' => $registrant_name,
  'registrant_relation' => $registrant_relation,
  'registrant_phone1' => $registrant_phone1,
  'registrant_phone2' => $registrant_phone2,
  'registrant_email'  => $registrant_email,
  'registrant_nid_passport' => $registrant_nid_passport,
  'registrant_address' => $registrant_address,
  'registrant_preferred_contact' => $registrant_pref,
  'is_legal_guardian' => $is_legal_guardian,
  'decision_maker_name' => $decision_maker_name,
  'decision_maker_phone' => $decision_maker_phone,
  // files (only set if a new file arrived)
  'patient_id_doc_url'    => $patient_id_doc_url,
  'discharge_summary_url' => $discharge_summary_url,
  'medical_fitness_url'   => $medical_fitness_url,
  'patient_photo_url'     => $patient_photo_url,
  'wound_images_json'     => $wound_images_json,
  // consents
  'consent_data_privacy'        => $consent_data_privacy,
  'consent_treatment'           => $consent_treatment,
  'consent_home_visit'          => $consent_home_visit,
  'consent_photo'               => $consent_photo,
  'consent_emergency_escalation'=> $consent_emergency_escalation,
  'consent_signed_by'           => $consent_signed_by,
  'consent_signed_name'         => $consent_signed_name,
  'consent_signed_relation'     => $consent_signed_relation,
  'consent_signed_at'           => $consent_signed_at,
  // workflow/audit
  'source' => 'web',
  'triage_priority' => 'routine',
  'case_owner_user_id' => $uid,
  'case_status' => 'new',
  'audit_json' => json_encode(['updated_by'=>'patient','user_id'=>$uid,'ts'=>date('c')], JSON_UNESCAPED_UNICODE),
  'updated_at' => date('Y-m-d H:i:s'),
];

/* If no new file was uploaded, avoid overwriting existing DB value with NULL */
foreach (['patient_id_doc_url','discharge_summary_url','medical_fitness_url','patient_photo_url','wound_images_json'] as $fcol) {
  if ($cols[$fcol] === null) unset($cols[$fcol]);
}

/* ---------- perform UPDATE ---------- */
$set = [];
$types = '';
$vals  = [];
foreach ($cols as $c => $v) {
  $set[] = "`$c` = ?";
  if (is_int($v)) $types .= 'i';
  elseif (is_float($v)) $types .= 'd';
  else $types .= 's';
  $vals[] = $v;
}
$sql = "UPDATE patients SET " . implode(', ', $set) . " WHERE id = ?";
$types .= 'i';
$vals[] = $pid;

$con->begin_transaction();
try {
  $st = $con->prepare($sql);
  $st->bind_param($types, ...$vals);
  if (!$st->execute()) throw new Exception($st->error);
  $st->close();
  $con->commit();

  header('Location: ' . url('patients/intake.php?saved=1'));
  exit;
} catch (Throwable $e) {
  $con->rollback();
  error_log('[save_intake] '.$e->getMessage());
  http_response_code(400);
  echo "<!doctype html><meta charset='utf-8'><div style='padding:20px;font-family:system-ui'>
        <h3>Could not submit intake</h3>
        <p>".htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')."</p>
        <p><a href='".htmlspecialchars(url('patients/intake.php'), ENT_QUOTES, 'UTF-8')."'>Go back</a></p>
      </div>";
  exit;
}
