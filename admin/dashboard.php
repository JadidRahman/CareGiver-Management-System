<?php
session_start();
require_once __DIR__ . '/../config.php';

if (empty($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
  header('Location: ' . url('login.php'));
  exit;
}

/* ---------- helpers ---------- */
function safe($s) {
  return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
function tryQuery(callable $fn, $fallback) {
  try { return $fn(); } catch (Throwable $e) {
    error_log($e->getMessage());
    return $fallback;
  }
}
function table_exists(mysqli $con, string $table): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? LIMIT 1";
  $st = $con->prepare($sql);
  $st->bind_param("s", $table);
  $st->execute();
  $ok = $st->get_result()->num_rows > 0;
  $st->close();
  return $ok;
}
function column_exists(mysqli $con, string $table, string $column): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
  $st = $con->prepare($sql);
  $st->bind_param("ss", $table, $column);
  $st->execute();
  $ok = $st->get_result()->num_rows > 0;
  $st->close();
  return $ok;
}
function time_ago($ts) {
  $t = @strtotime($ts);
  if (!$t) return '—';
  $d = time() - $t;
  if ($d < 60) return $d . 's ago';
  $m = floor($d / 60);
  if ($m < 60) return $m . 'm ago';
  $h = floor($m / 60);
  if ($h < 24) return $h . 'h ago';
  $dd = floor($h / 24);
  if ($dd < 7) return $dd . 'd ago';
  return date('M j, Y g:ia', $t);
}

/* quick lookups */
$CURRENCY = defined('CURRENCY') ? CURRENCY : '৳';
$PROFIT_MARGIN = defined('PROFIT_MARGIN') ? (float) PROFIT_MARGIN : 0.20;

/* ============================================================
   =================== AJAX HANDLERS ===========================
   ============================================================ */

/* ---------- AJAX: caregiver resume ---------- */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'caregiver_resume' && isset($_GET['id'])) {
  /** @var mysqli $con */
  $id = (int) $_GET['id'];

  // Main caregiver row
  $stmt = $con->prepare("SELECT * FROM caregivers WHERE id=? LIMIT 1");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $cg = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$cg) { echo '<div class="text-danger">Caregiver not found.</div>'; exit; }

  // Lookups
  $langs = [];
  if (table_exists($con, 'caregiver_languages')) {
    $res = $con->prepare("SELECT language FROM caregiver_languages WHERE caregiver_id=?");
    $res->bind_param("i", $id);
    $res->execute();
    $rs = $res->get_result();
    while ($r = $rs->fetch_assoc()) $langs[] = $r['language'];
    $res->close();
  }

  // Skills
  $skillLabels = [
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
  $skills = [];
  if (table_exists($con, 'caregiver_skills')) {
    $res = $con->prepare("SELECT skill_key FROM caregiver_skills WHERE caregiver_id=?");
    $res->bind_param("i", $id);
    $res->execute();
    $rs = $res->get_result();
    while ($r = $rs->fetch_assoc()) {
      $k = $r['skill_key'];
      $skills[] = $skillLabels[$k] ?? str_replace('_', ' ', $k);
    }
    $res->close();
  }

  // Weekly availability
  $days = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
  $avail = [];
  if (table_exists($con, 'caregiver_availability')) {
    $res = $con->prepare("SELECT dow,start_time,end_time FROM caregiver_availability WHERE caregiver_id=? ORDER BY dow");
    $res->bind_param("i", $id);
    $res->execute();
    $rs = $res->get_result();
    while ($r = $rs->fetch_assoc()) {
      $st = $r['start_time'] ? substr($r['start_time'], 0, 5) : '';
      $en = $r['end_time'] ? substr($r['end_time'], 0, 5) : '';
      $avail[] = ['dow' => (int)$r['dow'], 'start' => $st, 'end' => $en];
    }
    $res->close();
  }

  // References
  $refs = [];
  if (table_exists($con, 'caregiver_references')) {
    $res = $con->prepare("SELECT ref_name,ref_relation,ref_phone,ref_email FROM caregiver_references WHERE caregiver_id=?");
    $res->bind_param("i", $id);
    $res->execute();
    $rs = $res->get_result();
    while ($r = $rs->fetch_assoc()) $refs[] = $r;
    $res->close();
  }

  // Certifications
  $certs = [];
  if (table_exists($con, 'caregiver_certifications')) {
    $res = $con->prepare("SELECT cert_name,cert_org,cert_id,valid_till,file_path FROM caregiver_certifications WHERE caregiver_id=?");
    $res->bind_param("i", $id);
    $res->execute();
    $rs = $res->get_result();
    while ($r = $rs->fetch_assoc()) $certs[] = $r;
    $res->close();
  }

  // Small helpers for files in admin (../ relative)
  $isImg = function ($p) { return (bool)preg_match('/\.(jpe?g|png|gif|webp)$/i', (string)$p); };
  $fileBlock = function ($relPath) use ($isImg) {
    if (empty($relPath)) return '<span class="text-muted">N/A</span>';
    $url = '../' . safe($relPath);
    $name = safe(basename($relPath));
    $pill = '<a class="btn btn-sm btn-outline-primary me-2" target="_blank" href="' . $url . '"><i class="bi bi-box-arrow-up-right me-1"></i>' . $name . '</a>';
    $thumb = $isImg($relPath) ? '<div class="mt-1"><img src="' . $url . '" class="img-thumbnail" style="max-height:120px"></div>' : '';
    return $pill . $thumb;
  };

  // Render
  $photo = !empty($cg['photo_path']) ? '../' . safe($cg['photo_path']) : '';
  $live  = !empty($cg['can_live_in']) ? 'Yes' : 'No';
  $rate  = (defined('CURRENCY') ? CURRENCY : '৳') . ' ' . number_format((float)($cg['expected_rate_amount'] ?? 0), 0) . ' / ' . safe($cg['expected_rate_type'] ?? 'hourly');
  $dob   = !empty($cg['dob']) ? (new DateTime($cg['dob']))->format('M j, Y') : '—';
  ?>
  <style>
    .badge-soft { background: rgba(29, 78, 216, .08); color:#1d4ed8; border:1px solid rgba(29,78,216,.18); padding:.15rem .5rem; border-radius:9px; }
    .resume-key { color:#64748b; min-width:160px; display:inline-block; }
  </style>
  <div class="d-flex gap-3">
    <?php if ($photo): ?>
      <img src="<?php echo $photo; ?>" alt="" class="rounded" style="width:90px;height:90px;object-fit:cover;">
    <?php else: ?>
      <div class="rounded bg-light d-flex align-items-center justify-content-center" style="width:90px;height:90px;"><i class="bi bi-person fs-1 text-muted"></i></div>
    <?php endif; ?>
    <div>
      <h5 class="mb-1"><?php echo safe(($cg['first_name'] ?? '') . ' ' . ($cg['last_name'] ?? '')); ?></h5>
      <div class="text-muted small">
        <?php echo safe(ucfirst($cg['caregiver_type'] ?? 'Caregiver')); ?> •
        <?php echo (int)($cg['experience_years'] ?? 0); ?> yrs experience •
        <?php echo safe(ucfirst($cg['gender'] ?? '')); ?>
      </div>
      <div class="small mt-1"><i class="bi bi-telephone"></i> <?php echo safe($cg['phone_primary'] ?? ''); ?><?php if (!empty($cg['phone_alt'])) echo ' | ' . safe($cg['phone_alt']); ?></div>
      <div class="small"><i class="bi bi-envelope"></i> <?php echo safe($cg['email'] ?? ''); ?></div>
    </div>
  </div>
  <hr class="my-3">
  <div class="row g-3">
    <div class="col-md-6">
      <h6 class="mb-2">Personal</h6>
      <div><span class="resume-key">Date of birth</span> <?php echo safe($dob); ?></div>
      <div><span class="resume-key">Gender</span> <?php echo safe(ucfirst($cg['gender'] ?? '')); ?></div>
      <div><span class="resume-key">Blood group</span> <?php echo safe($cg['blood_group'] ?? '—'); ?></div>
      <div><span class="resume-key">NID / Passport</span> <?php echo safe($cg['nid_passport'] ?? '—'); ?></div>
      <div><span class="resume-key">Status</span> <?php echo safe($cg['status'] ?? '—'); ?></div>
    </div>
    <div class="col-md-6">
      <h6 class="mb-2">Professional</h6>
      <div><span class="resume-key">Caregiver type</span> <?php echo safe(ucfirst($cg['caregiver_type'] ?? '')); ?></div>
      <div><span class="resume-key">Experience</span> <?php echo (int)($cg['experience_years'] ?? 0); ?> years</div>
      <div><span class="resume-key">Availability type</span> <?php echo safe($cg['availability_type'] ?? '—'); ?></div>
      <div><span class="resume-key">Expected rate</span> <?php echo safe($rate); ?></div>
      <div><span class="resume-key">Live-in (24h)</span> <?php echo $live; ?></div>
      <div><span class="resume-key">Notice period</span> <?php echo (int)($cg['notice_period_days'] ?? 0); ?> days</div>
      <div><span class="resume-key">Highest qualification</span> <?php echo safe($cg['highest_qualification'] ?? '—'); ?></div>
    </div>
    <div class="col-md-6">
      <h6 class="mb-2">Address</h6>
      <div><span class="resume-key">Present address</span> <?php echo safe($cg['present_address'] ?? ''); ?></div>
      <div><span class="resume-key">Present district</span> <?php echo safe($cg['present_district'] ?? ''); ?></div>
      <div><span class="resume-key">Permanent address</span> <?php echo safe($cg['permanent_address'] ?? '—'); ?></div>
      <div><span class="resume-key">Permanent district</span> <?php echo safe($cg['permanent_district'] ?? '—'); ?></div>
    </div>
    <div class="col-md-6">
      <h6 class="mb-2">Emergency contact</h6>
      <div><span class="resume-key">Name</span> <?php echo safe($cg['emg_name'] ?? ''); ?></div>
      <div><span class="resume-key">Relation</span> <?php echo safe($cg['emg_relation'] ?? ''); ?></div>
      <div><span class="resume-key">Phone</span> <?php echo safe($cg['emg_phone'] ?? ''); ?></div>
    </div>
    <div class="col-12">
      <h6 class="mb-2">Languages</h6>
      <?php if ($langs) { foreach ($langs as $L) echo '<span class="badge-soft me-1">' . safe($L) . '</span>'; }
      else { echo '<span class="text-muted">N/A</span>'; } ?>
    </div>
    <div class="col-12">
      <h6 class="mb-2">Skills</h6>
      <?php if ($skills) { foreach ($skills as $S) echo '<span class="badge-soft me-1">' . safe($S) . '</span>'; }
      else { echo '<span class="text-muted">N/A</span>'; } ?>
    </div>
    <div class="col-12">
      <h6 class="mb-2">Weekly availability</h6>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr><th>Day</th><th>Start</th><th>End</th></tr></thead>
          <tbody>
            <?php if (!$avail): ?>
              <tr><td colspan="3" class="text-muted">No time slots provided.</td></tr>
            <?php else: foreach ($avail as $a): ?>
              <tr>
                <td><?php echo safe($days[$a['dow']] ?? $a['dow']); ?></td>
                <td><?php echo $a['start'] ?: '—'; ?></td>
                <td><?php echo $a['end'] ?: '—'; ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="col-12">
      <h6 class="mb-2">Documents</h6>
      <div class="mb-2">
        <div class="resume-key">Police verification</div>
        <div class="d-inline-block"><?php echo $fileBlock($cg['police_verification_path'] ?? null); ?></div>
      </div>
      <div>
        <div class="resume-key">Medical fitness</div>
        <div class="d-inline-block"><?php echo $fileBlock($cg['medical_fitness_path'] ?? null); ?></div>
      </div>
    </div>
    <div class="col-12">
      <h6 class="mb-2">Certifications</h6>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr><th>Name</th><th>Issued By</th><th>ID</th><th>Valid Till</th><th>File</th></tr></thead>
          <tbody>
            <?php if (!$certs): ?>
              <tr><td colspan="5" class="text-muted">No certifications uploaded.</td></tr>
            <?php else: foreach ($certs as $c): ?>
              <tr>
                <td><?php echo safe($c['cert_name']); ?></td>
                <td><?php echo safe($c['cert_org'] ?? '—'); ?></td>
                <td><?php echo safe($c['cert_id'] ?? '—'); ?></td>
                <td><?php echo safe($c['valid_till'] ?? '—'); ?></td>
                <td><?php echo $fileBlock($c['file_path'] ?? null); ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php
  exit;
}

/* ---------- AJAX: patient resume ---------- */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'patient_resume' && isset($_GET['id'])) {
  $id = (int) $_GET['id'];
  $stmt = $con->prepare("SELECT * FROM patients WHERE id=? LIMIT 1");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $p = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$p) { echo '<div class="text-danger">Patient not found.</div>'; exit; }

  $full = $p['full_name'] ?? ($p['name'] ?? ('Patient #' . $p['id']));
  $created = $p['created_at'] ?? null;

  // helper to decode json columns (if any)
  $decode = function ($k) use ($p) {
    if (empty($p[$k]) || !is_string($p[$k])) return null;
    $tmp = json_decode($p[$k], true);
    return json_last_error() === JSON_ERROR_NONE ? $tmp : null;
  };
  $comorbid = $decode('comorbidities_json');
  $meds     = $decode('medications_json');
  $tasks    = $decode('tasks_json');
  $adls     = $decode('adls_json');

  ?>
  <style>
    .resume-key { color:#64748b; min-width:200px; display:inline-block; }
    .badge-soft { background: rgba(29,78,216,.08); color:#1d4ed8; border:1px solid rgba(29,78,216,.18); padding:.15rem .5rem; border-radius:9px; }
  </style>
  <div class="d-flex justify-content-between align-items-start">
    <div>
      <h5 class="mb-0"><?php echo safe($full); ?></h5>
      <div class="text-muted small">Patient ID #<?php echo (int)$p['id']; ?><?php if ($created) echo ' • Created ' . safe(time_ago($created)); ?></div>
    </div>
    <?php if (!empty($p['case_status'])): ?>
      <span class="badge-soft"><?php echo safe($p['case_status']); ?></span>
    <?php endif; ?>
  </div>
  <hr class="my-3">
  <div class="row g-3">
    <div class="col-md-6">
      <h6 class="mb-2">Contact & Demographics</h6>
      <div><span class="resume-key">Email</span> <?php echo safe($p['email'] ?? '—'); ?></div>
      <div><span class="resume-key">Phone</span> <?php echo safe($p['phone'] ?? '—'); ?></div>
      <div><span class="resume-key">DOB</span> <?php echo safe($p['dob'] ?? '—'); ?></div>
      <div><span class="resume-key">Gender</span> <?php echo safe($p['gender'] ?? '—'); ?></div>
      <div><span class="resume-key">Service address</span> <?php echo safe($p['service_address'] ?? '—'); ?></div>
      <div><span class="resume-key">Language pref</span> <?php echo safe($p['language_pref'] ?? '—'); ?></div>
      <div><span class="resume-key">CG gender pref</span> <?php echo safe($p['caregiver_gender_pref'] ?? '—'); ?></div>
    </div>
    <div class="col-md-6">
      <h6 class="mb-2">Registrant / Decision Maker</h6>
      <div><span class="resume-key">Name</span> <?php echo safe($p['registrant_name'] ?? '—'); ?></div>
      <div><span class="resume-key">Relation</span> <?php echo safe($p['registrant_relation'] ?? '—'); ?></div>
      <div><span class="resume-key">Phone</span> <?php echo safe($p['registrant_phone1'] ?? ($p['registrant_phone'] ?? '—')); ?></div>
      <div><span class="resume-key">Email</span> <?php echo safe($p['registrant_email'] ?? '—'); ?></div>
    </div>
    <div class="col-md-6">
      <h6 class="mb-2">Clinical</h6>
      <div><span class="resume-key">Primary diagnosis</span> <?php echo safe($p['primary_dx'] ?? '—'); ?></div>
      <div><span class="resume-key">Comorbidities</span> <?php echo ($comorbid && is_array($comorbid)) ? safe(implode(', ', $comorbid)) : '—'; ?></div>
      <div><span class="resume-key">Allergies</span> <?php echo safe($p['allergies'] ?? '—'); ?></div>
      <div><span class="resume-key">Medications</span>
        <?php
        if ($meds && is_array($meds)) {
          $list = [];
          foreach ($meds as $m) $list[] = is_array($m) ? ($m['name'] ?? json_encode($m)) : $m;
          echo safe(implode(', ', $list));
        } else echo '—';
        ?>
      </div>
      <div><span class="resume-key">ADLs needing help</span>
        <?php
        if ($adls && is_array($adls)) {
          $list = [];
          foreach ($adls as $k=>$v) $list[] = is_int($k) ? $v : "$k: $v";
          echo safe(implode(', ', $list));
        } else echo '—';
        ?>
      </div>
    </div>
    <div class="col-md-6">
      <h6 class="mb-2">Requested Care</h6>
      <div><span class="resume-key">Caregiver type</span> <?php echo safe($p['caregiver_type'] ?? '—'); ?></div>
      <div><span class="resume-key">Tasks</span> <?php $tasks = $tasks ?: []; echo $tasks ? safe(implode(', ', $tasks)) : '—'; ?></div>
      <div><span class="resume-key">Shift type</span> <?php echo safe($p['shift_type'] ?? '—'); ?></div>
      <div><span class="resume-key">Hours / day</span> <?php echo safe($p['hours_per_day'] ?? '—'); ?></div>
      <div><span class="resume-key">Days / week</span> <?php echo safe($p['days_per_week'] ?? '—'); ?></div>
      <div><span class="resume-key">Start date</span> <?php echo safe($p['start_date'] ?? '—'); ?></div>
    </div>
    <div class="col-md-6">
      <h6 class="mb-2">Billing (if provided)</h6>
      <div><span class="resume-key">Payer name</span> <?php echo safe($p['payer_name'] ?? '—'); ?></div>
      <div><span class="resume-key">Payer phone</span> <?php echo safe($p['payer_phone'] ?? '—'); ?></div>
      <div><span class="resume-key">Payer email</span> <?php echo safe($p['payer_email'] ?? '—'); ?></div>
      <div><span class="resume-key">Payment mode</span> <?php echo safe($p['payment_mode'] ?? '—'); ?></div>
    </div>
  </div>
  <?php
  exit;
}

/* ---------- AJAX: nudge patient (in-app + optional email) ---------- */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'nudge_patient' && isset($_POST['id'])) {
  header('Content-Type: application/json; charset=utf-8');

  $pid = (int) $_POST['id'];

  // fetch patient row
  $ps = $con->prepare("SELECT * FROM patients WHERE id=? LIMIT 1");
  $ps->bind_param("i", $pid);
  $ps->execute();
  $p = $ps->get_result()->fetch_assoc();
  $ps->close();

  if (!$p) { echo json_encode(['ok'=>false,'error'=>'Patient not found']); exit; }

  // resolve user id
  $userId = null;
  if (column_exists($con,'patients','user_id')) $userId = (int)($p['user_id'] ?? 0) ?: null;
  if (!$userId && column_exists($con,'patients','registrant_user_id')) $userId = (int)($p['registrant_user_id'] ?? 0) ?: null;

  if (!$userId && table_exists($con,'users') && !empty($p['email'])) {
    $st = $con->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
    $st->bind_param("s", $p['email']); $st->execute();
    if ($r = $st->get_result()->fetch_assoc()) $userId = (int)$r['id'];
    $st->close();
  }
  if (!$userId && table_exists($con,'users') && !empty($p['registrant_email'])) {
    $st = $con->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
    $st->bind_param("s", $p['registrant_email']); $st->execute();
    if ($r = $st->get_result()->fetch_assoc()) $userId = (int)$r['id'];
    $st->close();
  }

  $intakeUrl = url('patients/intake.php?pid=' . $pid);
  $msg = "Please complete the intake form so we can match a caregiver faster.";
  $inserted = false;

  if ($userId && table_exists($con,'user_notifications')) {
    $sql = "INSERT INTO user_notifications (user_id, type, message, url, is_read, created_at)
            VALUES (?, 'intake_nudge', ?, ?, 0, NOW())";
    $st = $con->prepare($sql);
    $st->bind_param("iss", $userId, $msg, $intakeUrl);
    $inserted = $st->execute();
    $st->close();
  }

  $emailTried = false; $emailSent = false;
  if (function_exists('send_email')) {
    $to = array_values(array_filter([$p['registrant_email'] ?? null, $p['email'] ?? null]));
    if ($to) {
      $emailTried = true;
      $subject = 'Action needed: complete intake — CGMS';
      $html = '<p>Hi ' . safe($p['registrant_name'] ?? ($p['full_name'] ?? 'there')) . ',</p>'
            . '<p>' . $msg . '</p>'
            . '<p><a href="' . safe($intakeUrl) . '" style="background:#1d4ed8;color:#fff;padding:10px 14px;border-radius:8px;text-decoration:none">Open Intake Form</a></p>'
            . '<p>— CGMS Team</p>';
      $emailSent = @send_email($to, $subject, $html) ? true : false;
    }
  }

  if ($inserted || $emailSent) {
    echo json_encode(['ok'=>true,'notified_in_app'=>$inserted,'emailed'=>$emailSent,'message'=>'Nudge sent.']); exit;
  }
  $why = !$userId ? 'No linked user account found for this patient/registrant.' :
        (!table_exists($con,'user_notifications') ? 'user_notifications table missing.' : 'Insert failed.');
  echo json_encode(['ok'=>false,'error'=>$why]); exit;
}

/* ---------- AJAX: activity feed (clickable items) ---------- */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'activity_feed') {
  header('Content-Type: application/json; charset=utf-8');

  $items = [];

  // admin_events
  if (table_exists($con, 'admin_events')) {
    $sql = "SELECT e.*, c.first_name, c.last_name
            FROM admin_events e
            LEFT JOIN caregivers c ON c.id = e.caregiver_id
            ORDER BY e.created_at DESC LIMIT 30";
    $res = @$con->query($sql);
    if ($res) while ($r = $res->fetch_assoc()) {
      $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
      $who = $name ?: ('User #' . ($r['actor_user_id'] ?? '?'));
      $txt = trim(($r['entity'] ?? 'update') . ': ' . ($r['action'] ?? 'changed'));
      if (!empty($r['details'])) $txt .= ' — ' . $r['details'];
      $items[] = [
        'ts' => $r['created_at'] ?? null,
        'who' => $who,
        'text' => $txt,
        'icon' => 'bell',
        'kind' => 'caregiver',
        'id' => (int)($r['caregiver_id'] ?? 0)
      ];
    }
  }

  // caregiver_availability_overrides
  if (table_exists($con, 'caregiver_availability_overrides')) {
    $sql = "SELECT o.id, o.caregiver_id, o.date, o.start_time, o.end_time, o.is_available, o.note, o.created_at,
                   c.first_name, c.last_name
            FROM caregiver_availability_overrides o
            JOIN caregivers c ON c.id = o.caregiver_id
            ORDER BY o.created_at DESC LIMIT 30";
    $res = @$con->query($sql);
    if ($res) while ($r = $res->fetch_assoc()) {
      $who = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
      $s = $r['start_time'] ? substr($r['start_time'], 0, 5) : '';
      $e = $r['end_time'] ? substr($r['end_time'], 0, 5) : '';
      $range = ($s || $e) ? " {$s}" . ($e ? "–{$e}" : '') : '';
      $type = ((string)($r['is_available'] ?? '1') === '1') ? 'Available only' : 'Unavailable';
      $note = $r['note'] ? " ({$r['note']})" : '';
      $items[] = [
        'ts' => $r['created_at'] ?? null,
        'who' => $who,
        'text' => "Override: {$type} on {$r['date']}{$range}{$note}",
        'icon' => ((string)($r['is_available'] ?? '1') === '1') ? 'calendar-check' : 'calendar-x',
        'kind' => 'caregiver',
        'id' => (int)$r['caregiver_id']
      ];
    }
  }

  // caregiver_availability_status
  if (table_exists($con, 'caregiver_availability_status')) {
    $col = column_exists($con, 'caregiver_availability_status', 'updated_at') ? 'updated_at' : 'created_at';
    $sql = "SELECT s.caregiver_id, s.is_accepting, s.available_until, s.{$col} ts,
                   c.first_name, c.last_name
            FROM caregiver_availability_status s
            JOIN caregivers c ON c.id = s.caregiver_id
            ORDER BY s.{$col} DESC LIMIT 30";
    $res = @$con->query($sql);
    if ($res) while ($r = $res->fetch_assoc()) {
      $who = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
      $acc = ((int)($r['is_accepting'] ?? 0) === 1) ? 'Accepting' : 'Not accepting';
      $txt = "Availability status: {$acc}";
      if (!empty($r['available_until'])) $txt .= " (now until {$r['available_until']})";
      $items[] = [
        'ts' => $r['ts'] ?? null,
        'who' => $who,
        'text' => $txt,
        'icon' => ((int)($r['is_accepting'] ?? 0) === 1) ? 'toggle-on' : 'toggle-off',
        'kind' => 'caregiver',
        'id' => (int)$r['caregiver_id']
      ];
    }
  }

  // patients via admin_notifications (preferred)
  if (table_exists($con, 'admin_notifications')) {
    $sql = "SELECT n.*, p.full_name
            FROM admin_notifications n
            LEFT JOIN patients p ON p.id = n.target_id AND n.target_type='patient'
            WHERE n.type IN ('patient_register','patient_intake_updated')
            ORDER BY n.created_at DESC LIMIT 30";
    $res = @$con->query($sql);
    if ($res) while ($r = $res->fetch_assoc()) {
      $items[] = [
        'ts'   => $r['created_at'] ?? null,
        'who'  => $r['full_name'] ?: 'New patient',
        'text' => ($r['type'] === 'patient_register' ? 'New patient registered' : 'Patient intake updated'),
        'icon' => 'person-gear',
        'kind' => 'patient',
        'id'   => (int)($r['target_id'] ?? 0)
      ];
    }
  } else {
    // Fallback — last patients directly
    if (table_exists($con, 'patients') && column_exists($con, 'patients', 'created_at')) {
      $res = @$con->query("SELECT id, COALESCE(full_name,name,'Patient') nm, created_at FROM patients ORDER BY created_at DESC LIMIT 15");
      if ($res) while ($r = $res->fetch_assoc()) {
        $items[] = [
          'ts' => $r['created_at'] ?? null,
          'who' => $r['nm'],
          'text' => 'New patient registered',
          'icon' => 'person-gear',
          'kind' => 'patient',
          'id' => (int)$r['id']
        ];
      }
    }
  }

  // normalize, sort, de-dup
  $items = array_filter($items, fn($x) => !empty($x['ts']));
  usort($items, fn($a,$b) => strcmp($b['ts'], $a['ts']));
  $uniq=[]; $out=[];
  foreach ($items as $it) {
    $k = md5(($it['ts'] ?? '') . '|' . ($it['who'] ?? '') . '|' . ($it['text'] ?? ''));
    if (!isset($uniq[$k])) { $uniq[$k]=1; $out[]=$it; }
    if (count($out) >= 25) break;
  }
  echo json_encode(['ok'=>true,'items'=>$out], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ============================================================
   ==================== METRICS & QUERIES ======================
   ============================================================ */

$cards = [
  'total_caregivers' => 0,
  'new_caregivers_7d' => 0,
  'wow_caregivers' => 0.0,
  'active_patients' => 0,
  'active_assignments' => 0,
  'avg_rating' => 0.0,
];
$dailySignupLabels = $dailySignupData = [];
$revLabels = $revSeries = [];
$profitAmount = 0.0;
$profitWow = 0.0;
$profitEstimated = false;

/* total caregivers */
$cards['total_caregivers'] = tryQuery(function () use ($con) {
  $sql = "SELECT COUNT(*) FROM caregivers";
  return (int) $con->query($sql)->fetch_row()[0];
}, 0);

/* new caregivers & WoW */
$tmp = tryQuery(function () use ($con) {
  $new = (int) $con->query("SELECT COUNT(*) FROM users WHERE role='caregiver' AND created_at >= NOW() - INTERVAL 7 DAY")->fetch_row()[0];
  $prev = (int) $con->query("SELECT COUNT(*) FROM users WHERE role='caregiver' AND created_at >= NOW() - INTERVAL 14 DAY AND created_at < NOW() - INTERVAL 7 DAY")->fetch_row()[0];
  $wow = ($prev > 0) ? (($new - $prev) / $prev * 100.0) : ($new > 0 ? 100.0 : 0.0);
  return ['new' => $new, 'wow' => $wow];
}, ['new' => 0, 'wow' => 0.0]);
$cards['new_caregivers_7d'] = $tmp['new'];
$cards['wow_caregivers'] = $tmp['wow'];

/* active patients */
$cards['active_patients'] = tryQuery(function () use ($con) {
  return (int) $con->query("SELECT COUNT(*) FROM patients WHERE status IN ('active','ongoing')")->fetch_row()[0];
}, 0);

/* active assignments now */
$cards['active_assignments'] = tryQuery(function () use ($con) {
  return (int) $con->query("SELECT COUNT(*) FROM service_assignments WHERE status IN ('ongoing','active')")->fetch_row()[0];
}, 0);

/* avg rating */
$cards['avg_rating'] = tryQuery(function () use ($con) {
  $r = $con->query("SELECT AVG(rating) FROM caregiver_reviews")->fetch_row()[0];
  return round((float) $r, 2);
}, 0.0);

/* line: caregiver signups 14d */
$tmp = tryQuery(function () use ($con) {
  $q = $con->query("
        SELECT DATE(created_at) d, COUNT(*) c
        FROM users
        WHERE role='caregiver' AND created_at >= CURDATE() - INTERVAL 13 DAY
        GROUP BY DATE(created_at) ORDER BY d ASC
    ");
  $map = [];
  while ($q && ($row = $q->fetch_assoc())) $map[$row['d']] = (int) $row['c'];
  $labels = [];
  $vals = [];
  for ($i = 13; $i >= 0; $i--) {
    $d = (new DateTime("-{$i} days"))->format('Y-m-d');
    $labels[] = (new DateTime($d))->format('M j');
    $vals[] = $map[$d] ?? 0;
  }
  return [$labels, $vals];
}, [[], []]);
$dailySignupLabels = $tmp[0];
$dailySignupData   = $tmp[1];

/* top caregivers (with reviews) */
$topCaregivers = tryQuery(function () use ($con) {
  $sql = "
      SELECT c.id, CONCAT(c.first_name,' ',c.last_name) name, c.photo_path,
             ROUND(AVG(r.rating),2) avg_rate, COUNT(r.id) cnt
      FROM caregivers c
      JOIN users u ON u.id = c.user_id
      LEFT JOIN caregiver_reviews r ON r.caregiver_id = c.id
      GROUP BY c.id
      HAVING cnt > 0
      ORDER BY avg_rate DESC, cnt DESC
      LIMIT 5
    ";
  $res = $con->query($sql);
  $out = [];
  while ($res && ($row = $res->fetch_assoc())) {
    $out[] = ['id' => $row['id'], 'name' => $row['name'], 'photo' => $row['photo_path'], 'rating' => $row['avg_rate'], 'reviews' => $row['cnt']];
  }
  return $out;
}, []);

/* currently serving */
$servingNow = tryQuery(function () use ($con) {
  $sql = "
      SELECT sa.id, CONCAT(c.first_name,' ',c.last_name) caregiver,
             IFNULL(p.full_name, CONCAT('Patient #', sa.patient_id)) patient,
             sa.start_time
      FROM service_assignments sa
      JOIN caregivers c ON c.id = sa.caregiver_id
      LEFT JOIN patients p ON p.id = sa.patient_id
      WHERE sa.status IN ('ongoing','active')
      ORDER BY sa.start_time DESC
      LIMIT 8
    ";
  $res = $con->query($sql);
  $out = [];
  while ($res && ($row = $res->fetch_assoc())) {
    $out[] = ['caregiver' => $row['caregiver'], 'patient' => $row['patient'], 'since' => $row['start_time']];
  }
  return $out;
}, []);

/* free caregivers */
$freeCaregivers = tryQuery(function () use ($con) {
  $sql = "
      SELECT c.id, CONCAT(c.first_name,' ',c.last_name) name, c.caregiver_type
      FROM caregivers c
      LEFT JOIN service_assignments sa
        ON sa.caregiver_id = c.id AND sa.status IN ('ongoing','active')
      WHERE sa.id IS NULL
      ORDER BY c.id DESC
      LIMIT 8
    ";
  $res = $con->query($sql);
  $out = [];
  while ($res && ($row = $res->fetch_assoc())) {
    $out[] = ['id' => $row['id'], 'name' => $row['name'], 'type' => $row['caregiver_type']];
  }
  return $out;
}, []);

/* pending caregivers (for approvals) */
$pendingCgs = tryQuery(function () use ($con) {
  $res = $con->query("SELECT id, CONCAT(first_name,' ',last_name) name, caregiver_type, present_district FROM caregivers WHERE status='pending' ORDER BY id DESC LIMIT 8");
  $out = [];
  while ($res && ($row = $res->fetch_assoc())) $out[] = $row;
  return $out;
}, []);

/* profit + revenue series */
$profit = tryQuery(function () use ($con, $PROFIT_MARGIN) {
  $sumOrNull = function (string $sql) use ($con) {
    $res = @$con->query($sql);
    if (!$res) return null;
    $row = $res->fetch_row();
    return (float) ($row[0] ?? 0);
  };
  $rev30 = $sumOrNull("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status IN ('paid','completed','success') AND created_at >= NOW() - INTERVAL 30 DAY");
  $revPrev30 = $sumOrNull("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status IN ('paid','completed','success') AND created_at >= NOW() - INTERVAL 60 DAY AND created_at < NOW() - INTERVAL 30 DAY");
  $pay30 = $sumOrNull("SELECT COALESCE(SUM(amount),0) FROM payouts  WHERE status IN ('paid','completed','success') AND created_at >= NOW() - INTERVAL 30 DAY");
  $payPrev30 = $sumOrNull("SELECT COALESCE(SUM(amount),0) FROM payouts  WHERE status IN ('paid','completed','success') AND created_at >= NOW() - INTERVAL 60 DAY AND created_at < NOW() - INTERVAL 30 DAY");

  $estimated = false;
  if ($rev30 === null) { $rev30 = 0; $revPrev30 = 0; }
  if ($pay30 === null) {
    $estimated = true;
    $profit30 = $rev30 * $PROFIT_MARGIN;
    $profitOld = $revPrev30 * $PROFIT_MARGIN;
  } else {
    $profit30 = $rev30 - $pay30;
    $profitOld = $revPrev30 - $payPrev30;
  }
  $wow = ($profitOld > 0) ? (($profit30 - $profitOld) / $profitOld * 100.0) : ($profit30 > 0 ? 100.0 : 0.0);

  // revenue series last 14 days
  $res = @$con->query("
        SELECT DATE(created_at) d, SUM(amount) s
        FROM payments
        WHERE status IN ('paid','completed','success') AND created_at >= CURDATE() - INTERVAL 13 DAY
        GROUP BY DATE(created_at) ORDER BY d ASC
  ");
  $map = [];
  if ($res) while ($r = $res->fetch_assoc()) $map[$r['d']] = (float) $r['s'];
  $labels = []; $vals = [];
  for ($i = 13; $i >= 0; $i--) {
    $d = (new DateTime("-{$i} days"))->format('Y-m-d');
    $labels[] = (new DateTime($d))->format('M j');
    $vals[] = $map[$d] ?? 0.0;
  }

  return ['profit'=>$profit30,'wow'=>$wow,'labels'=>$labels,'series'=>$vals,'estimated'=>$estimated];
}, ['profit'=>0.0,'wow'=>0.0,'labels'=>[],'series'=>[],'estimated'=>false]);
$profitAmount = $profit['profit'];
$profitWow = $profit['wow'];
$revLabels = $profit['labels'];
$revSeries = $profit['series'];
$profitEstimated = $profit['estimated'];

/* gender split */
$gender = tryQuery(function () use ($con) {
  $res = $con->query("SELECT LOWER(gender) g, COUNT(*) c FROM caregivers GROUP BY LOWER(gender)");
  $counts = ['male' => 0, 'female' => 0, 'other' => 0];
  while ($res && ($r = $res->fetch_assoc())) {
    $g = $r['g'];
    if (!isset($counts[$g])) $counts['other'] += (int) $r['c'];
    else $counts[$g] = (int) $r['c'];
  }
  $total = array_sum($counts);
  $pct = $total ? [
    'male'   => round($counts['male'] / $total * 100, 1),
    'female' => round($counts['female'] / $total * 100, 1),
    'other'  => round($counts['other'] / $total * 100, 1),
  ] : ['male' => 0, 'female' => 0, 'other' => 0];
  return ['counts' => $counts, 'pct' => $pct, 'total' => $total];
}, ['counts' => ['male'=>0,'female'=>0,'other'=>0], 'pct' => ['male'=>0,'female'=>0,'other'=>0], 'total'=>0]);

/* Latest 5 patients (robust) */
$recentPatients = tryQuery(function () use ($con) {
  if (!table_exists($con, 'patients')) return [];
  $orderCol = column_exists($con, 'patients', 'created_at') ? 'created_at' : 'id';
  $nameExpr = column_exists($con, 'patients', 'full_name')
    ? 'full_name'
    : (column_exists($con, 'patients', 'name') ? 'name' : "CONCAT('Patient #', id)");
  $col = function (string $c) use ($con) { return column_exists($con, 'patients', $c) ? $c : 'NULL'; };
  $cols = "
    id,
    $nameExpr AS full_name,
    {$col('email')} AS email,
    {$col('registrant_name')} AS registrant_name,
    {$col('registrant_email')} AS registrant_email,
    $orderCol AS created_at,
    {$col('caregiver_type')} AS caregiver_type,
    {$col('shift_type')} AS shift_type,
    {$col('service_address')} AS service_address,
    {$col('language_pref')} AS language_pref,
    {$col('caregiver_gender_pref')} AS caregiver_gender_pref,
    {$col('hours_per_day')} AS hours_per_day,
    {$col('days_per_week')} AS days_per_week,
    {$col('case_status')} AS case_status
  ";
  $sql = "SELECT $cols FROM patients ORDER BY $orderCol DESC LIMIT 5";
  $res = @$con->query($sql);
  $out = [];
  while ($res && ($row = $res->fetch_assoc())) $out[] = $row;
  return $out;
}, []);

/* intake completion percent helper */
function intake_percent(array $p): int {
  $keys = ['caregiver_type', 'shift_type', 'service_address', 'language_pref', 'caregiver_gender_pref', 'hours_per_day', 'days_per_week', 'case_status'];
  $have=0; $tot=0;
  foreach ($keys as $k) { $tot++; if (trim((string)($p[$k] ?? '')) !== '') $have++; }
  return (int)round($tot ? ($have / $tot * 100) : 0);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin Dashboard | CGMS</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <style>
    :root { --pri:#1d4ed8; --acc:#06b6d4; --ink:#0f172a; --mut:#64748b; }
    body { background:#f6f9fc; }
    .sidebar { min-height:100vh; background:#0b1220; }
    .sidebar a { color:#cbd5e1; text-decoration:none; display:flex; align-items:center; gap:.6rem; padding:.6rem .9rem; border-radius:.6rem; }
    .sidebar a.active, .sidebar a:hover { background:#111827; color:#fff; }
    .brand { color:#fff; font-weight:800; letter-spacing:.3px; }
    .card-soft { border:1px solid rgba(2,6,23,.06); border-radius:16px; box-shadow:0 12px 32px rgba(2,6,23,.06); }
    .kpi { font-weight:800; font-size:1.8rem; }
    .kpi-sub { color:var(--mut); font-size:.9rem; }
    .badge-delta.up { background:rgba(16,185,129,.12); color:#065f46; border:1px solid rgba(16,185,129,.2); }
    .badge-delta.down { background:rgba(239,68,68,.12); color:#7f1d1d; border:1px solid rgba(239,68,68,.2); }
    .table>:not(caption)>*>* { padding:.6rem .7rem; }
    .progress-thin { height:8px; }
    .bell-wrap { position:relative; }
    .bell-dot { position:absolute; right:-2px; top:-2px; width:10px; height:10px; background:#ef4444; border-radius:50%; display:none; }
    .activity-list .item { display:flex; gap:.6rem; padding:.55rem .2rem; border-bottom:1px dashed rgba(2,6,23,.07); }
    .activity-list .item:last-child { border-bottom:none; }
    .activity-list .ic { width:28px; height:28px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; background:#eef2ff; color:#1d4ed8; }
    .activity-list .txt { font-size:.925rem; }
    .activity-list .meta { color:#64748b; font-size:.8rem; }
    .dropdown-menu-notif { width:360px; max-height:420px; overflow:auto; }
    .activity-list .item.item-click { cursor:pointer; border-radius:10px; }
    .activity-list .item.item-click:hover { background:#f8fafc; }
  </style>
</head>
<body>
  <div class="container-fluid">
    <div class="row">
      <!-- Sidebar -->
      <div class="col-12 col-md-3 col-lg-2 sidebar p-3">
        <div class="d-flex align-items-center justify-content-between mb-3">
          <div class="brand">CGMS Admin</div>
          <a class="btn btn-sm btn-outline-light" href="<?php echo safe(url('admin/logout.php')); ?>">Logout</a>
        </div>
        <nav class="d-grid gap-1">
          <a class="active" href="#"><i class="bi bi-speedometer2"></i> Dashboard</a>
          <a href="#"><i class="bi bi-people"></i> Caregivers</a>
          <a href="<?php echo safe(url('admin/patients.php')); ?>"><i class="bi bi-person-heart"></i> Patients</a>
          <a href="#"><i class="bi bi-arrow-left-right"></i> Assignments</a>
          <a href="#"><i class="bi bi-chat-dots"></i> Reviews</a>
          <a href="#"><i class="bi bi-gear"></i> Settings</a>
        </nav>
      </div>

      <!-- Main -->
      <div class="col-12 col-md-9 col-lg-10 p-3 p-md-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
          <h3 class="mb-0">Overview</h3>
          <div class="d-flex align-items-center gap-3">
            <div class="dropdown">
              <button class="btn btn-sm btn-outline-secondary bell-wrap" data-bs-toggle="dropdown" aria-expanded="false" id="btnBell">
                <i class="bi bi-bell"></i>
                <span class="bell-dot" id="bellDot"></span>
              </button>
              <div class="dropdown-menu dropdown-menu-end dropdown-menu-notif p-0">
                <div class="p-2 border-bottom d-flex align-items-center justify-content-between">
                  <div class="fw-semibold">Notifications</div>
                  <button class="btn btn-link btn-sm" id="btnMarkSeen">Mark all as read</button>
                </div>
                <div id="notifList" class="activity-list p-2"></div>
              </div>
            </div>
            <div class="text-muted small">Welcome, <?php echo safe($_SESSION['user_name'] ?? 'Administrator'); ?></div>
          </div>
        </div>

        <!-- KPI row -->
        <div class="row g-3">
          <div class="col-md-6 col-lg-3">
            <div class="card card-soft p-3">
              <div class="kpi"><?php echo (int) $cards['total_caregivers']; ?></div>
              <div class="kpi-sub">Total Caregivers</div>
            </div>
          </div>
          <div class="col-md-6 col-lg-3">
            <div class="card card-soft p-3">
              <div class="d-flex align-items-baseline justify-content-between">
                <div class="kpi"><?php echo (int) $cards['new_caregivers_7d']; ?></div>
                <?php $up = $cards['wow_caregivers'] >= 0; ?>
                <span class="badge badge-delta <?php echo $up ? 'up' : 'down'; ?>">
                  <?php echo ($up ? '+' : '') . number_format($cards['wow_caregivers'], 1); ?>%
                </span>
              </div>
              <div class="kpi-sub">New last 7 days (WoW)</div>
            </div>
          </div>
          <div class="col-md-6 col-lg-3">
            <div class="card card-soft p-3">
              <div class="kpi"><?php echo (int) $cards['active_patients']; ?></div>
              <div class="kpi-sub">Active Patients</div>
            </div>
          </div>
          <div class="col-md-6 col-lg-3">
            <div class="card card-soft p-3">
              <div class="kpi"><?php echo (int) $cards['active_assignments']; ?></div>
              <div class="kpi-sub">Serving Now</div>
            </div>
          </div>
        </div>

        <!-- Profit & Gender -->
        <div class="row g-3 mt-1">
          <div class="col-lg-6">
            <div class="card card-soft p-3">
              <div class="d-flex align-items-baseline justify-content-between">
                <div>
                  <h6 class="mb-1">Profit (last 30 days) <?php if ($profitEstimated) echo '<span class="text-muted small">(est.)</span>'; ?></h6>
                  <div class="kpi"><?php echo $CURRENCY . ' ' . number_format($profitAmount, 0); ?></div>
                </div>
                <?php $up = $profitWow >= 0; ?>
                <span class="badge badge-delta <?php echo $up ? 'up' : 'down'; ?>">
                  <?php echo ($up ? '+' : '') . number_format($profitWow, 1); ?>%
                </span>
              </div>
              <div class="kpi-sub">Based on payments<?php echo $profitEstimated ? " × margin" : " − payouts"; ?></div>
              <canvas id="barRevenue" height="120" class="mt-2"></canvas>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card card-soft p-3">
              <div class="d-flex align-items-center justify-content-between">
                <h6 class="mb-0">Caregiver Gender Split</h6>
                <span class="text-muted small"><?php echo (int) $gender['total']; ?> total</span>
              </div>
              <div class="row align-items-center mt-2">
                <div class="col-5"><canvas id="donutGender" height="150"></canvas></div>
                <div class="col-7">
                  <div class="small mb-1">Male <span class="float-end"><?php echo $gender['pct']['male']; ?>%</span></div>
                  <div class="progress progress-thin mb-2"><div class="progress-bar" role="progressbar" style="width: <?php echo $gender['pct']['male']; ?>%"></div></div>
                  <div class="small mb-1">Female <span class="float-end"><?php echo $gender['pct']['female']; ?>%</span></div>
                  <div class="progress progress-thin mb-2"><div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $gender['pct']['female']; ?>%"></div></div>
                  <div class="small mb-1">Other <span class="float-end"><?php echo $gender['pct']['other']; ?>%</span></div>
                  <div class="progress progress-thin"><div class="progress-bar bg-secondary" role="progressbar" style="width: <?php echo $gender['pct']['other']; ?>%"></div></div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Charts + Top caregivers -->
        <div class="row g-3 mt-1">
          <div class="col-lg-7">
            <div class="card card-soft p-3">
              <div class="d-flex justify-content-between align-items-center"><h6 class="mb-0">Caregiver Signups (14 days)</h6></div>
              <canvas id="lineSignups" height="140"></canvas>
            </div>
          </div>
          <div class="col-lg-5">
            <div class="card card-soft p-3">
              <div class="d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Average Rating</h6>
                <span class="badge bg-primary-subtle text-primary border border-primary">
                  <?php echo number_format($cards['avg_rating'], 2); ?> / 5
                </span>
              </div>
              <div class="text-muted small">Top caregivers by customer reviews</div>
              <div class="table-responsive mt-2">
                <table class="table align-middle">
                  <thead><tr><th>Caregiver</th><th>Rating</th><th>Reviews</th></tr></thead>
                  <tbody>
                    <?php if (!$topCaregivers): ?>
                      <tr><td colspan="3" class="text-muted">No reviews yet.</td></tr>
                    <?php else: foreach ($topCaregivers as $cg): ?>
                      <tr>
                        <td>
                          <div class="d-flex align-items-center gap-2">
                            <?php if (!empty($cg['photo'])): ?>
                              <img src="<?php echo safe('../' . $cg['photo']); ?>" width="34" height="34" class="rounded-circle" alt="">
                            <?php else: ?>
                              <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center" style="width:34px;height:34px;"><i class="bi bi-person"></i></div>
                            <?php endif; ?>
                            <div><?php echo safe($cg['name']); ?></div>
                          </div>
                        </td>
                        <td><?php echo safe($cg['rating']); ?></td>
                        <td><?php echo safe($cg['reviews']); ?></td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <!-- Latest Registered Patients -->
        <div class="row g-3 mt-1">
          <div class="col-12">
            <div class="card card-soft p-3">
              <div class="d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Latest Registered Patients</h6>
                <a class="btn btn-sm btn-outline-primary" href="<?php echo safe(url('admin/patients.php')); ?>"><i class="bi bi-list-ul me-1"></i> View All</a>
              </div>
              <div class="table-responsive mt-2">
                <table class="table align-middle">
                  <thead><tr><th>Patient</th><th>Registered</th><th style="min-width:180px;">Intake</th><th class="text-end">Actions</th></tr></thead>
                  <tbody>
                    <?php
                    $rows = is_array($recentPatients) ? array_slice($recentPatients, 0, 5) : [];
                    if (!$rows): ?>
                      <tr><td colspan="4" class="text-muted">No patients yet.</td></tr>
                    <?php else: foreach ($rows as $p):
                      $ptName = ($p['full_name'] ?? null) ?: ($p['name'] ?? ('Patient #' . (int)$p['id']));
                      $regTs  = ($p['created_at'] ?? null) ?: ($p['updated_at'] ?? null);
                      $pct    = intake_percent($p);
                    ?>
                      <tr>
                        <td>
                          <div class="fw-semibold"><?php echo safe($ptName); ?></div>
                          <div class="small text-muted"><?php echo safe($p['email'] ?? ($p['registrant_email'] ?? '')); ?></div>
                        </td>
                        <td class="text-muted small"><?php echo $regTs ? safe(time_ago($regTs)) : '—'; ?></td>
                        <td>
                          <div class="progress progress-thin">
                            <div class="progress-bar <?php echo $pct >= 70 ? 'bg-success' : ($pct >= 40 ? 'bg-warning' : ''); ?>" role="progressbar" style="width: <?php echo $pct; ?>%"></div>
                          </div>
                          <div class="small text-muted mt-1"><?php echo $pct; ?>%</div>
                        </td>
                        <td class="text-end">
                          <button class="btn btn-sm btn-secondary me-1" onclick="viewPatient(<?php echo (int)$p['id']; ?>)"><i class="bi bi-person-vcard"></i> Intake</button>
                          <button class="btn btn-sm btn-success" onclick="nudgePatient(<?php echo (int)$p['id']; ?>)"><i class="bi bi-bell"></i> Notify</button>
                        </td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <!-- Currently Serving + Pending Approvals -->
        <div class="row g-3 mt-1">
          <div class="col-lg-6">
            <div class="card card-soft p-3">
              <div class="d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Currently Serving</h6>
                <span class="text-muted small"><?php echo count($servingNow); ?> records</span>
              </div>
              <div class="table-responsive mt-2">
                <table class="table">
                  <thead><tr><th>Caregiver</th><th>Patient</th><th>Since</th></tr></thead>
                  <tbody>
                    <?php if (!$servingNow): ?>
                      <tr><td colspan="3" class="text-muted">No active assignments yet.</td></tr>
                    <?php else: foreach ($servingNow as $a): ?>
                      <tr>
                        <td><?php echo safe($a['caregiver']); ?></td>
                        <td><?php echo safe($a['patient']); ?></td>
                        <td><?php echo safe((new DateTime($a['since']))->format('M j, g:ia')); ?></td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="card card-soft p-3">
              <div class="d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Pending Approvals</h6>
                <span class="text-muted small"><?php echo count($pendingCgs); ?> waiting</span>
              </div>
              <div class="table-responsive mt-2">
                <table class="table">
                  <thead><tr><th>Caregiver</th><th>Type</th><th>District</th><th class="text-end">Actions</th></tr></thead>
                  <tbody>
                    <?php if (!$pendingCgs): ?>
                      <tr><td colspan="4" class="text-muted">No pending caregivers.</td></tr>
                    <?php else: foreach ($pendingCgs as $p): ?>
                      <tr>
                        <td><?php echo safe($p['name']); ?></td>
                        <td><?php echo safe($p['caregiver_type']); ?></td>
                        <td><?php echo safe($p['present_district']); ?></td>
                        <td class="text-end">
                          <a href="#" class="btn btn-sm btn-secondary me-2" onclick="viewCg(<?php echo (int)$p['id']; ?>);return false;"><i class="bi bi-person-badge"></i> View</a>
                          <a href="#" class="btn btn-sm btn-success me-1" onclick="approveCg(<?php echo (int)$p['id']; ?>);return false;">Approve</a>
                          <a href="#" class="btn btn-sm btn-outline-danger" onclick="rejectCg(<?php echo (int)$p['id']; ?>);return false;">Reject</a>
                        </td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <!-- Free caregivers + Recent Activity -->
        <div class="row g-3 mt-1">
          <div class="col-lg-6">
            <div class="card card-soft p-3">
              <div class="d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Free Caregivers (quick tag)</h6>
                <span class="text-muted small"><?php echo count($freeCaregivers); ?> found</span>
              </div>
              <div class="table-responsive mt-2">
                <table class="table">
                  <thead><tr><th>Name</th><th>Type</th><th>Assign to Patient</th></tr></thead>
                  <tbody>
                    <?php if (!$freeCaregivers): ?>
                      <tr><td colspan="3" class="text-muted">All caregivers are busy or data not ready.</td></tr>
                    <?php else: foreach ($freeCaregivers as $f): ?>
                      <tr>
                        <td><?php echo safe($f['name']); ?></td>
                        <td><?php echo safe($f['type']); ?></td>
                        <td style="max-width:280px;">
                          <div class="input-group input-group-sm">
                            <input class="form-control" placeholder="Patient ID">
                            <button class="btn btn-outline-primary" onclick="assignToPatient(<?php echo (int)$f['id']; ?>, this.previousElementSibling.value)">Assign</button>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="card card-soft p-3">
              <div class="d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Recent Activity</h6>
                <small class="text-muted">Caregiver updates & patient registrations</small>
              </div>
              <div id="activityList" class="activity-list mt-2"></div>
            </div>
          </div>
        </div>

        <footer class="text-muted small mt-4">© <?php echo date('Y'); ?> CGMS Admin</footer>
      </div>
    </div>
  </div>

  <!-- Caregiver Resume Modal -->
  <div class="modal fade" id="cgModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Caregiver Resume</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" id="cgModalBody">Loading…</div>
        <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
      </div>
    </div>
  </div>

  <!-- Patient Resume Modal -->
  <div class="modal fade" id="ptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Patient Resume</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" id="ptModalBody">Loading…</div>
        <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    /* ===== Charts ===== */
    (() => {
      const ctx1 = document.getElementById('lineSignups');
      if (ctx1) new Chart(ctx1, {
        type: 'line',
        data: {
          labels: <?php echo json_encode($dailySignupLabels); ?>,
          datasets: [{ label: 'Signups', data: <?php echo json_encode($dailySignupData); ?>, tension:.35, fill:true }]
        },
        options: { plugins:{ legend:{ display:false }}, scales:{ y:{ beginAtZero:true } } }
      });

      const ctx2 = document.getElementById('barRevenue');
      if (ctx2) new Chart(ctx2, {
        type: 'bar',
        data: { labels: <?php echo json_encode($revLabels); ?>, datasets: [{ label: 'Revenue (last 14d)', data: <?php echo json_encode($revSeries); ?> }] },
        options: { plugins:{ legend:{ display:false }}, scales:{ y:{ beginAtZero:true } } }
      });

      const ctx3 = document.getElementById('donutGender');
      if (ctx3) new Chart(ctx3, {
        type: 'doughnut',
        data: { labels:['Male','Female','Other'], datasets:[{ data:[<?php echo (int)$gender['counts']['male']; ?>, <?php echo (int)$gender['counts']['female']; ?>, <?php echo (int)$gender['counts']['other']; ?>] }] },
        options: { plugins:{ legend:{ display:false }}, cutout:'65%' }
      });
    })();

    /* ===== Actions (wire real endpoints later) ===== */
    function approveCg(id) { alert('Approve caregiver #'+id+' (create endpoint to set caregivers.status=\"verified\")'); }
    function rejectCg(id)  { alert('Reject caregiver #'+id+' (create endpoint to set caregivers.status=\"rejected\")'); }
    function assignToPatient(cgId, patientId) {
      if (!patientId) return alert('Enter a Patient ID first');
      alert('Assign caregiver #'+cgId+' to patient #'+patientId+' (create endpoint to insert into service_assignments)');
    }

    /* ===== Resume viewers ===== */
    function viewCg(id) {
      const body = document.getElementById('cgModalBody');
      body.innerHTML = 'Loading…';
      const modal = new bootstrap.Modal(document.getElementById('cgModal'));
      modal.show();
      fetch('dashboard.php?ajax=caregiver_resume&id='+encodeURIComponent(id))
        .then(r=>r.text()).then(html=>{ body.innerHTML = html; })
        .catch(()=>{ body.innerHTML = '<div class="text-danger">Failed to load resume.</div>'; });
    }
    function viewPatient(id) {
      const body = document.getElementById('ptModalBody');
      body.innerHTML = 'Loading…';
      const modal = new bootstrap.Modal(document.getElementById('ptModal'));
      modal.show();
      fetch('dashboard.php?ajax=patient_resume&id='+encodeURIComponent(id))
        .then(r=>r.text()).then(html=>{ body.innerHTML = html; })
        .catch(()=>{ body.innerHTML = '<div class="text-danger">Failed to load patient resume.</div>'; });
    }

    /* ===== Notify patient (in-app + email) ===== */
    function nudgePatient(id) {
      if (!confirm('Send a notification to complete the intake?')) return;
      fetch('dashboard.php?ajax=nudge_patient', {
        method:'POST',
        headers:{ 'Content-Type':'application/x-www-form-urlencoded' },
        body:'id='+encodeURIComponent(id)
      })
      .then(r=>r.json())
      .then(j=>{ if (j.ok) alert('Notification sent.'); else alert('Failed: '+(j.error||'Unknown error')); })
      .catch(()=>alert('Network error.'));
    }

    /* ===== Activity / Notifications (clickable) ===== */
    const iconMap = {
      'calendar-check': 'bi-calendar-check',
      'calendar-x': 'bi-calendar-x',
      'toggle-on': 'bi-toggle-on',
      'toggle-off': 'bi-toggle-off',
      'person-gear': 'bi-person-gear',
      'bell': 'bi-bell'
    };
    function timeAgo(ts) {
      const d = new Date((ts||'').replace(' ','T'));
      const s = Math.floor((Date.now() - d.getTime())/1000);
      if (isNaN(s)) return ts || '';
      if (s < 60) return s+'s ago';
      const m = Math.floor(s/60); if (m < 60) return m+'m ago';
      const h = Math.floor(m/60); if (h < 24) return h+'h ago';
      const d2 = Math.floor(h/24); if (d2 < 7) return d2+'d ago';
      return d.toLocaleString();
    }
    function renderActivity(listEl, items) {
      listEl.innerHTML = items.length ? items.map(it => {
        const ic = iconMap[it.icon] || 'bi-bell';
        const click = (it.kind && it.id) ? 'item-click' : '';
        const kindAttr = it.kind ? ` data-kind="${it.kind}"` : '';
        const idAttr = it.id ? ` data-id="${it.id}"` : '';
        return `<div class="item ${click}"${kindAttr}${idAttr}>
          <div class="ic"><i class="bi ${ic}"></i></div>
          <div class="flex-grow-1">
            <div class="txt"><strong>${it.who}</strong> — ${it.text}</div>
            <div class="meta">${timeAgo(it.ts)}</div>
          </div>
        </div>`;
      }).join('') : '<div class="text-muted small p-2">No recent activity.</div>';
    }
    function wireActivityClicks(containerId) {
      const el = document.getElementById(containerId);
      if (!el) return;
      el.addEventListener('click', (e) => {
        const item = e.target.closest('.item.item-click');
        if (!item) return;
        const kind = item.getAttribute('data-kind');
        const id = item.getAttribute('data-id');
        if (kind === 'patient' && id) viewPatient(id);
        if (kind === 'caregiver' && id) viewCg(id);
      });
    }

    let latestTs = null;
    function fetchActivity(showBellDot = true) {
      return fetch('dashboard.php?ajax=activity_feed')
        .then(r=>r.json()).then(j=>{
          if (!j.ok) return;
          const items = j.items || [];
          renderActivity(document.getElementById('activityList'), items);
          renderActivity(document.getElementById('notifList'), items);
          wireActivityClicks('activityList'); wireActivityClicks('notifList');
          if (items.length) {
            const newest = items[0].ts;
            latestTs = newest;
            const seen = localStorage.getItem('adminFeedSeenAt') || '';
            const dot = document.getElementById('bellDot');
            if (showBellDot && (!seen || newest > seen)) dot.style.display = 'block';
          }
        }).catch(()=>{});
    }
    document.getElementById('btnMarkSeen')?.addEventListener('click', () => {
      if (latestTs) localStorage.setItem('adminFeedSeenAt', latestTs);
      const dot = document.getElementById('bellDot'); if (dot) dot.style.display = 'none';
    });
    document.getElementById('btnBell')?.addEventListener('click', () => {
      const dot = document.getElementById('bellDot'); if (dot) dot.style.display = 'none';
      if (latestTs) localStorage.setItem('adminFeedSeenAt', latestTs);
    });

    fetchActivity(true);
    setInterval(()=>fetchActivity(false), 35000);
  </script>
</body>
</html>
