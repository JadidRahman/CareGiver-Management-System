<?php
session_start();
require_once __DIR__ . '/../config.php'; // $con + url()

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (isset($con) && method_exists($con, 'set_charset')) {
    @$con->set_charset('utf8mb4');
}

/* ---------- helpers ---------- */
function safe_str($s){ return trim((string)($s ?? '')); }
function table_exists(mysqli $con, string $table): bool{
  $sql="SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? LIMIT 1";
  $st=$con->prepare($sql); $st->bind_param("s",$table); $st->execute();
  $ok=$st->get_result()->num_rows>0; $st->close(); return $ok;
}
function column_exists(mysqli $con, string $table, string $column): bool{
  $sql="SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
  $st=$con->prepare($sql); $st->bind_param("ss",$table,$column); $st->execute();
  $ok=$st->get_result()->num_rows>0; $st->close(); return $ok;
}
function get_col_meta(mysqli $con, string $table, string $column): array {
  $sql="SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
  $st=$con->prepare($sql); $st->bind_param("ss",$table,$column); $st->execute();
  $res=$st->get_result()->fetch_assoc() ?: [];
  $st->close(); return $res;
}
function safe_json($a){ return json_encode($a, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
function back_err($msg){
  error_log('[register_save] '.$msg);
  $dest = url('patients/register.php') . '?err=' . rawurlencode($msg);
  if (!headers_sent()) { header('Location: '.$dest); exit; }
  echo '<script>location.href='.json_encode($dest).';</script>'; exit;
}
/* map free-text to your ENUM('spouse','parent','sibling','child','friend','legal_guardian','other') */
function normalize_relation(string $input, bool $isRelative): string {
  if (!$isRelative) return 'self';
  $x = strtolower(trim($input));
  $map = [
    'father'=>'parent','mother'=>'parent','dad'=>'parent','mom'=>'parent',
    'husband'=>'spouse','wife'=>'spouse',
    'brother'=>'sibling','sister'=>'sibling',
    'son'=>'child','daughter'=>'child',
    'guardian'=>'legal_guardian','legal guardian'=>'legal_guardian','lg'=>'legal_guardian','caregiver'=>'legal_guardian'
  ];
  $x = $map[$x] ?? $x;
  $allowed = ['self','spouse','parent','sibling','child','friend','legal_guardian','other'];
  return in_array($x, $allowed, true) ? $x : 'other';
}
function normalize_pref_contact(?string $input): ?string {
  $x = strtolower(trim((string)$input));
  if ($x==='') return null;
  $map = ['phone'=>'call','voice'=>'call','text'=>'sms','message'=>'sms','msg'=>'sms','whatsap'=>'whatsapp','wa'=>'whatsapp'];
  $x = $map[$x] ?? $x;
  $allowed = ['call','sms','whatsapp','email'];
  return in_array($x,$allowed,true) ? $x : null;
}
function normalize_gender(?string $g): ?string {
  $g = strtolower(trim((string)$g));
  if ($g==='') return null;
  return in_array($g,['male','female','other'],true) ? $g : null;
}
/** small helper to build INSERTs that tolerate schema differences */
function insert_dynamic(mysqli $con, string $table, array $data, array $ints=[]){
  $cols=[]; $qs=[]; $types=''; $vals=[];
  foreach($data as $k=>$v){
    if (!column_exists($con,$table,$k)) continue;
    $cols[]=$k; $qs[]='?';
    if (in_array($k,$ints,true)) { $types.='i'; $vals[] = ($v===null? null : (int)$v); }
    else { $types.='s'; $vals[] = ($v===null? null : (string)$v); }
  }
  if (!$cols) return 0;
  $sql="INSERT INTO {$table} (".implode(',',$cols).") VALUES (".implode(',',$qs).")";
  $st=$con->prepare($sql);
  $st->bind_param($types, ...$vals);
  $st->execute();
  $id=(int)$st->insert_id;
  $st->close();
  return $id;
}

/* ---------- CSRF ---------- */
if (empty($_POST['csrf']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf'])) {
  back_err('Invalid request. Please reload the page and try again.');
}

/* ---------- inputs ---------- */
$regRole = ($_POST['reg_role'] ?? 'self'); // self | relative
$isRelative = ($regRole === 'relative');

$registrant_name     = safe_str($_POST['registrant_name'] ?? '');
$registrant_relation = safe_str($_POST['registrant_relation'] ?? '');
$registrant_phone1   = safe_str($_POST['registrant_phone1'] ?? '');
$registrant_phone2   = safe_str($_POST['registrant_phone2'] ?? '');
$registrant_email    = safe_str($_POST['registrant_email'] ?? '');
$registrant_address  = safe_str($_POST['registrant_address'] ?? '');
$preferred_contact   = normalize_pref_contact($_POST['preferred_contact'] ?? '');
$is_legal_guardian   = !empty($_POST['is_legal_guardian']) ? 1 : 0;

$patient_name        = safe_str($_POST['patient_name'] ?? '');
$patient_dob         = safe_str($_POST['patient_dob'] ?? '');
$patient_gender      = normalize_gender($_POST['patient_gender'] ?? '');
$patient_phone       = safe_str($_POST['patient_phone'] ?? '');
$service_address     = safe_str($_POST['service_address'] ?? '');

$account_email       = safe_str($_POST['account_email'] ?? '');
$account_password    = (string)($_POST['account_password'] ?? '');
$account_password2   = (string)($_POST['account_password2'] ?? '');
$consent             = !empty($_POST['consent']) ? 1 : 0;

/* ---------- validation ---------- */
if (!$patient_name)      back_err('Please enter the patient’s full name.');
if (!$service_address)   back_err('Please enter the service address.');
if (!$account_email || !filter_var($account_email, FILTER_VALIDATE_EMAIL)) back_err('Please enter a valid email address.');
if (strlen($account_password) < 6) back_err('Password must be at least 6 characters.');
if ($account_password !== $account_password2) back_err('Passwords do not match.');
if (!$consent) back_err('Please accept the consent to continue.');

/* mirror registrant if "I am the patient" */
if (!$isRelative) {
  $registrant_name     = $patient_name;
  $registrant_relation = 'self';
  $registrant_email    = $account_email ?: $registrant_email;
  $is_legal_guardian   = 1;
}
$registrant_relation = normalize_relation($registrant_relation, $isRelative);

/* ---------- preflight ---------- */
if (!table_exists($con,'users') || !table_exists($con,'patients')) back_err('System setup incomplete (missing core tables).');
if (!column_exists($con,'patients','user_id')) back_err('System setup incomplete (patients.user_id missing).');

$passCol = column_exists($con,'users','password_hash') ? 'password_hash'
        : (column_exists($con,'users','password') ? 'password' : null);
if (!$passCol) back_err('users table missing a password column (password or password_hash).');

$meta = get_col_meta($con,'users',$passCol);
$needLen = 60; $maxLen = (int)($meta['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
if ($maxLen && $maxLen < $needLen) back_err("users.$passCol is too short (max $maxLen). Make it VARCHAR(255).");

/* unique email */
if (column_exists($con,'users','email')) {
  $st = $con->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
  $st->bind_param("s",$account_email); $st->execute();
  if ($st->get_result()->fetch_row()) { $st->close(); back_err('An account already exists with that email. Please log in.'); }
  $st->close();
}

/* ---------- create ---------- */
try {
  $con->begin_transaction();

  /* users row */
  $userCols = [];
  if (column_exists($con,'users','role'))       $userCols['role'] = 'patient';
  if (column_exists($con,'users','email'))      $userCols['email'] = $account_email;

  // Choose a status that allows login immediately: prefer 'verified' if present, else 'active'
  if (column_exists($con,'users','status')) {
    $statusMeta = get_col_meta($con,'users','status');
    $enum = (string)($statusMeta['COLUMN_TYPE'] ?? '');
    $userCols['status'] = (strpos($enum, "'verified'") !== false) ? 'verified' : 'active';
  }

  if (column_exists($con,'users','created_at')) $userCols['created_at'] = date('Y-m-d H:i:s');

  // mark email as verified now (no OTP flow)
  if (column_exists($con,'users','email_verified_at')) {
    $userCols['email_verified_at'] = date('Y-m-d H:i:s');
  }

  $userCols[$passCol] = password_hash($account_password, PASSWORD_BCRYPT);

  $sql = "INSERT INTO users (".implode(',', array_keys($userCols)).") VALUES (".implode(',', array_fill(0,count($userCols),'?')).")";
  $st  = $con->prepare($sql);
  $types = ''; $vals=[];
  foreach($userCols as $v){ $types .= is_int($v) ? 'i':'s'; $vals[]=$v; }
  $st->bind_param($types, ...$vals);
  $st->execute();
  $user_id = (int)$st->insert_id; $st->close();

  /* patients row */
  $pCols = ['user_id' => $user_id];

  if (column_exists($con,'patients','full_name'))        $pCols['full_name'] = $patient_name;
  if (column_exists($con,'patients','name') && !isset($pCols['full_name'])) $pCols['name'] = $patient_name;

  if (column_exists($con,'patients','email'))            $pCols['email'] = $account_email;
  if (column_exists($con,'patients','phone'))            $pCols['phone'] = $patient_phone;

  if ($patient_dob !== '' && column_exists($con,'patients','dob'))      $pCols['dob'] = $patient_dob;
  if ($patient_gender !== null && column_exists($con,'patients','gender')) $pCols['gender'] = $patient_gender;

  // care location
  if (column_exists($con,'patients','service_address'))  $pCols['service_address'] = $service_address;
  if (column_exists($con,'patients','present_address'))  $pCols['present_address'] = $service_address;

  // registrant snapshot
  $flatRegistrant = [
    'registrant_name'              => $registrant_name,
    'registrant_relation'          => $registrant_relation,
    'registrant_phone1'            => $registrant_phone1,
    'registrant_phone2'            => $registrant_phone2,
    'registrant_email'             => $registrant_email,
    'registrant_address'           => $registrant_address,
    'registrant_preferred_contact' => $preferred_contact, // may be NULL
    'is_legal_guardian'            => $is_legal_guardian,
  ];
  foreach($flatRegistrant as $col=>$val){
    if (!column_exists($con,'patients',$col)) continue;
    if ($col === 'registrant_preferred_contact' && $val === null) continue; // avoid ENUM truncation
    $pCols[$col] = $val;
  }

  if (column_exists($con,'patients','status'))          $pCols['status'] = 'active';
  if (column_exists($con,'patients','case_status'))     $pCols['case_status'] = 'new';
  if (column_exists($con,'patients','source'))          $pCols['source'] = 'web';
  if (column_exists($con,'patients','triage_priority')) $pCols['triage_priority'] = 'routine';
  if (column_exists($con,'patients','created_at'))      $pCols['created_at'] = date('Y-m-d H:i:s');

  $sql = "INSERT INTO patients (".implode(',', array_keys($pCols)).") VALUES (".implode(',', array_fill(0, count($pCols), '?')).")";
  $st  = $con->prepare($sql);
  $types=''; $vals=[];
  foreach($pCols as $col=>$val){
    if ($col==='user_id' || $col==='is_legal_guardian'){ $types.='i'; $vals[]=(int)$val; }
    else { $types.='s'; $vals[]=$val; }
  }
  $st->bind_param($types, ...$vals);
  $st->execute();
  $patient_id = (int)$st->insert_id; $st->close();

  // Optional: keep small context table in sync if it exists
  if (table_exists($con,'patient_care_context') && column_exists($con,'patient_care_context','patient_id')) {
    $st2 = $con->prepare("INSERT INTO patient_care_context (patient_id, service_address) VALUES (?, ?) ON DUPLICATE KEY UPDATE service_address=VALUES(service_address)");
    $st2->bind_param("is", $patient_id, $service_address);
    $st2->execute(); $st2->close();
  }

  $con->commit();

  /* ---------- Post-commit notifications ---------- */
  $now = date('Y-m-d H:i:s');

  // A) Admin bell (admin_notifications)
  if (table_exists($con,'admin_notifications')) {
    $meta = [
      'patient_id'     => $patient_id,
      'patient_name'   => $patient_name,
      'registrant'     => [
        'name' => $registrant_name,
        'relation' => $registrant_relation,
        'phone' => $registrant_phone1,
        'email' => $registrant_email,
        'is_legal_guardian' => (int)$is_legal_guardian
      ],
      'is_relative'    => (int)$isRelative,
      'phone'          => $patient_phone,
      'email'          => $account_email,
      'source'         => 'web'
    ];
    $title = 'New patient registration';
    $by    = $isRelative ? (" by {$registrant_name} ({$registrant_relation})") : ' (self)';
    $body  = $patient_name . $by;

    insert_dynamic($con, 'admin_notifications', [
      'type'          => 'patient_register',
      'title'         => $title,
      'body'          => $body,
      'actor_user_id' => $user_id,
      'actor_role'    => 'patient',
      'target_type'   => 'patient',
      'target_id'     => $patient_id,
      'meta_json'     => safe_json($meta),
      'created_at'    => $now
    ], ['actor_user_id','target_id','is_read']);
  }

  // B) Activity feed item (admin_events) so it appears in dashboard -> Recent Activity
  if (table_exists($con,'admin_events')) {
    $detBits = [];
    $detBits[] = "New patient: {$patient_name}";
    if ($isRelative) $detBits[] = "Registrant: {$registrant_name} ({$registrant_relation})";
    if ($patient_phone) $detBits[] = "Phone: {$patient_phone}";
    $details = implode(' — ', $detBits);

    $evt = [
      'actor_user_id' => $user_id,
      'entity'        => 'patient',
      'action'        => 'registered',
      'details'       => $details,
      'created_at'    => $now
    ];
    if (column_exists($con,'admin_events','patient_id'))   $evt['patient_id'] = $patient_id;
    // (we intentionally skip caregiver_id; dashboard will still show "User #<id>" if no CG name)
    insert_dynamic($con, 'admin_events', $evt, ['actor_user_id','patient_id']);
  }

  // C) Optional welcome nudge to patient (user_notifications), if table exists
  if (table_exists($con,'user_notifications')) {
    $actionUrl = url('patients/intake.php'); // adjust if your intake route differs
    insert_dynamic($con, 'user_notifications', [
      'user_id'    => $user_id,
      'type'       => 'welcome',
      'title'      => 'Welcome to CGMS',
      'body'       => 'Please complete the Intake form to help us plan your care.',
      'action_url' => $actionUrl,
      'meta_json'  => safe_json(['patient_id'=>$patient_id]),
      'created_at' => $now
    ], ['user_id','is_read']);
  }

  // ----- Success: NO auto-login; show success + redirect to login -----
  unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['user_name']);
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

  $dest = url('login.php?ok=' . rawurlencode('Registration successful. Please log in.'));
  if (!headers_sent()) { header('Location: '.$dest); exit; }
  echo '<script>alert("Registration successful. Please log in."); location.href='.json_encode($dest).';</script>'; exit;

} catch (Throwable $e) {
  @ $con->rollback();
  error_log('[register_save][fatal] '.$e->getMessage());
  back_err('DB error: ' . $e->getMessage());
}
