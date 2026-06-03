<?php
session_start();
require_once __DIR__ . '/../config.php'; // must define $con (mysqli) + url()

/* ------------------------------------------------------------------------
   Auth: only patients
------------------------------------------------------------------------- */
if (empty($_SESSION['user_role']) || $_SESSION['user_role'] !== 'patient') {
  header('Location: ' . url('login.php'));
  exit;
}
$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
  header('Location: ' . url('login.php'));
  exit;
}

/* ------------------------------------------------------------------------
   Helpers
------------------------------------------------------------------------- */
function safe($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function arr_has($arr,$val){ return is_array($arr) && in_array($val,$arr,true); }

/* ------------------------------------------------------------------------
   Load patient row (single-table model)
------------------------------------------------------------------------- */
$st = $con->prepare("SELECT * FROM patients WHERE user_id=? LIMIT 1");
$st->bind_param("i", $uid);
$st->execute();
$patient = $st->get_result()->fetch_assoc();
$st->close();

if (!$patient) {
  header('Location: ' . url('patients/register.php'));
  exit;
}

/* Decode JSON helpers for prefilling */
$equipment  = json_decode($patient['equipment_json'] ?? '[]', true) ?: [];
$comorbid   = json_decode($patient['comorbidities_json'] ?? '[]', true) ?: [];
$meds       = json_decode($patient['medications_json'] ?? '[]', true) ?: [];
$adls       = json_decode($patient['adls_json'] ?? '[]', true) ?: [];
$tasks      = json_decode($patient['tasks_json'] ?? '[]', true) ?: [];
$behaviors  = json_decode($patient['behavior_risks_json'] ?? '[]', true) ?: [];
$woundImgs  = json_decode($patient['wound_images_json'] ?? '[]', true) ?: [];

/* UI sugar */
$firstName = explode(' ', trim(($patient['full_name'] ?? $_SESSION['user_name'] ?? 'Patient')))[0];
$savedFlag = isset($_GET['saved']) && $_GET['saved'] === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Patient Intake | CGMS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root{ --pri:#1d4ed8; --acc:#06b6d4; --ink:#0f172a; --mut:#64748b; }
body{
  background:
    radial-gradient(900px 600px at 10% -10%, #e0f2fe 0%, transparent 60%),
    radial-gradient(900px 600px at 110% 10%, #e0fffb 0%, transparent 60%),
    #f8fafc;
  color:var(--ink);
}
.navbar{ backdrop-filter: blur(10px); background:rgba(255,255,255,.92)!important; border-bottom:1px solid rgba(15,23,42,.06); }
.brand{ font-weight:800; background:linear-gradient(90deg,var(--pri),var(--acc)); -webkit-background-clip:text; color:transparent; }
.card-soft{ background:#fff; border:1px solid rgba(15,23,42,.08); border-radius:18px; box-shadow:0 16px 40px rgba(2,6,23,.07); }
.fade-up{ opacity:0; transform:translateY(12px); animation:fadeUp .6s ease forwards; }
@keyframes fadeUp{ to{ opacity:1; transform:none; } }
.section-title{ font-weight:700; margin-top:.2rem; }
.small-muted{ color:var(--mut); font-size:.875rem; }
.stepper{ position:sticky; top:70px; z-index:10; display:flex; gap:.5rem; align-items:center; }
.step-dot{ width:10px; height:10px; border-radius:50%; background:#cbd5e1; transition:all .2s; }
.step-dot.active{ background:#1d4ed8; transform:scale(1.2); box-shadow:0 0 0 4px rgba(29,78,216,.15); }
hr.soft{ border:0; height:1px; background:linear-gradient(90deg, rgba(2,6,23,.12), rgba(2,6,23,.04)); }
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg sticky-top">
  <div class="container">
    <a class="navbar-brand brand" href="<?php echo safe(url('index.php')); ?>">CGMS</a>
    <div class="ms-auto d-flex align-items-center gap-2">
      <a class="btn btn-sm btn-outline-secondary" href="<?php echo safe(url('patients/dashboard.php')); ?>"><i class="bi bi-speedometer2 me-1"></i> Dashboard</a>
      <a class="btn btn-sm btn-outline-secondary" href="<?php echo safe(url('login.php')); ?>?logout=1">Logout</a>
    </div>
  </div>
</nav>

<div class="container my-3 my-md-4">
  <div class="card card-soft p-3 p-md-4 fade-up">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <div class="h5 mb-0">Hello, <?php echo safe($firstName); ?> — Patient Intake</div>
        <div class="text-muted small">Update details to help us assign the right caregiver.</div>
      </div>
      <div class="stepper">
        <div class="step-dot active"></div><!-- 1 -->
        <div class="step-dot"></div><!-- 2 -->
        <div class="step-dot"></div><!-- 3 -->
        <div class="step-dot"></div><!-- 4 -->
        <div class="step-dot"></div><!-- 5 -->
        <div class="step-dot"></div><!-- 6 -->
        <div class="step-dot"></div><!-- 7 -->
      </div>
    </div>
    <?php if ($savedFlag): ?>
      <div class="alert alert-success mt-3 mb-0"><i class="bi bi-check2-circle me-1"></i> Your intake was saved.</div>
    <?php endif; ?>
  </div>

  <form class="card card-soft p-3 p-md-4 mt-3 fade-up" action="<?php echo safe(url('patients/save_intake.php')); ?>" method="post" enctype="multipart/form-data">

    <!-- A) Registrant / Relative -->
    <h5 class="section-title mb-2">A) Registrant / Relative</h5>
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label">Full name</label>
        <input name="reg_name" class="form-control" value="<?php echo safe($patient['registrant_name'] ?? ''); ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Relationship to patient</label>
        <input name="reg_relation" class="form-control" placeholder="self/spouse/parent/child/guardian…" value="<?php echo safe($patient['registrant_relation'] ?? ''); ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Preferred contact method</label>
        <?php $pref = strtolower($patient['registrant_preferred_contact'] ?? ''); ?>
        <select name="reg_preferred_contact" class="form-select">
          <option value="">Select</option>
          <option value="call"     <?php echo $pref==='call'?'selected':''; ?>>Call</option>
          <option value="sms"      <?php echo $pref==='sms'?'selected':''; ?>>SMS</option>
          <option value="whatsapp" <?php echo $pref==='whatsapp'?'selected':''; ?>>WhatsApp</option>
          <option value="email"    <?php echo $pref==='email'?'selected':''; ?>>Email</option>
        </select>
      </div>

      <div class="col-md-4">
        <label class="form-label">Mobile (primary)</label>
        <input name="reg_phone1" class="form-control" value="<?php echo safe($patient['registrant_phone1'] ?? ''); ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Alternate phone</label>
        <input name="reg_phone2" class="form-control" value="<?php echo safe($patient['registrant_phone2'] ?? ''); ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Email</label>
        <input type="email" name="reg_email" class="form-control" value="<?php echo safe($patient['registrant_email'] ?? ''); ?>">
      </div>

      <div class="col-md-4">
        <label class="form-label">National ID / Passport (optional)</label>
        <input name="reg_nid_passport" class="form-control" value="<?php echo safe($patient['registrant_nid_passport'] ?? ''); ?>">
      </div>
      <div class="col-md-8">
        <label class="form-label">Address</label>
        <input name="reg_address" class="form-control" value="<?php echo safe($patient['registrant_address'] ?? ''); ?>">
      </div>

      <div class="col-md-4">
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" id="reg_is_guardian" name="reg_is_guardian" value="1" <?php echo !empty($patient['is_legal_guardian'])?'checked':''; ?>>
          <label class="form-check-label" for="reg_is_guardian">I am the legal guardian / decision-maker</label>
        </div>
      </div>
      <div class="col-md-8" id="guardianRow" style="<?php echo !empty($patient['is_legal_guardian'])?'':'display:none;'; ?>">
        <label class="form-label">Upload proof of guardianship/POA (optional)</label>
        <input type="file" class="form-control" name="guardian_doc" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
        <?php if (!empty($patient['guardian_doc_url'])): ?>
          <div class="small mt-1">Existing: <a target="_blank" href="<?php echo '../'.safe($patient['guardian_doc_url']); ?>">Open</a></div>
        <?php endif; ?>
      </div>

      <div class="col-md-6">
        <label class="form-label">If NOT guardian: primary decision-maker name</label>
        <input name="decision_maker_name" class="form-control" value="<?php echo safe($patient['decision_maker_name'] ?? ''); ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Decision-maker phone</label>
        <input name="decision_maker_phone" class="form-control" value="<?php echo safe($patient['decision_maker_phone'] ?? ''); ?>">
      </div>

      <div class="col-12">
        <div class="form-check mt-1">
          <input class="form-check-input" type="checkbox" id="reg_consent_confirm" name="reg_consent_confirm" value="1" checked>
          <label class="form-check-label" for="reg_consent_confirm">I confirm info is accurate & I’m authorized to share.</label>
        </div>
      </div>
    </div>

    <hr class="my-4">

    <!-- B) Patient Profile -->
    <h5 class="section-title mb-2">B) Patient Profile</h5>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Full name (as per ID)</label>
        <input name="full_name" class="form-control" value="<?php echo safe($patient['full_name'] ?? ''); ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Date of birth</label>
        <input type="date" name="dob" class="form-control" value="<?php echo safe($patient['dob'] ?? ''); ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Gender</label>
        <?php $g=strtolower($patient['gender'] ?? ''); ?>
        <select name="gender" class="form-select">
          <option value="">Select</option>
          <option value="male"   <?php echo $g==='male'?'selected':''; ?>>Male</option>
          <option value="female" <?php echo $g==='female'?'selected':''; ?>>Female</option>
          <option value="other"  <?php echo $g==='other'?'selected':''; ?>>Other</option>
        </select>
      </div>

      <div class="col-md-4">
        <label class="form-label">National ID / Passport (optional)</label>
        <input name="nid_passport" class="form-control" value="<?php echo safe($patient['nid_passport'] ?? ''); ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Marital status (optional)</label>
        <input name="marital_status" class="form-control" value="<?php echo safe($patient['marital_status'] ?? ''); ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Mobile</label>
        <input name="phone" class="form-control" value="<?php echo safe($patient['phone'] ?? ''); ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" value="<?php echo safe($patient['email'] ?? ''); ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Present address</label>
        <input name="present_address" class="form-control" value="<?php echo safe($patient['present_address'] ?? ''); ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Permanent address (optional)</label>
        <input name="permanent_address" class="form-control" value="<?php echo safe($patient['permanent_address'] ?? ''); ?>">
      </div>

      <div class="col-md-4">
        <label class="form-label">Emergency contact name</label>
        <input name="emergency_name" class="form-control" value="<?php echo safe($patient['emergency_contact_name'] ?? ''); ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Emergency relation</label>
        <input name="emergency_relation" class="form-control" value="<?php echo safe($patient['emergency_contact_relation'] ?? ''); ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Emergency phone</label>
        <input name="emergency_phone" class="form-control" value="<?php echo safe($patient['emergency_contact_phone'] ?? ''); ?>">
      </div>
    </div>

    <hr class="my-4">

    <!-- Care Location & Home Setup -->
    <h5 class="section-title mb-2">Care Location & Home Setup</h5>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Service address (care location)</label>
        <input name="service_address" class="form-control" value="<?php echo safe($patient['service_address'] ?? ''); ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Landmark</label>
        <input name="landmark" class="form-control" value="<?php echo safe($patient['landmark'] ?? ''); ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Lift available</label>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" id="lift_available" name="lift_available" value="1" <?php echo !empty($patient['lift_available'])?'checked':''; ?>>
          <label class="form-check-label" for="lift_available">Yes</label>
        </div>
      </div>
      <div class="col-md-3">
        <label class="form-label">Stairs (no. of steps)</label>
        <input type="number" name="stairs_count" class="form-control" value="<?php echo safe($patient['stairs_count'] ?? ''); ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Home access notes (gate/lift/stairs)</label>
        <input name="access_notes" class="form-control" value="<?php echo safe($patient['access_notes'] ?? ''); ?>">
      </div>

      <div class="col-12">
        <label class="form-label">Home equipment available</label>
        <div class="row g-2">
          <?php
            $E = ['wheelchair','walker','commode_chair','hospital_bed','oxygen','suction','nebulizer','glucometer','bp_machine','others'];
            foreach ($E as $e):
          ?>
          <div class="col-md-3">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="equipment[]" value="<?php echo safe($e); ?>" <?php echo arr_has($equipment,$e)?'checked':''; ?>>
              <label class="form-check-label"><?php echo safe(ucwords(str_replace('_',' ',$e))); ?></label>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <hr class="my-4">

    <!-- Medical Summary -->
    <h5 class="section-title mb-2">Medical Summary</h5>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Primary diagnosis / condition(s)</label>
        <input name="primary_dx" class="form-control" value="<?php echo safe($patient['primary_dx'] ?? ''); ?>">
      </div>

      <div class="col-md-6">
        <label class="form-label">Comorbidities</label>
        <div class="row g-2">
          <?php
            $C = ['diabetes','hypertension','copd_asthma','ckd','dementia','stroke','epilepsy','psychiatric','others'];
            foreach ($C as $c):
          ?>
          <div class="col-md-6">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="comorbidities[]" value="<?php echo safe($c); ?>" <?php echo arr_has($comorbid,$c)?'checked':''; ?>>
              <label class="form-check-label"><?php echo safe(ucwords(str_replace('_',' ',$c))); ?></label>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="col-md-6">
        <label class="form-label">Allergies (drug/food/others)</label>
        <input name="allergies" class="form-control" value="<?php echo safe($patient['allergies'] ?? ''); ?>">
      </div>

      <div class="col-md-6">
        <label class="form-label">Current medications</label>
        <div id="medList">
          <?php
            if (!$meds) $meds = [['name'=>'','dose'=>'','frequency'=>'']];
            foreach ($meds as $m):
          ?>
          <div class="row g-2 align-items-end mb-2 med-row">
            <div class="col-md-4">
              <label class="form-label small">Name</label>
              <input class="form-control" name="medication_name[]" value="<?php echo safe($m['name'] ?? ''); ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label small">Dose</label>
              <input class="form-control" name="medication_dose[]" value="<?php echo safe($m['dose'] ?? ''); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label small">Frequency</label>
              <input class="form-control" name="medication_freq[]" value="<?php echo safe($m['frequency'] ?? ''); ?>">
            </div>
            <div class="col-md-1 d-grid">
              <button type="button" class="btn btn-outline-danger btn-sm btnDelMed" title="Remove"><i class="bi bi-x"></i></button>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddMed"><i class="bi bi-plus"></i> Add another</button>
        <div class="mt-2">
          <label class="form-label">Upload prescription (optional)</label>
          <input type="file" class="form-control" name="prescription" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
          <?php if (!empty($patient['prescription_url'])): ?>
            <div class="small mt-1">Existing: <a target="_blank" href="<?php echo '../'.safe($patient['prescription_url']); ?>">Open</a></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="col-md-6">
        <label class="form-label">Recent hospitalization/surgery</label>
        <?php $rh = (int)($patient['recent_hospitalization'] ?? 0); ?>
        <select name="recent_hosp_yn" class="form-select">
          <option value="">Select</option>
          <option value="no"  <?php echo $rh ? '' : 'selected'; ?>>No</option>
          <option value="yes" <?php echo $rh ? 'selected' : ''; ?>>Yes</option>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">If yes: when / where / why</label>
        <input name="recent_hosp_info" class="form-control" value="<?php echo safe($patient['recent_hosp_reason'] ?? ''); ?>">
      </div>

      <div class="col-md-6">
        <label class="form-label">Doctor / Clinic name (optional)</label>
        <input name="doctor_name" class="form-control" value="<?php echo safe($patient['doctor_name'] ?? ''); ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Doctor / Clinic contact (optional)</label>
        <input name="doctor_contact" class="form-control" value="<?php echo safe($patient['doctor_contact'] ?? ''); ?>">
      </div>
    </div>

    <hr class="my-4">

    <!-- Functional & Cognitive Status -->
    <h5 class="section-title mb-2">Functional & Cognitive Status</h5>
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label">Mobility</label>
        <?php $mob = $patient['mobility'] ?? ''; ?>
        <select name="mobility" class="form-select">
          <option value="">Select</option>
          <option value="independent"     <?php echo $mob==='independent'?'selected':''; ?>>Independent</option>
          <option value="needs_assistance"<?php echo $mob==='needs_assistance'?'selected':''; ?>>Needs assistance</option>
          <option value="bed_bound"       <?php echo $mob==='bed_bound'?'selected':''; ?>>Bed-bound</option>
        </select>
      </div>

      <div class="col-md-8">
        <label class="form-label">ADLs (select where help is needed)</label>
        <div class="row g-2">
          <?php $A=['feeding','bathing','dressing','toileting','grooming']; foreach($A as $a): ?>
          <div class="col-md-4">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="adls[]" value="<?php echo safe($a); ?>" <?php echo arr_has($adls,$a)?'checked':''; ?>>
              <label class="form-check-label"><?php echo safe(ucfirst($a)); ?></label>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="col-md-4">
        <label class="form-label">Bladder/Bowel (continence)</label>
        <?php $cont = $patient['continence'] ?? ''; ?>
        <select name="continence" class="form-select">
          <option value="">Select</option>
          <option value="continent"  <?php echo $cont==='continent'?'selected':''; ?>>Continent</option>
          <option value="incontinent"<?php echo $cont==='incontinent'?'selected':''; ?>>Incontinent</option>
          <option value="catheter"   <?php echo $cont==='catheter'?'selected':''; ?>>Catheter</option>
          <option value="stoma"      <?php echo $cont==='stoma'?'selected':''; ?>>Stoma</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Swallowing</label>
        <?php $sw = $patient['swallowing'] ?? ''; ?>
        <select name="swallowing" class="form-select">
          <option value="">Select</option>
          <option value="none"       <?php echo $sw==='none'?'selected':''; ?>>No risk / normal</option>
          <option value="soft_diet"  <?php echo $sw==='soft_diet'?'selected':''; ?>>Soft diet</option>
          <option value="ryles_peg"  <?php echo $sw==='ryles_peg'?'selected':''; ?>>Ryle’s/PEG</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Communication ability</label>
        <?php $comms = $patient['communication'] ?? ''; ?>
        <select name="communication" class="form-select">
          <option value="">Select</option>
          <option value="verbal"     <?php echo $comms==='verbal'?'selected':''; ?>>Verbal</option>
          <option value="limited"    <?php echo $comms==='limited'?'selected':''; ?>>Limited</option>
          <option value="non_verbal" <?php echo $comms==='non_verbal'?'selected':''; ?>>Non-verbal</option>
        </select>
      </div>

      <div class="col-md-6">
        <label class="form-label">Communication language (optional)</label>
        <input name="communication_language" class="form-control" value="<?php echo safe($patient['communication_language'] ?? ''); ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Cognition / behavior</label>
        <input name="cognition" class="form-control" placeholder="oriented/confused/agitation/wandering/risk of falls…" value="<?php echo safe($patient['cognition'] ?? ''); ?>">
      </div>

      <div class="col-md-4">
        <label class="form-label">Behavior risks (comma-separated)</label>
        <input name="behavior_risks" class="form-control" value="<?php echo safe(implode(', ', $behaviors)); ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Fall risk</label>
        <?php $fr = $patient['fall_risk'] ?? ''; ?>
        <select name="fall_risk" class="form-select">
          <option value="">Select</option>
          <option value="low"    <?php echo $fr==='low'?'selected':''; ?>>Low</option>
          <option value="medium" <?php echo $fr==='medium'?'selected':''; ?>>Medium</option>
          <option value="high"   <?php echo $fr==='high'?'selected':''; ?>>High</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Bed sore risk</label>
        <?php $bs = $patient['bedsore_risk'] ?? ''; ?>
        <select name="bedsore_risk" class="form-select">
          <option value="">Select</option>
          <option value="low"    <?php echo $bs==='low'?'selected':''; ?>>Low</option>
          <option value="medium" <?php echo $bs==='medium'?'selected':''; ?>>Medium</option>
          <option value="high"   <?php echo $bs==='high'?'selected':''; ?>>High</option>
        </select>
      </div>
      <div class="col-md-12">
        <label class="form-label">Bed sore details (optional)</label>
        <input name="bedsore_details" class="form-control" value="<?php echo safe($patient['bedsore_details'] ?? ''); ?>">
      </div>
    </div>

    <hr class="my-4">

    <!-- Vitals & Monitoring (baseline) -->
    <h5 class="section-title mb-2">Vitals & Monitoring (baseline)</h5>
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label">Height (cm)</label>
        <input id="height_cm" name="height_cm" class="form-control" value="<?php echo safe($patient['height_cm'] ?? ''); ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Weight (kg)</label>
        <input id="weight_kg" name="weight_kg" class="form-control" value="<?php echo safe($patient['weight_kg'] ?? ''); ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">BMI</label>
        <input id="bmi_view" class="form-control" value="<?php echo safe($patient['bmi'] ?? ''); ?>" readonly>
        <input type="hidden" id="bmi" name="bmi" value="<?php echo safe($patient['bmi'] ?? ''); ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Pain level (0–10)</label>
        <input type="number" min="0" max="10" name="pain_score" class="form-control" value="<?php echo safe($patient['pain_score'] ?? ''); ?>">
      </div>

      <div class="col-md-3">
        <label class="form-label">BP</label>
        <input name="bp" class="form-control" placeholder="e.g., 120/80" value="<?php echo safe($patient['bp'] ?? ''); ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Pulse</label>
        <input name="pulse" class="form-control" value="<?php echo safe($patient['pulse'] ?? ''); ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">SpO₂</label>
        <input name="spo2" class="form-control" value="<?php echo safe($patient['spo2'] ?? ''); ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">RBS</label>
        <input name="rbs" class="form-control" value="<?php echo safe($patient['rbs'] ?? ''); ?>">
      </div>
    </div>

    <hr class="my-4">

    <!-- Care Requirements & Preferences -->
    <h5 class="section-title mb-2">Care Requirements & Preferences</h5>
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label">Required caregiver type</label>
        <?php $ct = $patient['caregiver_type'] ?? ''; ?>
        <select name="caregiver_type" class="form-select">
          <option value="">Select</option>
          <option value="nurse"          <?php echo $ct==='nurse'?'selected':''; ?>>Nurse</option>
          <option value="attendant"      <?php echo $ct==='attendant'?'selected':''; ?>>Attendant</option>
          <option value="physiotherapist"<?php echo $ct==='physiotherapist'?'selected':''; ?>>Physiotherapist</option>
          <option value="therapist"      <?php echo $ct==='therapist'?'selected':''; ?>>Therapist</option>
          <option value="mixed"          <?php echo $ct==='mixed'?'selected':''; ?>>Mixed</option>
        </select>
      </div>

      <div class="col-md-8">
        <label class="form-label">Care tasks (select all that apply)</label>
        <div class="row g-2">
          <?php
            $T = [
              'medication_administration'=>'Medication administration',
              'wound_care'=>'Wound care',
              'catheter_care'=>'Catheter care',
              'stoma_care'=>'Stoma care',
              'feeding_support'=>'Feeding / NG/PEG',
              'mobility_transfer'=>'Mobility & transfer',
              'hygiene_toileting'=>'Hygiene & toileting',
              'vital_monitoring'=>'Vital monitoring',
              'physiotherapy'=>'Physiotherapy / exercise',
              'dementia_support'=>'Dementia/behavioral support',
              'companionship'=>'Companionship/observation',
              'others'=>'Others'
            ];
            foreach ($T as $k=>$label):
          ?>
          <div class="col-md-6">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="tasks[]" value="<?php echo safe($k); ?>" <?php echo arr_has($tasks,$k)?'checked':''; ?>>
              <label class="form-check-label"><?php echo safe($label); ?></label>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="col-md-3">
        <label class="form-label">Start date</label>
        <input type="date" name="start_date" class="form-control" value="<?php echo safe($patient['start_date'] ?? ''); ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Shift type</label>
        <?php $stt=$patient['shift_type'] ?? ''; ?>
        <select name="shift_type" class="form-select">
          <option value="">Select</option>
          <option value="day"    <?php echo $stt==='day'?'selected':''; ?>>Day</option>
          <option value="night"  <?php echo $stt==='night'?'selected':''; ?>>Night</option>
          <option value="24h"    <?php echo $stt==='24h'?'selected':''; ?>>24-hr</option>
          <option value="hourly" <?php echo $stt==='hourly'?'selected':''; ?>>Hourly</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Hours per day</label>
        <input type="number" step="0.5" name="hours_per_day" class="form-control" value="<?php echo safe($patient['hours_per_day'] ?? ''); ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Days per week</label>
        <input type="number" min="1" max="7" name="days_per_week" class="form-control" value="<?php echo safe($patient['days_per_week'] ?? ''); ?>">
      </div>

      <div class="col-md-12">
        <label class="form-label">Duration (one-time / weeks / ongoing)</label>
        <input name="duration_text" class="form-control" value="<?php echo safe($patient['duration_text'] ?? ''); ?>">
      </div>

      <div class="col-md-4">
        <label class="form-label">Language preference</label>
        <input name="language_pref" class="form-control" value="<?php echo safe($patient['language_pref'] ?? ''); ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Caregiver gender preference</label>
        <?php $gp = $patient['caregiver_gender_pref'] ?? ''; ?>
        <select name="caregiver_gender_pref" class="form-select">
          <option value="">Any</option>
          <option value="male"   <?php echo $gp==='male'?'selected':''; ?>>Male</option>
          <option value="female" <?php echo $gp==='female'?'selected':''; ?>>Female</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Religious / dietary preferences</label>
        <input name="religion_diet_prefs" class="form-control" placeholder="veg/halal/prayer timing sensitivity…" value="<?php echo safe($patient['religion_diet_prefs'] ?? ''); ?>">
      </div>

      <div class="col-md-12">
        <label class="form-label">Privacy constraints</label>
        <input name="privacy_notes" class="form-control" placeholder="e.g., female caregiver for personal care only" value="<?php echo safe($patient['privacy_notes'] ?? ''); ?>">
      </div>

      <div class="col-md-3">
        <div class="form-check mt-4">
          <input class="form-check-input" type="checkbox" id="ppe_required" name="ppe_required" value="1" <?php echo !empty($patient['ppe_required'])?'checked':''; ?>>
          <label class="form-check-label" for="ppe_required">PPE required</label>
        </div>
      </div>
      <div class="col-md-9">
        <label class="form-label">Isolation / infection control notes</label>
        <input name="isolation_notes" class="form-control" value="<?php echo safe($patient['isolation_notes'] ?? ''); ?>">
      </div>
    </div>

    <hr class="my-4">

    <!-- Billing & Admin -->
    <h5 class="section-title mb-2">Billing & Admin</h5>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Payer name (who will pay)</label>
        <input name="payer_name" class="form-control" value="<?php echo safe($patient['payer_name'] ?? ''); ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Payer phone</label>
        <input name="payer_phone" class="form-control" value="<?php echo safe($patient['payer_phone'] ?? ''); ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Payer email</label>
        <input type="email" name="payer_email" class="form-control" value="<?php echo safe($patient['payer_email'] ?? ''); ?>">
      </div>

      <div class="col-md-12">
        <label class="form-label">Billing address / NID (optional)</label>
        <input name="billing_address" class="form-control" value="<?php echo safe($patient['billing_address'] ?? ''); ?>">
      </div>

      <div class="col-md-4">
        <label class="form-label">Payment mode</label>
        <?php $pmode = $patient['payment_mode'] ?? ''; ?>
        <select name="payment_mode" class="form-select">
          <option value="">Select</option>
          <option value="cash" <?php echo $pmode==='cash'?'selected':''; ?>>Cash</option>
          <option value="bank" <?php echo $pmode==='bank'?'selected':''; ?>>Bank</option>
          <option value="mfs"  <?php echo $pmode==='mfs'?'selected':''; ?>>MFS</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Insurance provider (optional)</label>
        <input name="insurance_provider" class="form-control" value="<?php echo safe($patient['insurance_provider'] ?? ''); ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Policy no. (optional)</label>
        <input name="insurance_policy_no" class="form-control" value="<?php echo safe($patient['insurance_policy_no'] ?? ''); ?>">
      </div>

      <div class="col-md-6">
        <label class="form-label">Estimated budget / expected rate (optional)</label>
        <input name="budget_note" class="form-control" value="<?php echo safe($patient['budget_note'] ?? ''); ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Invoice recipient email(s)</label>
        <input name="invoice_emails" class="form-control" placeholder="comma-separated if multiple" value="<?php echo safe($patient['invoice_emails'] ?? ''); ?>">
      </div>
    </div>

    <hr class="my-4">

    <!-- Documents & Consent -->
    <h5 class="section-title mb-2">Documents & Consent</h5>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Patient ID (optional)</label>
        <input type="file" class="form-control" name="doc_patient_id" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
        <?php if (!empty($patient['patient_id_doc_url'])): ?>
          <div class="small mt-1">Existing: <a target="_blank" href="<?php echo '../'.safe($patient['patient_id_doc_url']); ?>">Open</a></div>
        <?php endif; ?>
      </div>
      <div class="col-md-6">
        <label class="form-label">Latest discharge summary / prescription</label>
        <input type="file" class="form-control" name="doc_discharge" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
        <?php if (!empty($patient['discharge_summary_url'])): ?>
          <div class="small mt-1">Existing: <a target="_blank" href="<?php echo '../'.safe($patient['discharge_summary_url']); ?>">Open</a></div>
        <?php endif; ?>
      </div>
      <div class="col-md-6">
        <label class="form-label">Medical fitness notes (if any)</label>
        <input type="file" class="form-control" name="doc_medical_fitness" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
        <?php if (!empty($patient['medical_fitness_url'])): ?>
          <div class="small mt-1">Existing: <a target="_blank" href="<?php echo '../'.safe($patient['medical_fitness_url']); ?>">Open</a></div>
        <?php endif; ?>
      </div>
      <div class="col-md-6">
        <label class="form-label">Patient photo (optional)</label>
        <input type="file" class="form-control" name="doc_patient_photo" accept=".jpg,.jpeg,.png,.webp">
        <?php if (!empty($patient['patient_photo_url'])): ?>
          <div class="small mt-1">Existing: <a target="_blank" href="<?php echo '../'.safe($patient['patient_photo_url']); ?>">Open</a></div>
        <?php endif; ?>
      </div>
      <div class="col-md-12">
        <label class="form-label">Wound images (optional, multiple)</label>
        <input type="file" class="form-control" name="doc_wound_photos[]" accept=".jpg,.jpeg,.png,.webp" multiple>
        <div class="small-muted mt-1">Allowed: JPG/PNG/WEBP. Max 5–10MB each (server will validate).</div>
        <?php if ($woundImgs): ?>
          <div class="mt-2">
            <div class="fw-bold mb-1">Previously uploaded wound images</div>
            <div class="d-flex flex-wrap gap-2">
              <?php foreach ($woundImgs as $u): ?>
                <a class="btn btn-sm btn-outline-primary" target="_blank" href="<?php echo '../'.safe($u); ?>"><i class="bi bi-box-arrow-up-right me-1"></i>Open</a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <hr class="my-4">

    <!-- Consents -->
    <div class="row g-3">
      <div class="col-md-12">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="consent_data_privacy" name="consent_data_privacy" value="1" <?php echo !empty($patient['consent_data_privacy'])?'checked':''; ?> required>
          <label class="form-check-label" for="consent_data_privacy">I consent to CGMS storing & processing health data for care delivery. <span class="text-muted">(required)</span></label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="consent_treatment" name="consent_treatment" value="1" <?php echo !empty($patient['consent_treatment'])?'checked':''; ?> required>
          <label class="form-check-label" for="consent_treatment">I consent to nursing/personal care tasks as per plan. <span class="text-muted">(required)</span></label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="consent_home_visit" name="consent_home_visit" value="1" <?php echo !empty($patient['consent_home_visit'])?'checked':''; ?>>
          <label class="form-check-label" for="consent_home_visit">Home-visit consent & safety responsibilities acknowledged.</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="consent_photo" name="consent_photo" value="1" <?php echo !empty($patient['consent_photo'])?'checked':''; ?>>
          <label class="form-check-label" for="consent_photo">Consent to clinical photos (optional, clinical use only).</label>
        </div>
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" id="consent_emergency_escalation" name="consent_emergency_escalation" value="1" <?php echo !empty($patient['consent_emergency_escalation'])?'checked':''; ?>>
          <label class="form-check-label" for="consent_emergency_escalation">Emergency escalation consent (contact doctor/ER if needed).</label>
        </div>
      </div>

      <div class="col-md-3">
        <label class="form-label">Signed by</label>
        <?php $who = $patient['consent_signed_by'] ?? (!empty($patient['registrant_name']) ? 'registrant' : 'patient'); ?>
        <select name="signed_by" class="form-select">
          <option value="patient"    <?php echo $who==='patient'?'selected':''; ?>>Patient</option>
          <option value="registrant" <?php echo $who==='registrant'?'selected':''; ?>>Registrant / Relative</option>
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label">Signed name</label>
        <input name="signed_name" class="form-control" value="<?php echo safe($patient['consent_signed_name'] ?? ($who==='patient' ? ($patient['full_name'] ?? '') : ($patient['registrant_name'] ?? ''))); ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Signed relation (if registrant)</label>
        <input name="signed_relation" class="form-control" value="<?php echo safe($patient['consent_signed_relation'] ?? ($patient['registrant_relation'] ?? '')); ?>">
      </div>
    </div>

    <hr class="my-4">

    <div class="d-flex gap-2">
      <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i> Save Intake</button>
      <a class="btn btn-outline-secondary" href="<?php echo safe(url('patients/dashboard.php')); ?>"><i class="bi bi-speedometer2 me-1"></i> Back to Dashboard</a>
    </div>

  </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  /* Guardian toggle */
  document.getElementById('reg_is_guardian')?.addEventListener('change', (e) => {
    const row = document.getElementById('guardianRow');
    row.style.display = e.target.checked ? 'block' : 'none';
  });

  /* Add/remove medications */
  document.getElementById('btnAddMed')?.addEventListener('click', () => {
    const wrap = document.getElementById('medList');
    const div = document.createElement('div');
    div.className = 'row g-2 align-items-end mb-2 med-row';
    div.innerHTML = `
      <div class="col-md-4">
        <label class="form-label small">Name</label>
        <input class="form-control" name="medication_name[]">
      </div>
      <div class="col-md-4">
        <label class="form-label small">Dose</label>
        <input class="form-control" name="medication_dose[]">
      </div>
      <div class="col-md-3">
        <label class="form-label small">Frequency</label>
        <input class="form-control" name="medication_freq[]">
      </div>
      <div class="col-md-1 d-grid">
        <button type="button" class="btn btn-outline-danger btn-sm btnDelMed" title="Remove"><i class="bi bi-x"></i></button>
      </div>`;
    wrap.appendChild(div);
  });
  document.getElementById('medList')?.addEventListener('click', (e) => {
    if (e.target.closest('.btnDelMed')) {
      const row = e.target.closest('.med-row');
      if (row && document.querySelectorAll('#medList .med-row').length > 1) row.remove();
    }
  });

  /* BMI auto-calc */
  function calcBMI(){
    const h = parseFloat(document.getElementById('height_cm')?.value || '0');
    const w = parseFloat(document.getElementById('weight_kg')?.value || '0');
    const out = document.getElementById('bmi_view');
    const hid = document.getElementById('bmi');
    if (!out || !hid) return;
    if (h>0 && w>0){
      const m = h/100.0;
      const bmi = w/(m*m);
      out.value = bmi.toFixed(1);
      hid.value = bmi.toFixed(1);
    } else { out.value=''; hid.value=''; }
  }
  document.getElementById('height_cm')?.addEventListener('input', calcBMI);
  document.getElementById('weight_kg')?.addEventListener('input', calcBMI);
  calcBMI();

  /* Step dots highlight */
  (function () {
    const dots = document.querySelectorAll('.step-dot');
    const heads = Array.from(document.querySelectorAll('.section-title'));
    function update(){
      let idx = 0;
      const y = window.scrollY + 140;
      heads.forEach((h,i)=>{ if ((h.getBoundingClientRect().top + window.scrollY) < y) idx = Math.min(i+1, 6); });
      dots.forEach((d,i)=> d.classList.toggle('active', i===idx));
    }
    window.addEventListener('scroll', update, {passive:true});
    update();
  })();
</script>
</body>
</html>
