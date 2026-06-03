<?php
session_start();
require_once __DIR__ . '/../config.php'; // $con + url()

/* ------------------------------------------------------------------------
   Auth: only patients
------------------------------------------------------------------------- */
if (empty($_SESSION['user_role']) || $_SESSION['user_role'] !== 'patient') {
    header('Location: ' . url('login.php'));
    exit;
}

/* ------------------------------------------------------------------------
   Helpers
------------------------------------------------------------------------- */
function safe($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function tryQuery(callable $fn, $fallback){
    try { return $fn(); } catch (Throwable $e) { error_log($e->getMessage()); return $fallback; }
}
function table_exists(mysqli $con, string $table): bool {
    $sql="SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? LIMIT 1";
    $st=$con->prepare($sql); $st->bind_param("s",$table); $st->execute();
    $ok=$st->get_result()->num_rows>0; $st->close(); return $ok;
}
function column_exists(mysqli $con, string $table, string $column): bool {
    $sql="SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
    $st=$con->prepare($sql); $st->bind_param("ss",$table,$column); $st->execute();
    $ok=$st->get_result()->num_rows>0; $st->close(); return $ok;
}
function age_from_dob(?string $dob): ?int {
    if (!$dob) return null; try { $d=new DateTime($dob); $n=new DateTime(); return (int)$d->diff($n)->y; } catch(Throwable $e){ return null; }
}
function percent_complete(array $row, array $keys): int {
    $total = count($keys); if (!$total) return 0; $done=0;
    foreach ($keys as $k) if (isset($row[$k]) && $row[$k] !== '' && $row[$k] !== null) $done++;
    return (int)round(($done/$total)*100);
}
function decode_json_field($v){ return (is_string($v) && $v!=='') ? (json_decode($v,true) ?: []) : []; }

/* ------------------------------------------------------------------------
   Identify patient by session user_id
------------------------------------------------------------------------- */
$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) { header('Location: ' . url('login.php')); exit; }

$patient = tryQuery(function () use ($con, $uid) {
    if (!table_exists($con, 'patients') || !column_exists($con, 'patients', 'user_id')) return null;
    $st = $con->prepare("SELECT * FROM patients WHERE user_id=? LIMIT 1");
    $st->bind_param("i", $uid); $st->execute();
    $r = $st->get_result()->fetch_assoc(); $st->close();
    return $r ?: null;
}, null);

if (!$patient) { header('Location: ' . url('login.php')); exit; }

$pid = (int)$patient['id'];
$CURRENCY = defined('CURRENCY') ? CURRENCY : '৳';
$SUPPORT_EMAIL = defined('SUPPORT_EMAIL') ? SUPPORT_EMAIL : 'bdpsycare@gmail.com';

/* ------------------------------------------------------------------------
   AJAX: Caregiver mini card + Add review
------------------------------------------------------------------------- */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'cg_card' && isset($_GET['id'])) {
    $cgid = (int) $_GET['id'];
    $html = tryQuery(function () use ($con, $cgid) {
        if (!table_exists($con, 'caregivers')) return '<div class="text-muted">Not available.</div>';
        $st = $con->prepare("SELECT id, first_name,last_name, gender, caregiver_type, experience_years, photo_path, phone_primary
                             FROM caregivers WHERE id=? LIMIT 1");
        $st->bind_param("i", $cgid); $st->execute(); $r = $st->get_result()->fetch_assoc(); $st->close();
        if (!$r) return '<div class="text-danger">Not found.</div>';

        $avg = 0; $cnt = 0;
        if (table_exists($con, 'caregiver_reviews')) {
            $qr = $con->prepare("SELECT ROUND(AVG(rating),2) a, COUNT(*) c FROM caregiver_reviews WHERE caregiver_id=?");
            $qr->bind_param("i", $cgid); $qr->execute(); [$a,$c] = $qr->get_result()->fetch_row() ?: [0,0]; $qr->close();
            $avg = (float)$a; $cnt = (int)$c;
        }
        ob_start(); ?>
        <div class="d-flex gap-3">
            <?php if (!empty($r['photo_path'])): ?>
                <img src="<?php echo '../'.safe($r['photo_path']); ?>" class="rounded" style="width:90px;height:90px;object-fit:cover">
            <?php else: ?>
                <div class="rounded bg-light d-flex align-items-center justify-content-center" style="width:90px;height:90px">
                    <i class="bi bi-person fs-1 text-muted"></i>
                </div>
            <?php endif; ?>
            <div>
                <div class="fw-bold"><?php echo safe($r['first_name'].' '.$r['last_name']); ?></div>
                <div class="text-muted small"><?php echo safe(ucfirst($r['caregiver_type'])); ?> •
                    <?php echo (int)$r['experience_years']; ?> yrs • <?php echo safe(ucfirst($r['gender'])); ?></div>
                <div class="small"><i class="bi bi-telephone"></i> <?php echo safe($r['phone_primary'] ?: '—'); ?></div>
                <div class="mt-1">
                    <span class="badge bg-primary-subtle text-primary border"><?php echo number_format($avg,2); ?> ★</span>
                    <span class="text-muted small">(<?php echo (int)$cnt; ?> reviews)</span>
                </div>
            </div>
        </div>
        <?php return ob_get_clean();
    }, '<div class="text-danger">Error.</div>');
    echo $html; exit;
}

if (isset($_POST['ajax']) && $_POST['ajax'] === 'add_review') {
    header('Content-Type: application/json');
    $cgid = (int) ($_POST['caregiver_id'] ?? 0);
    $rating = max(1, min(5, (int) ($_POST['rating'] ?? 0)));
    $note = trim((string) ($_POST['note'] ?? ''));
    if ($cgid <= 0) { echo json_encode(['ok'=>false,'msg'=>'Invalid caregiver']); exit; }

    if (
        !table_exists($con, 'caregiver_reviews') ||
        !column_exists($con, 'caregiver_reviews', 'caregiver_id') ||
        !column_exists($con, 'caregiver_reviews', 'patient_id') ||
        !column_exists($con, 'caregiver_reviews', 'rating')
    ) { echo json_encode(['ok'=>false,'msg'=>'Reviews table/columns missing']); exit; }

    $st = $con->prepare("INSERT INTO caregiver_reviews (caregiver_id, patient_id, rating, review_text, created_at)
                         VALUES (?,?,?,?, NOW())");
    $st->bind_param("iiis", $cgid, $pid, $rating, $note);
    $ok = $st->execute(); if (!$ok) error_log($st->error); $st->close();
    echo json_encode(['ok'=>$ok,'msg'=>$ok?'Saved':'Failed to save']); exit;
}

/* ------------------------------------------------------------------------
   NEW: AJAX — Patient notifications (real-time polling)
------------------------------------------------------------------------- */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'notifications') {
    header('Content-Type: application/json; charset=utf-8');
    $items=[]; $unread=0; $latest=null;

    if (table_exists($con,'user_notifications')) {
        $st = $con->prepare("SELECT id,type,message,url,is_read,created_at
                             FROM user_notifications
                             WHERE user_id=?
                             ORDER BY created_at DESC
                             LIMIT 20");
        $st->bind_param("i", $uid); $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) {
            if ((int)$r['is_read'] === 0) $unread++;
            if ($latest === null) $latest = $r['created_at'];
            $items[] = [
                'id'=>(int)$r['id'],
                'type'=>$r['type'],
                'message'=>$r['message'],
                'url'=>$r['url'],
                'is_read'=>(int)$r['is_read'],
                'created_at'=>$r['created_at'],
            ];
        }
        $st->close();
    }
    echo json_encode(['ok'=>true,'items'=>$items,'unread'=>$unread,'latest'=>$latest]);
    exit;
}

if (isset($_POST['ajax']) && $_POST['ajax'] === 'notif_mark') {
    header('Content-Type: application/json; charset=utf-8');
    if (!table_exists($con,'user_notifications')) { echo json_encode(['ok'=>false]); exit; }
    $id = isset($_POST['id']) ? (int)$_POST['id'] : -1; // -1 => mark all
    if ($id === -1) {
        $st = $con->prepare("UPDATE user_notifications SET is_read=1 WHERE user_id=? AND is_read=0");
        $st->bind_param("i",$uid); $ok = $st->execute(); $st->close();
    } else {
        $st = $con->prepare("UPDATE user_notifications SET is_read=1 WHERE user_id=? AND id=?");
        $st->bind_param("ii",$uid,$id); $ok = $st->execute(); $st->close();
    }
    echo json_encode(['ok'=>true]);
    exit;
}

/* ------------------------------------------------------------------------
   Derived values
------------------------------------------------------------------------- */
$firstName = trim(($patient['full_name'] ?? '') ?: ($_SESSION['user_name'] ?? 'Patient'));
$firstName = explode(' ', $firstName)[0];
$age = age_from_dob($patient['dob'] ?? null);
$gender = $patient['gender'] ?? '';

$profileKeys = ['full_name','dob','gender','phone','email','service_address','emergency_contact_name','emergency_contact_relation','emergency_contact_phone'];
$careKeys    = ['caregiver_type','shift_type','start_date','hours_per_day','days_per_week'];
$billingKeys = ['payer_name','payer_phone','payment_mode'];
$consentKeys = ['consent_data_privacy','consent_treatment','consent_home_visit','consent_emergency_escalation'];

$percentProfile = percent_complete($patient, $profileKeys);
$percentCare    = percent_complete($patient, $careKeys);
$percentBilling = percent_complete($patient, $billingKeys);
$percentCons    = percent_complete($patient, $consentKeys);
$percentOverall = (int)round(($percentProfile + $percentCare + $percentBilling + $percentCons)/4);

$comorbid      = decode_json_field($patient['comorbidities_json'] ?? null);
$medications   = decode_json_field($patient['medications_json'] ?? null);
$equipment     = decode_json_field($patient['equipment_json'] ?? null);
$tasks         = decode_json_field($patient['tasks_json'] ?? null);
$adls          = decode_json_field($patient['adls_json'] ?? null);
$wounds        = decode_json_field($patient['wound_images_json'] ?? null);
$behaviorRisks = decode_json_field($patient['behavior_risks_json'] ?? null);

/* Case (optional) */
$case = tryQuery(function () use ($con, $pid) {
    if (!table_exists($con, 'cases') || !column_exists($con,'cases','patient_id')) return null;
    $st = $con->prepare("SELECT * FROM cases WHERE patient_id=? ORDER BY id DESC LIMIT 1");
    $st->bind_param("i", $pid); $st->execute();
    $r = $st->get_result()->fetch_assoc(); $st->close();
    return $r ?: null;
}, null);

/* Care team + upcoming (optional) */
$careTeam = tryQuery(function () use ($con, $pid) {
    if (!table_exists($con, 'service_assignments') || !table_exists($con,'caregivers')) return [];
    $sql = "SELECT sa.id, sa.caregiver_id, CONCAT(c.first_name,' ',c.last_name) cg_name, c.photo_path,
                   sa.status, sa.start_time, sa.end_time
            FROM service_assignments sa
            JOIN caregivers c ON c.id = sa.caregiver_id
            WHERE sa.patient_id=? AND sa.status IN ('active','ongoing','scheduled')
            ORDER BY COALESCE(sa.start_time, NOW()) ASC
            LIMIT 12";
    $st = $con->prepare($sql); $st->bind_param("i", $pid); $st->execute();
    $res = $st->get_result(); $out=[]; while ($r=$res->fetch_assoc()) $out[]=$r; $st->close(); return $out;
}, []);

$upcoming = tryQuery(function () use ($con, $pid) {
    if (!table_exists($con, 'service_assignments') || !table_exists($con,'caregivers')) return [];
    $sql = "SELECT sa.id, sa.start_time, sa.end_time, CONCAT(c.first_name,' ',c.last_name) caregiver
            FROM service_assignments sa
            JOIN caregivers c ON c.id = sa.caregiver_id
            WHERE sa.patient_id=? AND sa.start_time >= NOW() - INTERVAL 1 HOUR
            ORDER BY sa.start_time ASC
            LIMIT 20";
    $st = $con->prepare($sql); $st->bind_param("i", $pid); $st->execute();
    $res=$st->get_result(); $out=[]; while ($r=$res->fetch_assoc()) $out[]=$r; $st->close(); return $out;
}, []);

/* Hours last 6 months (optional) */
function month_labels_6(): array { $labels=[]; for($i=5;$i>=0;$i--){$ts=(new DateTime("first day of -$i month"))->format('Y-m-01'); $labels[]=(new DateTime($ts))->format('M Y');} return $labels; }

$hours = tryQuery(function () use ($con, $pid) {
    $labels = month_labels_6(); $map = array_fill_keys($labels, 0.0);
    if (!table_exists($con, 'service_assignments')) return ['labels'=>$labels,'series'=>array_values($map),'total'=>0.0];
    $st = $con->prepare("
        SELECT DATE_FORMAT(start_time,'%b %Y') m,
               SUM(TIMESTAMPDIFF(MINUTE, start_time, end_time))/60.0 h
        FROM service_assignments
        WHERE patient_id=? AND start_time >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH),'%Y-%m-01')
          AND end_time IS NOT NULL
        GROUP BY DATE_FORMAT(start_time,'%Y-%m')
    "); $st->bind_param("i", $pid); $st->execute();
    $res=$st->get_result(); $total=0.0;
    while ($r=$res->fetch_assoc()){ $map[$r['m']]=(float)$r['h']; $total+=(float)$r['h']; }
    $st->close(); $series=[]; foreach($labels as $L) $series[]=(float)($map[$L]??0.0);
    return ['labels'=>$labels,'series'=>$series,'total'=>$total];
}, ['labels'=>month_labels_6(),'series'=>array_fill(0,6,0.0),'total'=>0.0]);

$totalUpcoming   = count($upcoming);
$totalCaregivers = count(array_unique(array_map(fn($r) => $r['caregiver_id'] ?? null, $careTeam)));
$totalHours      = (int)round($hours['total']);

/* Other uploads (optional legacy) */
$otherUploads = tryQuery(function () use ($con, $pid) {
    if (!table_exists($con, 'uploads')) return [];
    $sql = "SELECT id, doc_type, file_url, created_at
            FROM uploads WHERE owner_type='patient' AND owner_id=? ORDER BY id DESC LIMIT 8";
    $st=$con->prepare($sql); $st->bind_param("i",$pid); $st->execute();
    $res=$st->get_result(); $out=[]; while($r=$res->fetch_assoc()) $out[]=$r; $st->close(); return $out;
}, []);

/* Activity timeline (optional) */
$timeline = tryQuery(function () use ($con, $pid, $case) {
    if (!table_exists($con,'activity_log')) return [];
    $hasEntity = column_exists($con,'activity_log','entity_type') && column_exists($con,'activity_log','entity_id');
    if (!$hasEntity) return [];
    $sql = "SELECT created_at, actor_role, summary FROM activity_log
            WHERE (entity_type='patient' AND entity_id=?) ".($case? "OR (entity_type='case' AND entity_id=".(int)$case['id'].")":"")."
            ORDER BY created_at DESC LIMIT 12";
    $st = $con->prepare($sql); $st->bind_param("i",$pid); $st->execute();
    $res=$st->get_result(); $out=[]; while($r=$res->fetch_assoc()) $out[]=$r; $st->close(); return $out;
}, []);

/* Documents via URL columns */
$docCols = [
  'patient_id_doc_url'    => 'Patient ID',
  'discharge_summary_url' => 'Discharge summary',
  'medical_fitness_url'   => 'Medical fitness',
  'patient_photo_url'     => 'Patient photo',
  'prescription_url'      => 'Prescription',
  'guardian_doc_url'      => 'Guardian proof'
];

/* JSON snapshot for export button */
$exportSnapshot = [
  'patient_id' => $pid,
  'profile' => [
    'full_name'=>$patient['full_name'] ?? null,
    'dob'=>$patient['dob'] ?? null,
    'age'=>$age,
    'gender'=>$gender,
    'phone'=>$patient['phone'] ?? null,
    'email'=>$patient['email'] ?? null,
    'present_address'=>$patient['present_address'] ?? null,
    'service_address'=>$patient['service_address'] ?? null,
  ],
  'emergency' => [
    'name'=>$patient['emergency_contact_name'] ?? null,
    'relation'=>$patient['emergency_contact_relation'] ?? null,
    'phone'=>$patient['emergency_contact_phone'] ?? null
  ],
  'care'=>[
    'caregiver_type'=>$patient['caregiver_type'] ?? null,
    'shift_type'=>$patient['shift_type'] ?? null,
    'start_date'=>$patient['start_date'] ?? null,
    'hours_per_day'=>$patient['hours_per_day'] ?? null,
    'days_per_week'=>$patient['days_per_week'] ?? null,
    'language_pref'=>$patient['language_pref'] ?? null,
    'caregiver_gender_pref'=>$patient['caregiver_gender_pref'] ?? null,
    'tasks'=>$tasks
  ],
  'medical'=>[
    'primary_dx'=>$patient['primary_dx'] ?? null,
    'comorbidities'=>$comorbid,
    'allergies'=>$patient['allergies'] ?? null,
    'medications'=>$medications,
    'vitals'=>[
      'height_cm'=>$patient['height_cm'] ?? null,
      'weight_kg'=>$patient['weight_kg'] ?? null,
      'bmi'=>$patient['bmi'] ?? null,
      'bp'=>$patient['bp'] ?? null,
      'pulse'=>$patient['pulse'] ?? null,
      'spo2'=>$patient['spo2'] ?? null,
      'rbs'=>$patient['rbs'] ?? null,
      'pain_score'=>$patient['pain_score'] ?? null
    ]
  ],
  'consent'=>[
    'data_privacy'=> !empty($patient['consent_data_privacy']),
    'treatment'=> !empty($patient['consent_treatment']),
    'home_visit'=> !empty($patient['consent_home_visit']),
    'photo'=> !empty($patient['consent_photo']),
    'emergency_escalation'=> !empty($patient['consent_emergency_escalation']),
    'signed_by'=>$patient['consent_signed_by'] ?? null,
    'signed_name'=>$patient['consent_signed_name'] ?? null,
    'signed_relation'=>$patient['consent_signed_relation'] ?? null,
    'signed_at'=>$patient['consent_signed_at'] ?? null
  ],
  'home_env'=>[
    'landmark'=>$patient['landmark'] ?? null,
    'lift_available'=>$patient['lift_available'] ?? null,
    'stairs_count'=>$patient['stairs_count'] ?? null,
    'equipment'=>$equipment,
    'access_notes'=>$patient['access_notes'] ?? null
  ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>My Dashboard | CGMS Patient</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
:root{
  --pri:#1d4ed8; --acc:#06b6d4; --ink:#0f172a; --mut:#64748b;
  --bg:#f8fafc; --card:#fff; --border:rgba(15,23,42,.08);
  --mut-strong:#475569;
}
body{
  background:
    radial-gradient(900px 600px at 10% -10%, #e0f2fe 0%, transparent 60%),
    radial-gradient(900px 600px at 110% 10%, #e0fffb 0%, transparent 60%),
    var(--bg);
  color:var(--ink);
}
.navbar{ backdrop-filter: blur(10px); background: rgba(255,255,255,.9)!important; border-bottom:1px solid rgba(15,23,42,.06); }
.brand{ font-weight:800; background: linear-gradient(90deg, var(--pri), var(--acc)); -webkit-background-clip:text; color:transparent; }
.card-soft{ background:var(--card); border:1px solid var(--border); border-radius:18px; box-shadow:0 16px 40px rgba(2,6,23,.07); }
.kpi{ font-weight:800; font-size:1.6rem; } .kpi-sub{ color:var(--mut); font-size:.9rem; }
.badge-soft{ background:rgba(29,78,216,.08); color:#1d4ed8; border:1px solid rgba(29,78,216,.18); padding:.2rem .5rem; border-radius:9px; }
.avatar{ width:60px; height:60px; border-radius:50%; object-fit:cover; }
.progress-thin{ height:8px; }

/* bell */
.bell-wrap{ position:relative; }
.bell-dot{ position:absolute; right:-2px; top:-2px; width:10px; height:10px; background:#ef4444; border-radius:50%; display:none; }
.dropdown-menu-notif{ width:360px; max-height:420px; overflow:auto; }

/* latest notif highlight */
.notif-latest{ border-left:3px solid var(--pri); }

/* ---- Dark mode: higher contrast ---- */
.dark{
  --bg:#0b1220; --ink:#e5e7eb; --mut:#9aa4b2; --card:#0f172a; --border:#1f2937; --mut-strong:#cbd5e1;
}
.dark body{ background:var(--bg); color:var(--ink); }
.dark .card-soft{ background:var(--card); border-color:var(--border); }
.dark .navbar{ background:rgba(15,23,42,.9)!important; border-color:var(--border); }
.dark .text-muted{ color:var(--mut) !important; }
.dark .badge-soft{ background:rgba(59,130,246,.18); color:#bfdbfe; border-color:rgba(59,130,246,.35); }
.dark .badge.bg-primary-subtle{ background:rgba(59,130,246,.15)!important; color:#bfdbfe!important; border-color:rgba(59,130,246,.35)!important; }
.dark .progress-thin .progress-bar{ background-color:#60a5fa; }
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg sticky-top">
  <div class="container">
    <a class="navbar-brand brand" href="<?php echo safe(url('index.php')); ?>">CGMS</a>
    <div class="ms-auto d-flex align-items-center gap-2">
      <!-- Notifications bell (click-to-open dropdown) -->
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
          <div id="notifList" class="list-group list-group-flush small"></div>
        </div>
      </div>

      <button class="btn btn-sm btn-outline-secondary" id="btnDark" title="Toggle dark mode"><i class="bi bi-moon"></i></button>
      <a class="btn btn-sm btn-accent" href="<?php echo safe(url('patients/intake.php')); ?>"><i class="bi bi-clipboard2-pulse me-1"></i> Update Intake</a>
      <a class="btn btn-sm btn-outline-secondary" href="<?php echo safe(url('patients/logout.php')); ?>?logout=1">Logout</a>
    </div>
  </div>
</nav>

<div class="container my-3 my-md-4">

  <!-- Profile header -->
  <div class="card card-soft p-3 p-md-4 mb-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
      <div class="d-flex align-items-center gap-3">
        <?php if (!empty($patient['patient_photo_url'])): ?>
          <img class="avatar" src="<?php echo '../'.safe($patient['patient_photo_url']); ?>" alt="">
        <?php else: ?>
          <div class="avatar bg-light d-flex align-items-center justify-content-center"><i class="bi bi-person text-muted fs-4"></i></div>
        <?php endif; ?>
        <div>
          <div class="h5 mb-1"><?php echo safe($patient['full_name'] ?? 'Patient'); ?>
            <?php if ($age !== null): ?><span class="text-muted small">• <?php echo (int)$age; ?>y</span><?php endif; ?>
            <?php if ($gender): ?><span class="text-muted small">• <?php echo safe(ucfirst($gender)); ?></span><?php endif; ?>
          </div>
          <div class="small text-muted">
            <i class="bi bi-telephone me-1"></i><?php echo safe($patient['phone'] ?: '—'); ?>
            &nbsp; • &nbsp;<i class="bi bi-envelope me-1"></i><?php echo safe($patient['email'] ?: '—'); ?>
          </div>
        </div>
      </div>
      <div class="text-end">
        <div class="text-muted small mb-1">Intake completion</div>
        <div class="progress progress-thin" style="min-width:220px">
          <div class="progress-bar" role="progressbar" style="width: <?php echo $percentOverall; ?>%"></div>
        </div>
        <div class="small mt-1"><?php echo $percentOverall; ?>%</div>
      </div>
    </div>

    <!-- Smart alerts -->
    <div class="row g-2 mt-3">
      <?php if ($percentCons < 50): ?>
        <div class="col-md-6"><div class="alert alert-warning py-2 mb-0"><i class="bi bi-shield-exclamation me-1"></i>Consents are incomplete. Please update from Intake.</div></div>
      <?php endif; ?>
      <?php if ($percentBilling < 67): ?>
        <div class="col-md-6"><div class="alert alert-info py-2 mb-0"><i class="bi bi-credit-card me-1"></i>Billing profile missing details. Add payer info in Intake.</div></div>
      <?php endif; ?>
    </div>

    <!-- Quick actions -->
    <div class="d-flex flex-wrap gap-2 mt-3">
      <a class="btn btn-primary btn-sm" href="<?php echo safe(url('patients/intake.php')); ?>"><i class="bi bi-pencil-square me-1"></i>Edit Intake</a>
      <a class="btn btn-outline-primary btn-sm" href="<?php echo safe(url('patients/intake.php')); ?>"><i class="bi bi-upload me-1"></i>Upload Docs</a>
      <?php if (!empty($patient['prescription_url'])): ?>
        <a class="btn btn-outline-primary btn-sm" target="_blank" href="<?php echo '../'.safe($patient['prescription_url']); ?>"><i class="bi bi-file-earmark-medical me-1"></i>Prescription</a>
      <?php endif; ?>
      <button class="btn btn-outline-secondary btn-sm" id="btnExport"><i class="bi bi-download me-1"></i>Download Snapshot</button>
      <a class="btn btn-outline-secondary btn-sm" href="mailto:<?php echo safe($SUPPORT_EMAIL); ?>"><i class="bi bi-life-preserver me-1"></i>Support</a>
    </div>
  </div>

  <div class="row g-3">
    <!-- Care requirements -->
    <div class="col-lg-7">
      <div class="card card-soft p-3">
        <div class="fw-bold">Care Requirements</div>
        <div class="row small mt-2 g-2">
          <div class="col-6"><span class="text-muted">Type:</span> <?php echo safe(ucfirst($patient['caregiver_type'] ?? '—')); ?></div>
          <div class="col-6"><span class="text-muted">Shift:</span> <?php echo safe(ucfirst($patient['shift_type'] ?? '—')); ?></div>
          <div class="col-6"><span class="text-muted">Start:</span> <?php echo safe($patient['start_date'] ?: '—'); ?></div>
          <div class="col-6"><span class="text-muted">Hours/day:</span> <?php echo safe($patient['hours_per_day'] !== null ? (string)$patient['hours_per_day'] : '—'); ?></div>
          <div class="col-6"><span class="text-muted">Days/week:</span> <?php echo safe($patient['days_per_week'] !== null ? (string)$patient['days_per_week'] : '—'); ?></div>
          <div class="col-6"><span class="text-muted">Language:</span> <?php echo safe($patient['language_pref'] ?? '—'); ?></div>
          <div class="col-6"><span class="text-muted">Caregiver gender:</span> <?php echo safe(ucfirst($patient['caregiver_gender_pref'] ?? 'any')); ?></div>
        </div>
        <div class="mt-2">
          <div class="text-muted small mb-1">Tasks</div>
          <?php if (!$tasks): ?>
            <div class="text-muted small">—</div>
          <?php else: foreach ($tasks as $t) echo '<span class="badge-soft me-1">'.safe($t).'</span>'; endif; ?>
        </div>
      </div>
    </div>

    <!-- Home environment -->
    <div class="col-lg-5">
      <div class="card card-soft p-3">
        <div class="fw-bold">Home Environment</div>
        <div class="small mt-2">
          <div><i class="bi bi-geo-alt me-1"></i><?php echo safe($patient['service_address'] ?: '—'); ?></div>
          <?php if (!empty($patient['landmark'])): ?><div class="text-muted"><i class="bi bi-signpost-2 me-1"></i><?php echo safe($patient['landmark']); ?></div><?php endif; ?>
        </div>
        <div class="row small mt-2 g-2">
          <div class="col-6"><span class="text-muted">Lift:</span> <?php echo ($patient['lift_available'] ?? null) ? 'Yes' : 'No'; ?></div>
          <div class="col-6"><span class="text-muted">Stairs:</span> <?php echo safe(($patient['stairs_count'] ?? '') === '' ? '—' : (string)$patient['stairs_count']); ?></div>
        </div>
        <div class="mt-2">
          <div class="text-muted small mb-1">Equipment</div>
          <?php if (!$equipment) echo '<div class="text-muted small">—</div>';
                else foreach ($equipment as $e) echo '<span class="badge-soft me-1">'.safe($e).'</span>'; ?>
        </div>
        <?php if (!empty($patient['access_notes'])): ?>
          <div class="mt-2 small"><span class="text-muted">Notes:</span> <?php echo safe($patient['access_notes']); ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Chart + Vitals & Emergency -->
  <div class="row g-3 mt-1">
    <div class="col-lg-7">
      <div class="card card-soft p-3">
        <div class="d-flex align-items-center justify-content-between">
          <div>
            <div class="fw-bold">Care Hours (last 6 months)</div>
            <div class="text-muted small">Logged from scheduled visits</div>
          </div>
        <span class="badge bg-primary-subtle text-primary border"><?php echo (int)$totalHours; ?> hrs</span>
        </div>
        <canvas id="hrsChart" height="120" class="mt-2"></canvas>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="card card-soft p-3">
        <div class="fw-bold">Vitals Snapshot</div>
        <div class="row small mt-2 g-2">
          <div class="col-6">Height: <span class="text-muted"><?php echo safe($patient['height_cm'] ?: '—'); ?> cm</span></div>
          <div class="col-6">Weight: <span class="text-muted"><?php echo safe($patient['weight_kg'] ?: '—'); ?> kg</span></div>
          <div class="col-6">BMI: <span class="text-muted"><?php echo safe($patient['bmi'] ?: '—'); ?></span></div>
          <div class="col-6">BP: <span class="text-muted"><?php echo safe($patient['bp'] ?: '—'); ?></span></div>
          <div class="col-6">Pulse: <span class="text-muted"><?php echo safe($patient['pulse'] ?: '—'); ?></span></div>
          <div class="col-6">SpO₂: <span class="text-muted"><?php echo safe($patient['spo2'] ?: '—'); ?></span></div>
          <div class="col-6">RBS: <span class="text-muted"><?php echo safe($patient['rbs'] ?: '—'); ?></span></div>
          <div class="col-6">Pain: <span class="text-muted"><?php echo safe($patient['pain_score'] ?: '—'); ?></span></div>
        </div>
        <hr>
        <div class="fw-bold">Emergency Contact</div>
        <div class="small mt-1">
          <div><?php echo safe($patient['emergency_contact_name'] ?: '—'); ?>
            <?php if (!empty($patient['emergency_contact_relation'])) echo ' ('.safe($patient['emergency_contact_relation']).')'; ?></div>
          <div class="text-muted"><i class="bi bi-telephone me-1"></i><?php echo safe($patient['emergency_contact_phone'] ?: '—'); ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Care team + upcoming -->
  <div class="row g-3 mt-1">
    <div class="col-lg-6">
      <div class="card card-soft p-3">
        <div class="d-flex align-items-center justify-content-between">
          <div class="fw-bold mb-0">My Care Team</div>
          <span class="text-muted small"><?php echo count($careTeam); ?> active</span>
        </div>
        <div class="table-responsive mt-2">
          <table class="table align-middle">
            <thead><tr><th>Caregiver</th><th>Status</th><th>Next</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
              <?php if (!$careTeam): ?>
                <tr><td colspan="4" class="text-muted">No caregivers assigned yet.</td></tr>
              <?php else: foreach ($careTeam as $row): ?>
                <tr>
                  <td><a href="#" onclick="viewCG(<?php echo (int)$row['caregiver_id']; ?>);return false;"><?php echo safe($row['cg_name']); ?></a></td>
                  <td><span class="badge bg-<?php $s=strtolower($row['status']); echo $s==='active'?'success':($s==='scheduled'?'warning':'secondary'); ?>"><?php echo safe(ucfirst($row['status'])); ?></span></td>
                  <td><?php echo $row['start_time'] ? safe((new DateTime($row['start_time']))->format('M j, g:ia')) : '—'; ?></td>
                  <td class="text-end"><button class="btn btn-sm btn-outline-primary" onclick="openReview(<?php echo (int)$row['caregiver_id']; ?>,'<?php echo safe($row['cg_name']); ?>')"><i class="bi bi-star"></i></button></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card card-soft p-3">
        <div class="d-flex align-items-center justify-content-between">
          <div class="fw-bold mb-0">Upcoming Schedule</div>
          <span class="text-muted small">Next 2 weeks</span>
        </div>
        <div class="table-responsive mt-2">
          <table class="table align-middle">
            <thead><tr><th>Date</th><th>Time</th><th>Caregiver</th></tr></thead>
            <tbody>
              <?php if (!$upcoming): ?>
                <tr><td colspan="3" class="text-muted">No upcoming visits yet.</td></tr>
              <?php else: foreach ($upcoming as $s):
                $st = $s['start_time'] ? new DateTime($s['start_time']) : null;
                $en = $s['end_time'] ? new DateTime($s['end_time']) : null; ?>
                <tr>
                  <td><?php echo $st ? safe($st->format('D, M j')) : '—'; ?></td>
                  <td><?php echo $st ? safe($st->format('g:ia')) : '—'; ?><?php echo $en ? ' – '.safe($en->format('g:ia')) : ''; ?></td>
                  <td><?php echo safe($s['caregiver']); ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Documents & timeline -->
  <div class="row g-3 mt-1">
    <div class="col-lg-6">
      <div class="card card-soft p-3">
        <div class="d-flex align-items-center justify-content-between">
          <div class="fw-bold mb-0">My Documents</div>
          <a class="btn btn-sm btn-outline-primary" href="<?php echo safe(url('patients/intake.php')); ?>"><i class="bi bi-upload me-1"></i>Upload</a>
        </div>
        <div class="table-responsive mt-2">
          <table class="table align-middle">
            <thead><tr><th>Type</th><th>File</th></tr></thead>
            <tbody>
              <?php
              $hasAny=false;
              foreach ($docCols as $col=>$label):
                if (!empty($patient[$col])){ $hasAny=true; ?>
                  <tr>
                    <td><?php echo safe($label); ?></td>
                    <td><a class="btn btn-sm btn-outline-primary" target="_blank" href="<?php echo '../'.safe($patient[$col]); ?>"><i class="bi bi-box-arrow-up-right me-1"></i>Open</a></td>
                  </tr>
              <?php } endforeach;
              if (!$hasAny): ?>
                <tr><td colspan="2" class="text-muted">No documents uploaded yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <?php if ($wounds): ?>
          <div class="mt-2">
            <div class="small text-muted mb-1">Wound images</div>
            <div class="d-flex flex-wrap gap-2">
              <?php foreach ($wounds as $img): ?>
                <a href="<?php echo '../'.safe($img); ?>" target="_blank"><img src="<?php echo '../'.safe($img); ?>" style="width:84px;height:84px;object-fit:cover;border-radius:10px;border:1px solid rgba(15,23,42,.08)"></a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($otherUploads): ?>
          <hr>
          <div class="fw-bold mb-1">Other uploads</div>
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead><tr><th>Type</th><th>Uploaded</th><th>File</th></tr></thead>
              <tbody>
                <?php foreach ($otherUploads as $d): ?>
                  <tr>
                    <td><?php echo safe(ucwords(str_replace('_',' ',$d['doc_type'] ?? 'file'))); ?></td>
                    <td><?php echo !empty($d['created_at']) ? safe((new DateTime($d['created_at']))->format('M j, Y')) : '—'; ?></td>
                    <td><?php if (!empty($d['file_url'])): ?><a class="btn btn-sm btn-outline-primary" target="_blank" href="<?php echo '../'.safe($d['file_url']); ?>"><i class="bi bi-box-arrow-up-right me-1"></i>Open</a><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card card-soft p-3">
        <div class="d-flex align-items-center justify-content-between">
          <div class="fw-bold mb-0">Recent Activity</div>
          <span class="text-muted small"><?php echo $timeline ? count($timeline) : 0; ?></span>
        </div>
        <div class="mt-2">
          <?php if (!$timeline): ?>
            <div class="text-muted small">No recent activity yet.</div>
          <?php else: ?>
            <ul class="list-unstyled small mb-0">
              <?php foreach ($timeline as $t): ?>
                <li class="mb-2">
                  <div class="text-muted"><?php echo !empty($t['created_at'])?safe((new DateTime($t['created_at']))->format('M j, g:ia')):'—'; ?></div>
                  <div><?php echo safe($t['summary'] ?? ''); ?></div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Notifications card -->
  <div class="row g-3 mt-1">
    <div class="col-12">
      <div class="card card-soft p-3">
        <div class="d-flex align-items-center justify-content-between">
          <div class="fw-bold mb-0">My Notifications</div>
          <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-secondary" id="btnNotifRefresh"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button>
            <button class="btn btn-sm btn-outline-primary" id="btnNotifMarkAll"><i class="bi bi-check2-all me-1"></i>Mark all as read</button>
          </div>
        </div>
        <div id="notifCard" class="mt-2">
          <div class="p-3 text-muted">No notifications yet.</div>
        </div>
      </div>
    </div>
  </div>

  <footer class="text-muted small mt-4">© <?php echo date('Y'); ?> CGMS • Patient Portal</footer>
</div>

<!-- Caregiver mini modal -->
<div class="modal fade" id="cgViewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Caregiver</h5><button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body" id="cgViewBody">Loading…</div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<!-- Review modal -->
<div class="modal fade" id="reviewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="reviewForm">
      <div class="modal-header"><h5 class="modal-title" id="rvTitle">Rate Caregiver</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" name="caregiver_id" id="rvCgId">
        <div class="mb-3">
          <label class="form-label">Rating</label>
          <select name="rating" class="form-select">
            <option value="5">5 - Excellent</option><option value="4">4 - Good</option>
            <option value="3">3 - Fair</option><option value="2">2 - Poor</option><option value="1">1 - Very poor</option>
          </select>
        </div>
        <div><label class="form-label">Comments (optional)</label><textarea name="note" class="form-control" rows="3" placeholder="Share your experience…"></textarea></div>
      </div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Submit Review</button></div>
    </form>
  </div>
</div>

<!-- Bootstrap JS required for dropdown -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>

<script>
/* ---- Dark mode (persist & better contrast) ---- */
(function(){
  const root = document.documentElement;
  const btn  = document.getElementById('btnDark');
  const icon = btn.querySelector('i');

  function applyStored(){
    if (localStorage.getItem('theme') === 'dark') root.classList.add('dark');
    setIcon();
  }
  function setIcon(){
    if (root.classList.contains('dark')) { icon.classList.remove('bi-moon'); icon.classList.add('bi-sun'); }
    else { icon.classList.remove('bi-sun'); icon.classList.add('bi-moon'); }
  }
  btn.addEventListener('click', () => {
    root.classList.toggle('dark');
    localStorage.setItem('theme', root.classList.contains('dark') ? 'dark' : 'light');
    setIcon();
    renderChart(); // re-render for dark grid/labels
  });
  applyStored();
})();

/* ---- Chart (adapts to dark mode) ---- */
let hrsChart = null;
function renderChart(){
  const el = document.getElementById('hrsChart');
  if (!el) return;

  const dark = document.documentElement.classList.contains('dark');
  const tick = dark ? '#cbd5e1' : '#475569';
  const grid = dark ? 'rgba(148,163,184,.2)' : 'rgba(15,23,42,.08)';
  const line = 'rgba(29,78,216,1)';

  if (hrsChart) { hrsChart.destroy(); }
  hrsChart = new Chart(el, {
    type: 'line',
    data: {
      labels: <?php echo json_encode($hours['labels']); ?>,
      datasets: [{
        label: 'Hours',
        data: <?php echo json_encode($hours['series']); ?>,
        fill: true, tension: .35, borderWidth: 2,
        borderColor: line,
        backgroundColor: (ctx) => {
          const g = ctx.chart.ctx.createLinearGradient(0,0,0,200);
          g.addColorStop(0,'rgba(29,78,216,.35)'); g.addColorStop(1,'rgba(29,78,216,0)');
          return g;
        },
        pointRadius: 3
      }]
    },
    options: {
      plugins:{ legend:{ display:false } },
      scales:{
        x:{ ticks:{ color: tick }, grid:{ color: grid } },
        y:{ beginAtZero:true, ticks:{ color: tick }, grid:{ color: grid } }
      }
    }
  });
}
renderChart();

/* view caregiver mini */
function viewCG(id){
  const body = document.getElementById('cgViewBody');
  body.innerHTML = 'Loading…';
  const modal = new bootstrap.Modal(document.getElementById('cgViewModal')); modal.show();
  fetch('dashboard.php?ajax=cg_card&id='+encodeURIComponent(id))
    .then(r=>r.text()).then(html=>body.innerHTML=html)
    .catch(()=>body.innerHTML='<div class="text-danger">Failed to load.</div>');
}
/* open review modal */
function openReview(id,name){
  document.getElementById('rvCgId').value = id;
  const m = new bootstrap.Modal(document.getElementById('reviewModal'));
  document.getElementById('rvTitle').textContent = 'Rate '+name; m.show();
}
/* submit review (AJAX) */
document.getElementById('reviewForm')?.addEventListener('submit', function (e){
  e.preventDefault();
  const fd = new FormData(this); fd.append('ajax','add_review');
  fetch('dashboard.php',{ method:'POST', body:fd })
    .then(r=>r.json()).then(j=>{
      if (j.ok){ alert('Thanks! Your review was submitted.'); bootstrap.Modal.getInstance(document.getElementById('reviewModal'))?.hide(); this.reset(); }
      else { alert(j.msg || 'Failed to save review'); }
    }).catch(()=>alert('Network error'));
});

/* download dashboard snapshot (PNG) */
document.getElementById('btnExport').addEventListener('click', async () => {
  const btn = document.getElementById('btnExport');
  const prev = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Preparing…';
  window.scrollTo(0, 0);
  const element = document.body;

  try {
    const canvas = await html2canvas(element, {
      useCORS: true,
      backgroundColor: null,
      scale: window.devicePixelRatio > 1 ? window.devicePixelRatio : 2,
      windowWidth: document.documentElement.scrollWidth,
      windowHeight: document.documentElement.scrollHeight
    });

    canvas.toBlob((blob) => {
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'cgms_dashboard_<?php echo (int)$pid; ?>.png';
      a.click();
      URL.revokeObjectURL(a.href);
    }, 'image/png');
  } catch (e) {
    console.error(e);
    alert('Could not create snapshot. Please try again.');
  } finally {
    btn.disabled = false;
    btn.innerHTML = prev;
  }
});

/* ================= Real-time notifications (polling) ================= */
function timeAgo(ts){
  const d = new Date((ts||'').replace(' ','T'));
  const s = Math.floor((Date.now() - d.getTime())/1000);
  if (isNaN(s)) return ts || '';
  if (s < 60) return s + 's ago';
  const m = Math.floor(s/60); if (m < 60) return m + 'm ago';
  const h = Math.floor(m/60); if (h < 24) return h + 'h ago';
  const d2 = Math.floor(h/24); if (d2 < 7) return d2 + 'd ago';
  return d.toLocaleString();
}
function esc(s){ return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function renderNotifsDropdown(list){
  const el = document.getElementById('notifList');
  if (!list || !list.length) {
    el.innerHTML = '<div class="p-3 text-muted">No notifications yet.</div>';
    return;
  }
  el.innerHTML = list.map((n,idx) => {
    const read = Number(n.is_read) === 1;
    const latest = idx === 0;
    const strongStart = latest ? '<strong>' : '';
    const strongEnd   = latest ? '</strong>' : '';
    const btn = n.url
      ? `<a class="btn btn-sm btn-primary ms-2" href="${esc(n.url)}">Open</a>`
      : `<button class="btn btn-sm btn-secondary ms-2" disabled>Open</button>`;
    return `<div class="list-group-item position-relative ${read?'':'bg-light'} ${latest?'notif-latest':''}">
      <div class="d-flex justify-content-between align-items-center">
        <div class="me-3">${strongStart}${esc(n.message||'')}${strongEnd}</div>
        <div class="d-flex align-items-center">
          <small class="text-muted">${timeAgo(n.created_at)}</small>
          ${btn}
        </div>
      </div>
    </div>`;
  }).join('');
}

function renderNotifsCard(list){
  const el = document.getElementById('notifCard');
  if (!list || !list.length) {
    el.innerHTML = '<div class="p-3 text-muted">No notifications yet.</div>';
    return;
  }
  el.innerHTML = `<div class="list-group list-group-flush">
    ${list.map((n,idx)=>{
      const read = Number(n.is_read) === 1;
      const latest = idx === 0;
      const strongStart = latest ? '<strong>' : '';
      const strongEnd   = latest ? '</strong>' : '';
      const btn = n.url
        ? `<a class="btn btn-sm btn-primary ms-2" href="${esc(n.url)}">Open</a>`
        : `<button class="btn btn-sm btn-secondary ms-2" disabled>Open</button>`;
      return `<div class="list-group-item d-flex justify-content-between align-items-center ${read?'':'bg-light'} ${latest?'notif-latest':''}">
        <div class="me-3">${strongStart}${esc(n.message||'')}${strongEnd}</div>
        <div class="d-flex align-items-center">
          <small class="text-muted">${timeAgo(n.created_at)}</small>
          ${btn}
        </div>
      </div>`;
    }).join('')}
  </div>`;
}

function fetchNotifs(showDot=true){
  return fetch('dashboard.php?ajax=notifications')
    .then(r=>r.json()).then(j=>{
      if (!j || !j.ok) return;
      renderNotifsDropdown(j.items || []);
      renderNotifsCard(j.items || []);
      const dot = document.getElementById('bellDot');
      if (dot) dot.style.display = (j.unread && showDot) ? 'block' : 'none';
    }).catch(()=>{});
}

// Bell click → open dropdown only (do NOT auto mark read)
document.getElementById('btnBell')?.addEventListener('click', () => {
  const dot = document.getElementById('bellDot'); if (dot) dot.style.display = 'none';
});
// “Mark all read” in dropdown
document.getElementById('btnMarkSeen')?.addEventListener('click', () => {
  fetch('dashboard.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'ajax=notif_mark&id=-1'
  }).then(()=>fetchNotifs(false));
});
// Notifications card buttons
document.getElementById('btnNotifMarkAll')?.addEventListener('click', () => {
  fetch('dashboard.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'ajax=notif_mark&id=-1'
  }).then(()=>fetchNotifs(false));
});
document.getElementById('btnNotifRefresh')?.addEventListener('click', () => fetchNotifs(true));

// Initial load + poll every 12s
fetchNotifs(true);
setInterval(()=>fetchNotifs(true), 1000);
</script>
</body>
</html>
