<?php
/**
 * Caregiver Registration (CGMS)
 * Requires:
 *   - config.php (DB connection in $con + send_email())
 *   - tables (users, caregivers, caregiver_* , admin_notifications)
 *   - uploads/caregivers/ directory writable by PHP
 */
session_start();
require_once __DIR__ . '/config.php'; // must define $con (mysqli) + send_email()

/* ---- App meta (safe fallbacks; no undefined constants) ---- */
$appName = defined('APP_NAME') ? APP_NAME : 'CGMS';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$appUrl = defined('APP_URL') ? rtrim(APP_URL, '/') : ($scheme . '://' . $host . ($base ? $base : ''));

/* ---- CSRF ---- */
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

/* ---- Helpers ---- */
function safe($s)
{
  return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
function bd_phone_valid($p)
{
  return preg_match('/^(?:\+?88)?01[3-9]\d{8}$/', trim($p));
}
function pass_valid($p)
{
  return strlen($p) >= 8 && preg_match('/[A-Z]/', $p) && preg_match('/\d/', $p);
}
/** Save upload to $targetDir with MIME/size checks; returns absolute path or null */
function save_upload($field, $targetDir, $allowed, $maxBytes = 1048576)
{
  if (empty($_FILES[$field]['name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK)
    return null;
  if ($_FILES[$field]['size'] > $maxBytes)
    return null;
  if (!function_exists('finfo_open'))
    return null;
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime = finfo_file($finfo, $_FILES[$field]['tmp_name']);
  finfo_close($finfo);
  if (!in_array($mime, $allowed))
    return null;
  if (!is_dir($targetDir))
    @mkdir($targetDir, 0775, true);
  if (!is_writable($targetDir))
    @chmod($targetDir, 0775);
  $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
  $name = $field . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
  $dest = rtrim($targetDir, '/') . '/' . $name;
  if (move_uploaded_file($_FILES[$field]['tmp_name'], $dest))
    return $dest;
  return null;
}
/** check if a column exists (so we save area_preference only if present) */
function column_exists(mysqli $con, string $table, string $column): bool
{
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
  $st = $con->prepare($sql);
  $st->bind_param("ss", $table, $column);
  $st->execute();
  $ok = $st->get_result()->num_rows > 0;
  $st->close();
  return $ok;
}

/* ---- Static lists ---- */
$skillsList = [
  "medication_support" => "Medication support",
  "wound_care" => "Wound care",
  "catheter_care" => "Catheter care",
  "stoma_care" => "Stoma care",
  "feeding_support" => "Feeding support",
  "mobility_transfer" => "Mobility & transfer",
  "hygiene_toileting" => "Hygiene & toileting",
  "dementia_support" => "Dementia/behavior support",
  "vitals_monitoring" => "Vitals monitoring",
  "physiotherapy_assist" => "Physiotherapy assist",
  "child_care" => "Child care",
  "companionship" => "Companionship",
  "other" => "Other"
];
$languagesList = ["Bangla", "English", "Hindi", "Urdu", "Other"];
$districts = ["Dhaka", "Chattogram", "Khulna", "Rajshahi", "Sylhet", "Barishal", "Rangpur", "Mymensingh"];

/* ---- Handle submit ---- */
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? ''))
    $errors[] = "Invalid session token. Please reload the page.";

  $required = [
    'first_name',
    'last_name',
    'dob',
    'gender',
    'phone_primary',
    'email',
    'present_address',
    'present_district',
    'caregiver_type',
    'experience_years',
    'expected_rate_type',
    'expected_rate_amount',
    'availability_type',
    'highest_qualification',
    'emg_name',
    'emg_relation',
    'emg_phone',
    'password',
    'password_confirm'
  ];
  foreach ($required as $r) {
    if (empty($_POST[$r]))
      $errors[] = "Missing: $r";
  }

  if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL))
    $errors[] = "Invalid email format.";
  if (!empty($_POST['phone_primary']) && !bd_phone_valid($_POST['phone_primary']))
    $errors[] = "Invalid primary phone. Use 01XXXXXXXXX.";
  if (!empty($_POST['emg_phone']) && !bd_phone_valid($_POST['emg_phone']))
    $errors[] = "Invalid emergency phone. Use 01XXXXXXXXX.";
  if (!pass_valid($_POST['password'] ?? ''))
    $errors[] = "Password must be 8+ chars, include an uppercase letter and a number.";
  if (($_POST['password'] ?? '') !== ($_POST['password_confirm'] ?? ''))
    $errors[] = "Passwords do not match.";
  if (empty($_POST['languages']))
    $errors[] = "Choose at least one language.";
  if (empty($_POST['skills']))
    $errors[] = "Choose at least one skill.";
  if (empty($_POST['consent_data_processing']) || empty($_POST['consent_background_check']))
    $errors[] = "Please accept both consent checkboxes.";
  if (empty($_FILES['photo']['name']))
    $errors[] = "Profile photo is required.";

  // (Optional) check uploads dir
  if (!is_dir(__DIR__ . '/uploads'))
    @mkdir(__DIR__ . '/uploads', 0775, true);
  if (!is_dir(__DIR__ . '/uploads/caregivers'))
    @mkdir(__DIR__ . '/uploads/caregivers', 0775, true);
  if (!is_writable(__DIR__ . '/uploads/caregivers'))
    $errors[] = "Uploads folder not writable: /uploads/caregivers";

  if (!$errors) {
    $email = trim($_POST['email']);
    $stmt = $con->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
      $errors[] = "Email already exists. Try logging in.";
    }
    $stmt->close();
  }

  if (!$errors) {
    $con->begin_transaction();
    try {
      /* users */
      $role = 'caregiver';
      $status = 'pending';
      $passhash = password_hash($_POST['password'], PASSWORD_BCRYPT);
      $stmt = $con->prepare("INSERT INTO users(role,email,password_hash,status) VALUES (?,?,?,?)");
      $stmt->bind_param("ssss", $role, $email, $passhash, $status);
      $stmt->execute();
      $user_id = $stmt->insert_id;
      $stmt->close();

      /* gather fields */
      $first_name = trim($_POST['first_name']);
      $last_name = trim($_POST['last_name']);
      $dob = $_POST['dob'];
      $gender = $_POST['gender'];
      $nid_passport = $_POST['nid_passport'] ?? null;
      $blood_group = $_POST['blood_group'] ?? null;
      $phone_primary = $_POST['phone_primary'];
      $phone_alt = $_POST['phone_alt'] ?? null;

      $present_address = $_POST['present_address'];
      $present_district = $_POST['present_district'];

      // NEW: same-as-present toggle (server-side)
      $permanent_address = $_POST['permanent_address'] ?? null;
      $permanent_district = $_POST['permanent_district'] ?? null;
      if (!empty($_POST['same_as_present'])) {
        $permanent_address = $present_address;
        $permanent_district = $present_district;
      }

      $caregiver_type = $_POST['caregiver_type'];
      $experience_years = max(0, (int) $_POST['experience_years']);
      $expected_rate_type = $_POST['expected_rate_type'];
      $expected_rate_amount = max(0, (float) $_POST['expected_rate_amount']);
      $availability_type = $_POST['availability_type'];
      $can_live_in = isset($_POST['can_live_in']) ? 1 : 0;
      $notice_period_days = max(0, (int) ($_POST['notice_period_days'] ?? 0));
      $highest_qualification = $_POST['highest_qualification'];

      // NEW: Preferred Area(s)
      $area_preference = trim($_POST['area_preference'] ?? '');
      $hasAreaPrefCol = column_exists($con, 'caregivers', 'area_preference');

      $emg_name = $_POST['emg_name'];
      $emg_relation = $_POST['emg_relation'];
      $emg_phone = $_POST['emg_phone'];

      /* uploads (temp) */
      $tempDir = __DIR__ . '/uploads/caregivers/tmp_' . bin2hex(random_bytes(3));
      @mkdir($tempDir, 0775, true);
      $photo_path = save_upload('photo', $tempDir, ['image/jpeg', 'image/png'], (int) (1.5 * 1024 * 1024));
      if (!$photo_path)
        throw new Exception("Photo upload failed or invalid type/size.");
      // Allow PDF/JPG/PNG/DOC/DOCX
      $docMimes = ['image/jpeg', 'image/png', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
      $police_path = save_upload('police_verification_file', $tempDir, $docMimes, 2 * 1024 * 1024);
      $medical_path = save_upload('medical_fitness_file', $tempDir, $docMimes, 2 * 1024 * 1024);

      /* caregivers INSERT (adds area_preference only if column exists) */
      if ($hasAreaPrefCol) {
        $sql = "INSERT INTO caregivers
          (user_id,first_name,last_name,dob,gender,nid_passport,blood_group,phone_primary,phone_alt,email,
           present_address,present_district,permanent_address,permanent_district,caregiver_type,experience_years,
           expected_rate_type,expected_rate_amount,availability_type,can_live_in,notice_period_days,highest_qualification,
           emg_name,emg_relation,emg_phone,photo_path,police_verification_path,medical_fitness_path,area_preference,status)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmt = $con->prepare($sql);
        $types = 'i' . str_repeat('s', 14) . 'i' . 's' . 'd' . 's' . 'i' . 'i' . str_repeat('s', 9);
        $stmt->bind_param(
          $types,
          $user_id,
          $first_name,
          $last_name,
          $dob,
          $gender,
          $nid_passport,
          $blood_group,
          $phone_primary,
          $phone_alt,
          $email,
          $present_address,
          $present_district,
          $permanent_address,
          $permanent_district,
          $caregiver_type,
          $experience_years,
          $expected_rate_type,
          $expected_rate_amount,
          $availability_type,
          $can_live_in,
          $notice_period_days,
          $highest_qualification,
          $emg_name,
          $emg_relation,
          $emg_phone,
          $photo_path,
          $police_path,
          $medical_path,
          $area_preference,
          $status
        );
      } else {
        $sql = "INSERT INTO caregivers
          (user_id,first_name,last_name,dob,gender,nid_passport,blood_group,phone_primary,phone_alt,email,
           present_address,present_district,permanent_address,permanent_district,caregiver_type,experience_years,
           expected_rate_type,expected_rate_amount,availability_type,can_live_in,notice_period_days,highest_qualification,
           emg_name,emg_relation,emg_phone,photo_path,police_verification_path,medical_fitness_path,status)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmt = $con->prepare($sql);
        $types = 'i' . str_repeat('s', 14) . 'i' . 's' . 'd' . 's' . 'i' . 'i' . str_repeat('s', 8);
        $stmt->bind_param(
          $types,
          $user_id,
          $first_name,
          $last_name,
          $dob,
          $gender,
          $nid_passport,
          $blood_group,
          $phone_primary,
          $phone_alt,
          $email,
          $present_address,
          $present_district,
          $permanent_address,
          $permanent_district,
          $caregiver_type,
          $experience_years,
          $expected_rate_type,
          $expected_rate_amount,
          $availability_type,
          $can_live_in,
          $notice_period_days,
          $highest_qualification,
          $emg_name,
          $emg_relation,
          $emg_phone,
          $photo_path,
          $police_path,
          $medical_path,
          $status
        );
      }
      $stmt->execute();
      $caregiver_id = $stmt->insert_id;
      $stmt->close();

      /* move uploads to final */
      $finalDir = __DIR__ . "/uploads/caregivers/{$caregiver_id}";
      @mkdir($finalDir, 0775, true);
      foreach (glob($tempDir . '/*') as $f) {
        @rename($f, $finalDir . '/' . basename($f));
      }
      @rmdir($tempDir);

      $pp = file_exists($finalDir . '/' . basename($photo_path)) ? "uploads/caregivers/{$caregiver_id}/" . basename($photo_path) : null;
      $pl = $police_path ? "uploads/caregivers/{$caregiver_id}/" . basename($police_path) : null;
      $md = $medical_path ? "uploads/caregivers/{$caregiver_id}/" . basename($medical_path) : null;

      $upd = $con->prepare("UPDATE caregivers SET photo_path=?, police_verification_path=?, medical_fitness_path=? WHERE id=?");
      $upd->bind_param("sssi", $pp, $pl, $md, $caregiver_id);
      $upd->execute();
      $upd->close();

      /* languages */
      if (!empty($_POST['languages'])) {
        $ins = $con->prepare("INSERT INTO caregiver_languages (caregiver_id, language) VALUES (?,?)");
        foreach ($_POST['languages'] as $lang) {
          $l = trim($lang);
          if (!$l)
            continue;
          $ins->bind_param("is", $caregiver_id, $l);
          $ins->execute();
        }
        $ins->close();
      }
      /* skills */
      if (!empty($_POST['skills'])) {
        $ins = $con->prepare("INSERT INTO caregiver_skills (caregiver_id, skill_key) VALUES (?,?)");
        foreach ($_POST['skills'] as $sk) {
          $skey = trim($sk);
          if (!$skey)
            continue;
          $ins->bind_param("is", $caregiver_id, $skey);
          $ins->execute();
        }
        $ins->close();
      }
      /* availability */
      if (!empty($_POST['avail']) && is_array($_POST['avail'])) {
        $ins = $con->prepare("INSERT INTO caregiver_availability (caregiver_id,dow,start_time,end_time) VALUES (?,?,?,?)");
        foreach ($_POST['avail'] as $dow => $times) {
          $st = !empty($times['start']) ? $times['start'] . ":00" : null;
          $en = !empty($times['end']) ? $times['end'] . ":00" : null;
          $dowInt = (int) $dow;
          $ins->bind_param("iiss", $caregiver_id, $dowInt, $st, $en);
          $ins->execute();
        }
        $ins->close();
      }
      /* references */
      if (!empty($_POST['ref_name']) && is_array($_POST['ref_name'])) {
        $ins = $con->prepare("INSERT INTO caregiver_references (caregiver_id,ref_name,ref_relation,ref_phone,ref_email) VALUES (?,?,?,?,?)");
        $cnt = count($_POST['ref_name']);
        for ($i = 0; $i < $cnt; $i++) {
          $rn = trim($_POST['ref_name'][$i] ?? '');
          $rr = trim($_POST['ref_relation'][$i] ?? '');
          $rp = trim($_POST['ref_phone'][$i] ?? '');
          $re = trim($_POST['ref_email'][$i] ?? '');
          if (!$rn || !$rp)
            continue;
          $ins->bind_param("issss", $caregiver_id, $rn, $rr, $rp, $re);
          $ins->execute();
        }
        $ins->close();
      }
      /* certifications */
      if (!empty($_POST['cert_name']) && is_array($_POST['cert_name'])) {
        $ins = $con->prepare("INSERT INTO caregiver_certifications (caregiver_id,cert_name,cert_org,cert_id,valid_till,file_path) VALUES (?,?,?,?,?,?)");
        $cnt = count($_POST['cert_name']);
        for ($i = 0; $i < $cnt; $i++) {
          $cn = trim($_POST['cert_name'][$i] ?? '');
          if (!$cn)
            continue;
          $co = trim($_POST['cert_org'][$i] ?? '');
          $ci = trim($_POST['cert_id'][$i] ?? '');
          $vt = $_POST['cert_valid_till'][$i] ?? null;
          $filePath = null;
          if (!empty($_FILES['cert_file']['name'][$i])) {
            $key = 'cert_file_single';
            $_FILES[$key] = [
              'name' => $_FILES['cert_file']['name'][$i],
              'type' => $_FILES['cert_file']['type'][$i],
              'tmp_name' => $_FILES['cert_file']['tmp_name'][$i],
              'error' => $_FILES['cert_file']['error'][$i],
              'size' => $_FILES['cert_file']['size'][$i],
            ];
            $abs = save_upload($key, $finalDir, ['image/jpeg', 'image/png', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], 2 * 1024 * 1024);
            unset($_FILES[$key]);
            if ($abs)
              $filePath = "uploads/caregivers/{$caregiver_id}/" . basename($abs);
          }
          $ins->bind_param("isssss", $caregiver_id, $cn, $co, $ci, $vt, $filePath);
          $ins->execute();
        }
        $ins->close();
      }

      /* commit */
      $con->commit();

      /* ===== Post-commit ===== */
      // 1) OTP save
      $otp = strval(random_int(100000, 999999));
      $exp = (new DateTime('+15 minutes'))->format('Y-m-d H:i:s');
      $upd = $con->prepare("UPDATE users SET email_verification_code=?, email_verification_expires_at=? WHERE id=?");
      $upd->bind_param("ssi", $otp, $exp, $user_id);
      $upd->execute();
      $upd->close();

      // 2) Email OTP to caregiver
      $verifyLink = $appUrl . "/verify-email.php?uid=" . urlencode((string) $user_id);
      $emailHtml = "
        <h2>Verify your email</h2>
        <p>Hi " . safe($first_name) . ",</p>
        <p>Use this one-time code to verify your email:</p>
        <div style='font-size:24px;font-weight:bold;letter-spacing:3px'>{$otp}</div>
        <p>Or click this link to verify: <a href='{$verifyLink}'>{$verifyLink}</a></p>
        <p>This code expires in 15 minutes.</p>
        <hr><small>" . safe($appName) . "</small>";
      $sentOk = send_email($email, 'Verify your email – ' . $appName, $emailHtml);
      if (!$sentOk) {
        error_log('OTP email failed for ' . $email);
      }

      // 3) Admin dashboard notification (no email/SMS)
      try {
        $type = 'new_caregiver_signup';
        $title = 'New Caregiver Registration';
        $body = $first_name . ' ' . $last_name . ' (' . $caregiver_type . ', ' . $experience_years . ' yrs) signed up.';
        $meta = json_encode([
          'email' => $email,
          'phone' => $phone_primary,
          'rate' => $expected_rate_type . ' ' . $expected_rate_amount,
          'district' => $present_district
        ], JSON_UNESCAPED_UNICODE);
        $insn = $con->prepare("INSERT INTO admin_notifications (type,title,body,actor_user_id,actor_role,target_type,target_id,meta_json) VALUES (?,?,?,?,?,?,?,?)");
        $actor_role = 'caregiver';
        $target_type = 'caregiver';
        $insn->bind_param("sssissis", $type, $title, $body, $user_id, $actor_role, $target_type, $caregiver_id, $meta);
        $insn->execute();
        $insn->close();
      } catch (Throwable $t) {
        error_log('admin_notifications insert failed: ' . $t->getMessage());
      }

      // 4) Redirect to OTP screen (as before)
      $_SESSION['pending_email'] = $email;
      $_SESSION['pending_user_id'] = $user_id;
      header('Location: success-caregiver.php');
      exit;

    } catch (Exception $e) {
      $con->rollback();
      $errors[] = "Registration failed: " . $e->getMessage();
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title>Caregiver Registration | CGMS</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --pri: #1d4ed8;
      --acc: #06b6d4;
      --ink: #0f172a;
      --mut: #64748b;
      --glass: rgba(255, 255, 255, .72);
      --brd: rgba(15, 23, 42, .08);
    }

    body {
      font-family: 'Inter', system-ui;
      background:
        radial-gradient(900px 600px at 10% -10%, #e0f2fe 0%, transparent 60%),
        radial-gradient(900px 600px at 110% 10%, #e0fffb 0%, transparent 60%),
        #f8fafc;
      color: var(--ink);
    }

    .navbar {
      backdrop-filter: blur(10px);
      background: rgba(255, 255, 255, .8) !important;
      border-bottom: 1px solid rgba(15, 23, 42, .06);
    }

    .brand {
      font-weight: 800;
      background: linear-gradient(90deg, var(--pri), var(--acc));
      -webkit-background-clip: text;
      color: transparent;
    }

    .card-glass {
      background: var(--glass);
      border: 1px solid var(--brd);
      border-radius: 20px;
      box-shadow: 0 14px 40px rgba(2, 6, 23, .08);
    }

    .btn-primary {
      background: linear-gradient(90deg, var(--pri), #3b82f6);
      border: none;
      box-shadow: 0 10px 20px rgba(29, 78, 216, .18);
    }

    .btn-accent {
      background: linear-gradient(90deg, var(--acc), #22d3ee);
      border: none;
      color: #052e2b;
    }

    .stepper .step {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: #e2e8f0;
      color: #334155;
      font-weight: 700;
    }

    .stepper .step.active {
      background: var(--pri);
      color: #fff;
      box-shadow: 0 0 0 4px rgba(29, 78, 216, .18);
    }

    .form-control,
    .form-select {
      border-radius: 12px;
      border: 1px solid rgba(15, 23, 42, .12);
    }

    .badge-soft {
      background: rgba(29, 78, 216, .08);
      color: var(--pri);
      border: 1px solid rgba(29, 78, 216, .18);
    }

    .sticky-actions {
      position: sticky;
      bottom: 0;
      background: rgba(255, 255, 255, .9);
      backdrop-filter: blur(6px);
      border-top: 1px solid rgba(15, 23, 42, .08);
      padding: 12px;
      border-radius: 0 0 20px 20px;
    }

    .fade-up {
      opacity: 0;
      transform: translateY(14px);
      animation: fadeUp .6s ease forwards;
    }

    @keyframes fadeUp {
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .small-hint {
      font-size: .85rem;
      color: var(--mut);
    }

    .required:after {
      content: " *";
      color: #ef4444;
    }
  </style>
</head>

<body>
  <nav class="navbar navbar-expand-lg sticky-top">
    <div class="container">
      <a class="navbar-brand brand" href="index.php">CGMS</a>
    </div>
  </nav>

  <div class="container my-4">
    <div class="row justify-content-center">
      <div class="col-lg-10 col-xl-9">
        <div class="card card-glass p-3 p-md-4 fade-up">
          <div class="d-flex align-items-center justify-content-between">
            <h3 class="mb-0">Caregiver Registration</h3>
            <div class="stepper d-flex align-items-center gap-2">
              <span class="step step-idx step-1 active">1</span>
              <span class="step step-idx step-2">2</span>
              <span class="step step-idx step-3">3</span>
              <span class="step step-idx step-4">4</span>
            </div>
          </div>

          <?php if ($errors): ?>
            <div class="alert alert-danger mt-3">
              <strong>Fix the following:</strong>
              <ul class="mb-0"><?php foreach ($errors as $e)
                echo "<li>" . safe($e) . "</li>"; ?></ul>
            </div>
          <?php endif; ?>

          <form id="cgForm" method="post" enctype="multipart/form-data" class="mt-3" novalidate>
            <input type="hidden" name="csrf" value="<?php echo safe($csrf); ?>">

            <!-- STEP 1 -->
            <div class="form-step" data-step="1">
              <h5 class="mt-2">Personal & Contact</h5>
              <div class="row g-3 mt-1">
                <div class="col-md-6">
                  <label class="form-label required">First Name</label>
                  <input name="first_name" class="form-control" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label required">Last Name</label>
                  <input name="last_name" class="form-control" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label required">Date of Birth</label>
                  <input type="date" name="dob" class="form-control" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label required">Gender</label>
                  <select name="gender" class="form-select" required>
                    <option value="">Choose…</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                    <option value="other">Other</option>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Blood Group</label>
                  <select name="blood_group" class="form-select">
                    <option value="">Select</option>
                    <?php foreach (['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'] as $bg)
                      echo "<option>$bg</option>"; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label required">Primary Phone</label>
                  <input name="phone_primary" class="form-control" placeholder="01XXXXXXXXX" required>
                  <div class="small-hint">Bangladesh format</div>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Alternate Phone</label>
                  <input name="phone_alt" class="form-control" placeholder="Optional">
                </div>
                <div class="col-md-6">
                  <label class="form-label required">Email</label>
                  <input type="email" name="email" class="form-control" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label">NID / Passport</label>
                  <input name="nid_passport" class="form-control">
                </div>

                <div class="col-12">
                  <label class="form-label required">Present Address</label>
                  <textarea id="present_address" name="present_address" class="form-control" rows="2"
                    required></textarea>
                </div>
                <div class="col-md-6">
                  <label class="form-label required">Present District</label>
                  <select id="present_district" name="present_district" class="form-select" required>
                    <option value="">Choose…</option>
                    <?php foreach ($districts as $d)
                      echo "<option>$d</option>"; ?>
                  </select>
                </div>

                <!-- NEW: Same as present toggle -->
                <div class="col-12">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="sameAddr" name="same_as_present">
                    <label class="form-check-label" for="sameAddr">Permanent address same as present</label>
                  </div>
                </div>

                <div class="col-12">
                  <label class="form-label">Permanent Address</label>
                  <textarea id="permanent_address" name="permanent_address" class="form-control" rows="2"></textarea>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Permanent District</label>
                  <select id="permanent_district" name="permanent_district" class="form-select">
                    <option value="">Choose…</option>
                    <?php foreach ($districts as $d)
                      echo "<option>$d</option>"; ?>
                  </select>
                </div>

                <div class="col-md-6">
                  <label class="form-label required">Profile Photo (JPG/PNG, ≤1.5MB)</label>
                  <input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png" required>
                </div>
              </div>

              <div class="sticky-actions mt-3 d-flex justify-content-end">
                <button type="button" class="btn btn-primary next-step">Next</button>
              </div>
            </div>

            <!-- STEP 2 -->
            <div class="form-step d-none" data-step="2">
              <h5>Professional & Availability</h5>
              <div class="row g-3 mt-1">
                <div class="col-md-6">
                  <label class="form-label required">Caregiver Type</label>
                  <select name="caregiver_type" class="form-select" required>
                    <option value="">Choose…</option>
                    <option value="nurse">Nurse</option>
                    <option value="attendant">Attendant</option>
                    <option value="physiotherapist">Physiotherapist</option>
                    <option value="therapist">Therapist</option>
                    <option value="other">Other</option>
                  </select>
                </div>
                <div class="col-md-3">
                  <label class="form-label required">Experience (years)</label>
                  <input type="number" min="0" max="50" name="experience_years" class="form-control" required>
                </div>
                <div class="col-md-3">
                  <label class="form-label required">Availability Type</label>
                  <select name="availability_type" class="form-select" required>
                    <option value="">Choose…</option>
                    <option>day</option>
                    <option>night</option>
                    <option>24h</option>
                    <option>hourly</option>
                    <option>mixed</option>
                  </select>
                </div>

                <div class="col-md-3">
                  <label class="form-label required">Rate Type</label>
                  <select name="expected_rate_type" class="form-select" required>
                    <option value="">Choose…</option>
                    <option>hourly</option>
                    <option>shift</option>
                    <option>day</option>
                    <option>24h</option>
                  </select>
                </div>
                <div class="col-md-3">
                  <label class="form-label required">Rate Amount</label>
                  <input type="number" step="0.01" name="expected_rate_amount" class="form-control" required>
                </div>
                <div class="col-md-3">
                  <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="can_live_in" id="canlive">
                    <label class="form-check-label" for="canlive">Can do Live-in (24h)</label>
                  </div>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Notice Period (days)</label>
                  <input type="number" min="0" max="60" name="notice_period_days" class="form-control" placeholder="0">
                </div>

                <!-- NEW: Preferred Area(s) -->
                <div class="col-12">
                  <label class="form-label">Preferred Area(s)</label>
                  <input name="area_preference" class="form-control" placeholder="e.g., Gulshan, Banani, Dhanmondi">
                  <div class="small-hint">Write where you prefer to work (comma separated).</div>
                </div>

                <div class="col-12">
                  <label class="form-label required">Languages</label><br>
                  <?php foreach ($languagesList as $lang): ?>
                    <label class="me-3"><input type="checkbox" name="languages[]" value="<?php echo $lang; ?>">
                      <?php echo $lang; ?></label>
                  <?php endforeach; ?>
                </div>

                <div class="col-12">
                  <label class="form-label required">Skills</label>
                  <div class="row">
                    <?php foreach ($skillsList as $key => $label): ?>
                      <div class="col-6 col-md-4"><label class="me-3"><input type="checkbox" name="skills[]"
                            value="<?php echo $key; ?>"> <?php echo $label; ?></label></div>
                    <?php endforeach; ?>
                  </div>
                </div>

                <div class="col-12">
                  <label class="form-label">Weekly Availability (optional times)</label>
                  <div class="row g-2">
                    <?php
                    $days = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
                    foreach ($days as $i => $d) {
                      echo '<div class="col-6 col-md-3"><div class="p-2 border rounded">';
                      echo "<div class='badge badge-soft mb-1'>$d</div>";
                      echo "<input type='time' class='form-control form-control-sm' name='avail[$i][start]' placeholder='Start'>";
                      echo "<input type='time' class='form-control form-control-sm mt-1' name='avail[$i][end]' placeholder='End'>";
                      echo '</div></div>';
                    }
                    ?>
                  </div>
                  <div class="small-hint mt-1">Leave blank for days you are not available.</div>
                </div>
              </div>

              <div class="sticky-actions mt-3 d-flex justify-content-between">
                <button type="button" class="btn btn-light prev-step">Back</button>
                <button type="button" class="btn btn-primary next-step">Next</button>
              </div>
            </div>

            <!-- STEP 3 -->
            <div class="form-step d-none" data-step="3">
              <h5>Education, Certifications & References</h5>
              <div class="row g-3 mt-1">
                <div class="col-md-6">
                  <label class="form-label required">Highest Qualification</label>
                  <input name="highest_qualification" class="form-control" required
                    placeholder="e.g., Diploma in Nursing">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Police Verification (PDF/JPG/PNG/DOC/DOCX, ≤2MB)</label>
                  <input type="file" name="police_verification_file" class="form-control"
                    accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Medical Fitness (PDF/JPG/PNG/DOC/DOCX, ≤2MB)</label>
                  <input type="file" name="medical_fitness_file" class="form-control"
                    accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                </div>
              </div>

              <hr class="my-3">
              <h6 class="mb-2">Certifications <span class="small-hint">(optional)</span></h6>
              <div id="certRows"></div>
              <button type="button" class="btn btn-sm btn-accent" id="addCert"><i class="bi bi-plus-lg"></i> Add
                Certification</button>

              <hr class="my-3">
              <h6 class="mb-2">References <span class="small-hint">(at least one)</span></h6>
              <div id="refRows"></div>
              <button type="button" class="btn btn-sm btn-accent" id="addRef"><i class="bi bi-plus-lg"></i> Add
                Reference</button>

              <div class="sticky-actions mt-3 d-flex justify-content-between">
                <button type="button" class="btn btn-light prev-step">Back</button>
                <button type="button" class="btn btn-primary next-step">Next</button>
              </div>
            </div>

            <!-- STEP 4 -->
            <div class="form-step d-none" data-step="4">
              <h5>Emergency & Account</h5>
              <div class="row g-3 mt-1">
                <div class="col-md-4">
                  <label class="form-label required">Emergency Contact Name</label>
                  <input name="emg_name" class="form-control" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label required">Relation</label>
                  <input name="emg_relation" class="form-control" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label required">Emergency Phone</label>
                  <input name="emg_phone" class="form-control" required placeholder="01XXXXXXXXX">
                </div>

                <div class="col-md-6">
                  <label class="form-label required">Password</label>
                  <input type="password" name="password" class="form-control" required>
                  <div class="small-hint">Min 8 chars, include uppercase & a number.</div>
                </div>
                <div class="col-md-6">
                  <label class="form-label required">Confirm Password</label>
                  <input type="password" name="password_confirm" class="form-control" required>
                </div>

                <div class="col-12">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="consent_data_processing" id="c1" required>
                    <label class="form-check-label" for="c1">I agree to store/process my data for job matching.</label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="consent_background_check" id="c2" required>
                    <label class="form-check-label" for="c2">I authorize verification of my documents &
                      references.</label>
                  </div>
                </div>
              </div>

              <div class="sticky-actions mt-3 d-flex justify-content-between">
                <button type="button" class="btn btn-light prev-step">Back</button>
                <button type="submit" class="btn btn-primary">Submit Registration</button>
              </div>
            </div>
          </form>

        </div>
      </div>
    </div>
  </div>

  <footer class="py-4 text-center text-muted">
    <small>©
      <script>document.write(new Date().getFullYear())</script> CGMS • Caregiver Registration
    </small>
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    /* Step logic with required-toggling to avoid hidden-required blocking submit */
    const form = document.getElementById('cgForm');
    const steps = Array.from(document.querySelectorAll('.form-step'));
    const dots = document.querySelectorAll('.step-idx');
    let cur = 1;

    steps.forEach(step => {
      step.querySelectorAll('input,select,textarea').forEach(el => {
        if (el.hasAttribute('required')) el.dataset.req = '1';
      });
    });

    function setRequiredForStep(stepEl, active) {
      stepEl.querySelectorAll('input,select,textarea').forEach(el => {
        if (el.dataset.req === '1') el.required = !!active;
      });
    }
    function showStep(n) {
      steps.forEach((s, i) => {
        const active = (i + 1) === n;
        s.classList.toggle('d-none', !active);
        setRequiredForStep(s, active);
      });
      dots.forEach((d, i) => d.classList.toggle('active', (i + 1) <= n));
      cur = n;
    }
    function validateCurrentStep() {
      const active = steps[cur - 1];
      const fields = Array.from(active.querySelectorAll('input,select,textarea'));
      for (const el of fields) {
        if (!el.checkValidity()) { el.reportValidity(); return false; }
      }
      if (cur === 2) {
        if (!document.querySelector('input[name="languages[]"]:checked')) { alert('Please select at least one language.'); return false; }
        if (!document.querySelector('input[name="skills[]"]:checked')) { alert('Please select at least one skill.'); return false; }
      }
      return true;
    }
    document.querySelectorAll('.next-step').forEach(b => b.onclick = () => { if (!validateCurrentStep()) return; showStep(Math.min(cur + 1, 4)); });
    document.querySelectorAll('.prev-step').forEach(b => b.onclick = () => showStep(Math.max(cur - 1, 1)));
    form.addEventListener('submit', (e) => { if (!validateCurrentStep()) { e.preventDefault(); return; } });

    /* Same-as-present behaviour */
    const same = document.getElementById('sameAddr');
    const pa = document.getElementById('present_address');
    const pdist = document.getElementById('present_district');
    const permA = document.getElementById('permanent_address');
    const permD = document.getElementById('permanent_district');

    function syncPermanent() {
      const on = same.checked;
      if (on) { permA.value = pa.value; permD.value = pdist.value; }
      permA.readOnly = on;
      permD.disabled = on;
    }
    same?.addEventListener('change', syncPermanent);
    pa?.addEventListener('input', () => { if (same.checked) permA.value = pa.value; });
    pdist?.addEventListener('change', () => { if (same.checked) permD.value = pdist.value; });
    syncPermanent();

    /* dynamic rows */
    function makeCertRow() {
      const tpl = `
        <div class="row g-2 align-items-end mb-2">
          <div class="col-md-3"><label class="form-label">Name</label><input name="cert_name[]" class="form-control" placeholder="e.g., CPR"></div>
          <div class="col-md-3"><label class="form-label">Issued By</label><input name="cert_org[]" class="form-control"></div>
          <div class="col-md-2"><label class="form-label">ID</label><input name="cert_id[]" class="form-control"></div>
          <div class="col-md-2"><label class="form-label">Valid Till</label><input type="date" name="cert_valid_till[]" class="form-control"></div>
          <div class="col-md-2"><label class="form-label">File</label><input type="file" name="cert_file[]" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"></div>
        </div>`;
      document.getElementById('certRows').insertAdjacentHTML('beforeend', tpl);
    }
    function makeRefRow() {
      const tpl = `
        <div class="row g-2 align-items-end mb-2">
          <div class="col-md-3"><label class="form-label">Name</label><input name="ref_name[]" class="form-control" required></div>
          <div class="col-md-3"><label class="form-label">Relation</label><input name="ref_relation[]" class="form-control"></div>
          <div class="col-md-3"><label class="form-label">Phone</label><input name="ref_phone[]" class="form-control" required></div>
          <div class="col-md-3"><label class="form-label">Email</label><input type="email" name="ref_email[]" class="form-control"></div>
        </div>`;
      document.getElementById('refRows').insertAdjacentHTML('beforeend', tpl);
    }
    document.getElementById('addCert')?.addEventListener('click', makeCertRow);
    document.getElementById('addRef')?.addEventListener('click', makeRefRow);
    makeRefRow(); // seed one
    showStep(1);  // initialize
  </script>
</body>

</html>