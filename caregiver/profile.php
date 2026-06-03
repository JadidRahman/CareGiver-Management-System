<?php
/** Caregiver Profile Edit */
session_start();
require_once __DIR__ . '/../config.php'; // must define $con (mysqli) + url()

if (empty($_SESSION['user_role']) || $_SESSION['user_role'] !== 'caregiver') {
  header('Location: ' . url('login.php'));
  exit;
}

/* ---------- helpers ---------- */
function safe($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function table_exists(mysqli $con, string $table): bool{
  $sql="SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? LIMIT 1";
  $st=$con->prepare($sql); $st->bind_param("s",$table); $st->execute();
  $ok=$st->get_result()->num_rows>0; $st->close(); return $ok;
}
function bd_phone_valid($p){ return preg_match('/^(?:\+?88)?01[3-9]\d{8}$/', trim((string)$p)); }
/** Save upload into caregiver folder; return relative path or null */
function save_upload_rel(string $field, int $cgId, array $allowed, int $maxBytes){
  if (empty($_FILES[$field]['name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
  if ($_FILES[$field]['size'] > $maxBytes) return null;
  if (!function_exists('finfo_open')) return null;
  $finfo=finfo_open(FILEINFO_MIME_TYPE); $mime=finfo_file($finfo, $_FILES[$field]['tmp_name']); finfo_close($finfo);
  if (!in_array($mime, $allowed, true)) return null;
  $dir = __DIR__ . '/../uploads/caregivers/' . $cgId;
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  if (!is_writable($dir)) @chmod($dir, 0775);
  $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
  $name = $field . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
  $dest = rtrim($dir, '/\\') . '/' . $name;
  if (move_uploaded_file($_FILES[$field]['tmp_name'], $dest)) {
    return 'uploads/caregivers/' . $cgId . '/' . $name; // relative path for DB
  }
  return null;
}

/* ---------- Static lists (same as register) ---------- */
$skillsList = [
  "medication_support" => "Medication support", "wound_care" => "Wound care",
  "catheter_care" => "Catheter care", "stoma_care" => "Stoma care", "feeding_support" => "Feeding support",
  "mobility_transfer" => "Mobility & transfer", "hygiene_toileting" => "Hygiene & toileting",
  "dementia_support" => "Dementia/behavior support", "vitals_monitoring" => "Vitals monitoring",
  "physiotherapy_assist" => "Physiotherapy assist", "child_care" => "Child care", "companionship" => "Companionship",
  "other" => "Other"
];
$languagesList = ["Bangla","English","Hindi","Urdu","Other"];
$districts = ["Dhaka","Chattogram","Khulna","Rajshahi","Sylhet","Barishal","Rangpur","Mymensingh"];

/* ---------- load caregiver ---------- */
$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) { header('Location: ' . url('login.php')); exit; }

$st = $con->prepare("SELECT * FROM caregivers WHERE user_id=? LIMIT 1");
$st->bind_param("i", $uid);
$st->execute();
$cg = $st->get_result()->fetch_assoc();
$st->close();
if (!$cg) { header('Location: ' . url('login.php')); exit; }
$cgId = (int)$cg['id'];

/* current languages/skills for checkboxes */
$curLangs = [];
$curSkills = [];
if (table_exists($con, 'caregiver_languages')) {
  $st=$con->prepare("SELECT language FROM caregiver_languages WHERE caregiver_id=?");
  $st->bind_param("i",$cgId); $st->execute(); $res=$st->get_result();
  while($r=$res->fetch_row()) $curLangs[] = $r[0]; $st->close();
}
if (table_exists($con, 'caregiver_skills')) {
  $st=$con->prepare("SELECT skill_key FROM caregiver_skills WHERE caregiver_id=?");
  $st->bind_param("i",$cgId); $st->execute(); $res=$st->get_result();
  while($r=$res->fetch_row()) $curSkills[] = $r[0]; $st->close();
}

/* ---------- POST: save ---------- */
$errors = [];
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // gather posted values with fallback to existing DB values
  $first_name = trim($_POST['first_name'] ?? $cg['first_name']);
  $last_name  = trim($_POST['last_name']  ?? $cg['last_name']);
  $dob        = $_POST['dob']            ?? $cg['dob'];
  $gender     = $_POST['gender']         ?? $cg['gender'];
  $nid_passport = trim($_POST['nid_passport'] ?? (string)($cg['nid_passport'] ?? ''));
  $blood_group  = $_POST['blood_group']  ?? (string)($cg['blood_group'] ?? '');

  $phone_primary = trim($_POST['phone_primary'] ?? $cg['phone_primary']);
  $phone_alt     = trim($_POST['phone_alt']     ?? (string)($cg['phone_alt'] ?? ''));

  $present_address  = trim($_POST['present_address']  ?? (string)($cg['present_address'] ?? ''));
  $present_district = $_POST['present_district']      ?? (string)($cg['present_district'] ?? '');
  $permanent_address  = trim($_POST['permanent_address']  ?? (string)($cg['permanent_address'] ?? ''));
  $permanent_district = $_POST['permanent_district']      ?? (string)($cg['permanent_district'] ?? '');

  $caregiver_type = $_POST['caregiver_type'] ?? (string)($cg['caregiver_type'] ?? '');
  $experience_years = (int)($_POST['experience_years'] ?? (int)($cg['experience_years'] ?? 0));
  $expected_rate_type = $_POST['expected_rate_type'] ?? (string)($cg['expected_rate_type'] ?? 'hourly');
  $expected_rate_amount = (float)($_POST['expected_rate_amount'] ?? (float)($cg['expected_rate_amount'] ?? 0));
  $availability_type = $_POST['availability_type'] ?? (string)($cg['availability_type'] ?? 'day');
  $can_live_in = isset($_POST['can_live_in']) ? 1 : (int)($cg['can_live_in'] ?? 0);
  $notice_period_days = (int)($_POST['notice_period_days'] ?? (int)($cg['notice_period_days'] ?? 0));
  $highest_qualification = trim($_POST['highest_qualification'] ?? (string)($cg['highest_qualification'] ?? ''));

  $emg_name = trim($_POST['emg_name'] ?? (string)($cg['emg_name'] ?? ''));
  $emg_relation = trim($_POST['emg_relation'] ?? (string)($cg['emg_relation'] ?? ''));
  $emg_phone = trim($_POST['emg_phone'] ?? (string)($cg['emg_phone'] ?? ''));

  $langsPost  = (array)($_POST['languages'] ?? $curLangs);
  $skillsPost = (array)($_POST['skills']    ?? $curSkills);

  // basic validation
  if ($first_name === '' || $last_name === '') $errors[] = 'First & last name are required.';
  if ($phone_primary === '' || !bd_phone_valid($phone_primary)) $errors[] = 'Enter a valid primary phone (BD format).';
  if ($present_address === '' || $present_district === '') $errors[] = 'Present address & district required.';
  if ($highest_qualification === '') $errors[] = 'Highest qualification required.';
  if (empty($langsPost))  $errors[] = 'Please select at least one language.';
  if (empty($skillsPost)) $errors[] = 'Please select at least one skill.';

  if (!$errors) {
    $con->begin_transaction();
    try {
      // optional uploads
      $newPhoto   = save_upload_rel('photo', $cgId, ['image/jpeg','image/png'], (int)(1.5*1024*1024));
      $newPolice  = save_upload_rel('police_verification_file', $cgId, ['image/jpeg','image/png','application/pdf'], 2*1024*1024);
      $newMedical = save_upload_rel('medical_fitness_file',  $cgId, ['image/jpeg','image/png','application/pdf'], 2*1024*1024);

      // Build SQL dynamically
      $sql = "UPDATE caregivers SET
        first_name=?, last_name=?, dob=?, gender=?, nid_passport=?, blood_group=?,
        phone_primary=?, phone_alt=?, present_address=?, present_district=?,
        permanent_address=?, permanent_district=?,
        caregiver_type=?, experience_years=?, expected_rate_type=?, expected_rate_amount=?,
        availability_type=?, can_live_in=?, notice_period_days=?, highest_qualification=?,
        emg_name=?, emg_relation=?, emg_phone=?";

      if ($newPhoto)   $sql .= ", photo_path=?";
      if ($newPolice)  $sql .= ", police_verification_path=?";
      if ($newMedical) $sql .= ", medical_fitness_path=?";
      $sql .= " WHERE id=?";

      $st = $con->prepare($sql);

      // types & values
      $types = '';
      $vals = [];
      $add = function($v, $t) use (&$types, &$vals){ $types .= $t; $vals[] = $v; };

      $add($first_name, 's');
      $add($last_name,  's');
      $add($dob,        's');
      $add($gender,     's');
      $add($nid_passport, 's');
      $add($blood_group,  's');

      $add($phone_primary,'s');
      $add($phone_alt,    's');
      $add($present_address,  's');
      $add($present_district, 's');
      $add($permanent_address,  's');
      $add($permanent_district, 's');

      $add($caregiver_type, 's');
      $add($experience_years, 'i');
      $add($expected_rate_type, 's');
      $add($expected_rate_amount, 'd');
      $add($availability_type, 's');
      $add($can_live_in, 'i');
      $add($notice_period_days, 'i');
      $add($highest_qualification, 's');

      $add($emg_name, 's');
      $add($emg_relation, 's');
      $add($emg_phone, 's');

      if ($newPhoto)   $add($newPhoto, 's');
      if ($newPolice)  $add($newPolice, 's');
      if ($newMedical) $add($newMedical,'s');

      $add($cgId, 'i');

      $st->bind_param($types, ...$vals);
      $st->execute();
      $st->close();

      // Replace languages & skills
      if (table_exists($con, 'caregiver_languages')) {
        $con->query("DELETE FROM caregiver_languages WHERE caregiver_id=".$cgId);
        $ins=$con->prepare("INSERT INTO caregiver_languages (caregiver_id, language) VALUES (?,?)");
        foreach ($langsPost as $L){ $l = trim($L); if(!$l) continue; $ins->bind_param("is",$cgId,$l); $ins->execute(); }
        $ins->close();
      }
      if (table_exists($con, 'caregiver_skills')) {
        $con->query("DELETE FROM caregiver_skills WHERE caregiver_id=".$cgId);
        $ins=$con->prepare("INSERT INTO caregiver_skills (caregiver_id, skill_key) VALUES (?,?)");
        foreach ($skillsPost as $S){ $s = trim($S); if(!$s) continue; $ins->bind_param("is",$cgId,$s); $ins->execute(); }
        $ins->close();
      }

      $con->commit();
      $saved = true;

      // reload current record and selections
      $st=$con->prepare("SELECT * FROM caregivers WHERE id=? LIMIT 1");
      $st->bind_param("i",$cgId); $st->execute();
      $cg = $st->get_result()->fetch_assoc() ?: $cg; $st->close();
      $curLangs = $langsPost; $curSkills = $skillsPost;

    } catch (Throwable $e) {
      $con->rollback();
      $errors[] = "Update failed: " . $e->getMessage();
    }
  }
}

/* quick doc links (after possible save) */
$docPolice = !empty($cg['police_verification_path']) ? '../'.safe($cg['police_verification_path']) : null;
$docMedical= !empty($cg['medical_fitness_path'])     ? '../'.safe($cg['medical_fitness_path'])     : null;

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Edit Profile | CGMS Caregiver</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root{ --pri:#1d4ed8; --acc:#06b6d4; --ink:#0f172a; --mut:#64748b; }
body{ background:#f8fafc; color:var(--ink); }
.navbar{ backdrop-filter:blur(10px); background:rgba(255,255,255,.9)!important; border-bottom:1px solid rgba(15,23,42,.06); }
.brand{ font-weight:800; background:linear-gradient(90deg,var(--pri),var(--acc)); -webkit-background-clip:text; color:transparent; }
.card-soft{ background:#fff; border:1px solid rgba(15,23,42,.08); border-radius:18px; box-shadow:0 16px 40px rgba(2,6,23,.07); }
.small-hint{ color:var(--mut); font-size:.9rem; }
.avatar{ width:44px; height:44px; border-radius:50%; object-fit:cover; }
</style>
</head>
<body>
<nav class="navbar navbar-expand-lg sticky-top">
  <div class="container">
    <a class="navbar-brand brand" href="<?php echo safe(url('index.php')); ?>">CGMS</a>
    <div class="ms-auto d-flex align-items-center gap-2">
      <a class="btn btn-sm btn-outline-secondary" href="<?php echo safe(url('caregiver/dashboard.php')); ?>">Dashboard</a>
      <a class="btn btn-sm btn-outline-secondary" href="<?php echo safe(url('login.php')); ?>?logout=1">Logout</a>
    </div>
  </div>
</nav>

<div class="container my-3 my-md-4">
  <div class="card card-soft p-3 p-md-4">
    <div class="d-flex align-items-center gap-3 mb-3">
      <?php if(!empty($cg['photo_path'])): ?>
        <img src="<?php echo '../'.safe($cg['photo_path']); ?>" class="avatar" alt="">
      <?php else: ?>
        <div class="avatar bg-light d-flex align-items-center justify-content-center"><i class="bi bi-person text-muted"></i></div>
      <?php endif; ?>
      <div>
        <h5 class="mb-0">Edit Profile, <?php echo safe($cg['first_name'] ?? ''); ?></h5>
        <div class="text-muted small">Keep your information accurate for better matching.</div>
      </div>
      <span class="badge text-bg-light ms-auto">ID #<?php echo (int)$cgId; ?></span>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-danger">
        <strong>Fix the following:</strong>
        <ul class="mb-0">
          <?php foreach ($errors as $e) echo "<li>".safe($e)."</li>"; ?>
        </ul>
      </div>
    <?php elseif ($saved): ?>
      <div class="alert alert-success"><i class="bi bi-check2-circle me-1"></i>Profile updated successfully.</div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="mt-2">

      <div class="row g-3">
        <!-- Photo -->
        <div class="col-lg-6">
          <div class="card card-soft p-3 h-100">
            <div class="fw-semibold mb-2">Profile Photo</div>
            <input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png">
            <div class="small-hint mt-1">JPG/PNG, ≤ 1.5MB.</div>
          </div>
        </div>
        <!-- Docs -->
        <div class="col-lg-6">
          <div class="card card-soft p-3 h-100">
            <div class="fw-semibold mb-2">Verification Documents</div>
            <div class="row g-2">
              <div class="col-12">
                <label class="form-label small">Police Verification (PDF/JPG/PNG, ≤2MB)</label>
                <input type="file" name="police_verification_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                <div class="small-hint">Current:
                  <?php if($docPolice): ?><a href="<?php echo $docPolice; ?>" target="_blank">View</a><?php else: ?>N/A<?php endif; ?>
                </div>
              </div>
              <div class="col-12">
                <label class="form-label small">Medical Fitness (PDF/JPG/PNG, ≤2MB)</label>
                <input type="file" name="medical_fitness_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                <div class="small-hint">Current:
                  <?php if($docMedical): ?><a href="<?php echo $docMedical; ?>" target="_blank">View</a><?php else: ?>N/A<?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        </div>

        <hr class="my-2">

        <!-- Personal -->
        <div class="col-12"><div class="fw-semibold">Personal & Contact</div></div>
        <div class="col-md-6">
          <label class="form-label">First Name</label>
          <input name="first_name" class="form-control" value="<?php echo safe($cg['first_name'] ?? ''); ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Last Name</label>
          <input name="last_name" class="form-control" value="<?php echo safe($cg['last_name'] ?? ''); ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Date of Birth</label>
          <input type="date" name="dob" class="form-control" value="<?php echo safe($cg['dob'] ?? ''); ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Gender</label>
          <select name="gender" class="form-select">
            <?php
              $g = strtolower($cg['gender'] ?? '');
              foreach (['male'=>'Male','female'=>'Female','other'=>'Other'] as $k=>$v){
                $sel = $g===$k?'selected':'';
                echo "<option value=\"$k\" $sel>$v</option>";
              }
            ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Blood Group</label>
          <select name="blood_group" class="form-select">
            <?php
              $bg = $cg['blood_group'] ?? '';
              foreach (['','A+','A-','B+','B-','O+','O-','AB+','AB-'] as $v){
                $sel = ($bg===$v)?'selected':'';
                echo "<option value=\"".safe($v)."\" $sel>".($v?:'Select')."</option>";
              }
            ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Primary Phone</label>
          <input name="phone_primary" class="form-control" value="<?php echo safe($cg['phone_primary'] ?? ''); ?>">
          <div class="small-hint">Bangladesh format 01XXXXXXXXX</div>
        </div>
        <div class="col-md-6">
          <label class="form-label">Alternate Phone</label>
          <input name="phone_alt" class="form-control" value="<?php echo safe($cg['phone_alt'] ?? ''); ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">NID / Passport</label>
          <input name="nid_passport" class="form-control" value="<?php echo safe($cg['nid_passport'] ?? ''); ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Email (login)</label>
          <input class="form-control" value="<?php echo safe($cg['email'] ?? ($_SESSION['user_email'] ?? '')); ?>" disabled>
          <div class="small-hint">To change email/password, contact support or use Account page.</div>
        </div>

        <div class="col-12">
          <label class="form-label">Present Address</label>
          <textarea name="present_address" class="form-control" rows="2"><?php echo safe($cg['present_address'] ?? ''); ?></textarea>
        </div>
        <div class="col-md-6">
          <label class="form-label">Present District</label>
          <select name="present_district" class="form-select">
            <option value="">Choose…</option>
            <?php $pd = $cg['present_district'] ?? '';
              foreach ($districts as $d){ $sel=($pd===$d)?'selected':''; echo "<option $sel>".safe($d)."</option>"; }
            ?>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label">Permanent Address</label>
          <textarea name="permanent_address" class="form-control" rows="2"><?php echo safe($cg['permanent_address'] ?? ''); ?></textarea>
        </div>
        <div class="col-md-6">
          <label class="form-label">Permanent District</label>
          <select name="permanent_district" class="form-select">
            <option value="">Choose…</option>
            <?php $qd = $cg['permanent_district'] ?? '';
              foreach ($districts as $d){ $sel=($qd===$d)?'selected':''; echo "<option $sel>".safe($d)."</option>"; }
            ?>
          </select>
        </div>

        <!-- Professional -->
        <div class="col-12"><hr><div class="fw-semibold">Professional</div></div>
        <div class="col-md-4">
          <label class="form-label">Caregiver Type</label>
          <select name="caregiver_type" class="form-select">
            <?php
              $ct = $cg['caregiver_type'] ?? '';
              foreach (['nurse'=>'Nurse','attendant'=>'Attendant','physiotherapist'=>'Physiotherapist','therapist'=>'Therapist','other'=>'Other'] as $k=>$v){
                $sel = ($ct===$k)?'selected':''; echo "<option value=\"$k\" $sel>$v</option>";
              }
            ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Experience (years)</label>
          <input type="number" min="0" max="50" name="experience_years" class="form-control" value="<?php echo (int)($cg['experience_years'] ?? 0); ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Availability Type</label>
          <select name="availability_type" class="form-select">
            <?php
              $av = $cg['availability_type'] ?? 'day';
              foreach (['day','night','24h','hourly','mixed'] as $v){
                $sel = ($av===$v)?'selected':''; echo "<option $sel>".safe($v)."</option>";
              }
            ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Rate Type</label>
          <select name="expected_rate_type" class="form-select">
            <?php
              $rt = $cg['expected_rate_type'] ?? 'hourly';
              foreach (['hourly','shift','day','24h'] as $v){ $sel=($rt===$v)?'selected':''; echo "<option $sel>".safe($v)."</option>"; }
            ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Rate Amount</label>
          <input type="number" step="0.01" name="expected_rate_amount" class="form-control" value="<?php echo safe($cg['expected_rate_amount'] ?? ''); ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Notice Period (days)</label>
          <input type="number" min="0" max="60" name="notice_period_days" class="form-control" value="<?php echo (int)($cg['notice_period_days'] ?? 0); ?>">
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" name="can_live_in" id="canlive" <?php echo !empty($cg['can_live_in'])?'checked':''; ?>>
            <label class="form-check-label" for="canlive">Can do Live-in (24h)</label>
          </div>
        </div>

        <div class="col-md-6">
          <label class="form-label">Highest Qualification</label>
          <input name="highest_qualification" class="form-control" value="<?php echo safe($cg['highest_qualification'] ?? ''); ?>">
        </div>

        <!-- Languages & Skills -->
        <div class="col-12">
          <label class="form-label">Languages</label><br>
          <?php foreach ($languagesList as $lang): $chk=in_array($lang,$curLangs,true)?'checked':''; ?>
            <label class="me-3"><input type="checkbox" name="languages[]" value="<?php echo safe($lang); ?>" <?php echo $chk; ?>> <?php echo safe($lang); ?></label>
          <?php endforeach; ?>
        </div>
        <div class="col-12">
          <label class="form-label">Skills</label>
          <div class="row">
            <?php foreach ($skillsList as $key=>$label): $chk=in_array($key,$curSkills,true)?'checked':''; ?>
              <div class="col-6 col-md-4"><label class="me-3"><input type="checkbox" name="skills[]" value="<?php echo safe($key); ?>" <?php echo $chk; ?>> <?php echo safe($label); ?></label></div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Emergency -->
        <div class="col-12"><hr><div class="fw-semibold">Emergency Contact</div></div>
        <div class="col-md-4">
          <label class="form-label">Name</label>
          <input name="emg_name" class="form-control" value="<?php echo safe($cg['emg_name'] ?? ''); ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Relation</label>
          <input name="emg_relation" class="form-control" value="<?php echo safe($cg['emg_relation'] ?? ''); ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Phone</label>
          <input name="emg_phone" class="form-control" value="<?php echo safe($cg['emg_phone'] ?? ''); ?>">
        </div>
      </div>

      <div class="mt-3 d-flex justify-content-end">
        <a class="btn btn-light me-2" href="<?php echo safe(url('caregiver/dashboard.php')); ?>">Cancel</a>
        <button class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>

  <footer class="text-muted small mt-4">© <?php echo date('Y'); ?> CGMS • Caregiver Portal</footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
