<?php
session_start();
require_once __DIR__ . '/../config.php'; // must define url() + $con (mysqli) if needed

// If already logged in as patient, send them to their dashboard
if (!empty($_SESSION['user_role']) && $_SESSION['user_role'] === 'patient') {
  header('Location: ' . url('patients/dashboard.php'));
  exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Small helpers
function safe($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

// Flash messages via query
$err = isset($_GET['err']) ? (string)$_GET['err'] : '';
$ok  = isset($_GET['ok'])  ? (string)$_GET['ok']  : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Patient Registration | CGMS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root{ --pri:#1d4ed8; --acc:#06b6d4; --ink:#0f172a; --mut:#64748b; }
body{
  min-height:100vh;
  background:
    radial-gradient(1000px 700px at -10% -10%, #e0f2fe 0%, transparent 60%),
    radial-gradient(1000px 700px at 110% 0%, #e0fffb 0%, transparent 60%),
    #f8fafc;
  color:var(--ink);
}
.navbar{ backdrop-filter: blur(8px); background: rgba(255,255,255,.9)!important; border-bottom:1px solid rgba(15,23,42,.06); }
.brand{ font-weight:800; background: linear-gradient(90deg, var(--pri), var(--acc)); -webkit-background-clip:text; color:transparent; }
.card-soft{ background:#fff; border:1px solid rgba(15,23,42,.08); border-radius:18px; box-shadow:0 16px 40px rgba(2,6,23,.07); }
.section-title{ font-weight:700; }
.fade-up{ opacity:0; transform:translateY(12px); animation:fadeUp .5s ease forwards; }
@keyframes fadeUp{ to{ opacity:1; transform:none; } }
.badge-soft{ background:rgba(29,78,216,.08); color:#1d4ed8; border:1px solid rgba(29,78,216,.18); padding:.2rem .5rem; border-radius:9px; }
.step{ display:flex; align-items:center; gap:.6rem; font-weight:600; color:#334155; }
.step .dot{
  width:28px; height:28px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center;
  background:linear-gradient(90deg,var(--pri),#3b82f6); color:#fff; font-size:.9rem; box-shadow:0 6px 18px rgba(29,78,216,.25);
}
hr.soft{ border:0; height:1px; background:linear-gradient(90deg, rgba(2,6,23,.12), rgba(2,6,23,.04)); }
.form-hint{ color:var(--mut); font-size:.875rem; }
.btn-accent{ background:linear-gradient(90deg,var(--acc),#22d3ee); border:none; color:#052e2b; }
.small-muted{ font-size:.9rem; color:#64748b; }
.required::after{ content:" *"; color:#ef4444; font-weight:600; }
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg sticky-top">
  <div class="container">
    <a class="navbar-brand brand" href="<?php echo safe(url('index.php')); ?>">CGMS</a>
    <div class="ms-auto d-flex align-items-center gap-2">
      <a class="btn btn-sm btn-outline-secondary" href="<?php echo safe(url('login.php')); ?>">
        <i class="bi bi-box-arrow-in-right me-1"></i> Login
      </a>
    </div>
  </div>
</nav>

<div class="container py-4 py-md-5">
  <div class="row justify-content-center">
    <div class="col-lg-9 col-xl-8">
      <div class="card card-soft p-3 p-md-4 fade-up">
        <div class="d-flex align-items-center justify-content-between">
          <div class="step"><span class="dot">1</span> Create Patient Account</div>
          <span class="badge-soft">Care at Home</span>
        </div>

        <?php if ($err): ?>
          <div class="alert alert-danger mt-3 mb-0"><i class="bi bi-exclamation-triangle me-1"></i><?php echo safe($err); ?></div>
        <?php elseif ($ok): ?>
          <div class="alert alert-success mt-3 mb-0"><i class="bi bi-check2-circle me-1"></i><?php echo safe($ok); ?></div>
        <?php endif; ?>

        <hr class="soft my-3">

        <form id="regForm" method="post" action="<?php echo safe(url('patients/register_save.php')); ?>" novalidate>
          <input type="hidden" name="csrf" value="<?php echo safe($_SESSION['csrf_token']); ?>">
          <!-- new: tag patient source for patients.source -->
          <input type="hidden" name="source" value="web">

          <!-- Who is registering -->
          <div class="mb-3">
            <label class="form-label required">Who is filling this form?</label>
            <div class="d-flex flex-wrap gap-2">
              <input type="hidden" name="reg_role" id="reg_role" value="self">
              <button type="button" class="btn btn-primary btn-sm" id="btnSelf"><i class="bi bi-person me-1"></i> I am the patient</button>
              <button type="button" class="btn btn-outline-primary btn-sm" id="btnRelative"><i class="bi bi-people me-1"></i> I’m a relative / guardian</button>
              <div class="small-muted ms-1">You can add more clinical details later in Intake.</div>
            </div>
          </div>

          <!-- Patient section -->
          <div class="mt-3">
            <div class="section-title mb-2">Patient</div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label required">Full name</label>
                <input type="text" class="form-control" name="patient_name" required>
              </div>
              <div class="col-md-3">
                <label class="form-label">Date of birth</label>
                <input type="date" class="form-control" name="patient_dob">
              </div>
              <div class="col-md-3">
                <label class="form-label">Gender</label>
                <select class="form-select" name="patient_gender">
                  <option value="">Select…</option>
                  <option>male</option>
                  <option>female</option>
                  <option>other</option>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Phone (patient)</label>
                <input type="text" class="form-control" name="patient_phone" placeholder="01XXXXXXXXX">
              </div>
              <div class="col-md-6">
                <label class="form-label required">Service address (care location)</label>
                <input type="text" class="form-control" name="service_address" required placeholder="Flat/House, Road, Area, Thana, District">
              </div>
            </div>
          </div>

          <hr class="soft my-4 d-none" id="sepRegistrant">

          <!-- Registrant / Relative section -->
          <div id="boxRegistrant" class="d-none">
            <div class="section-title mb-2">Registrant / Relative</div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label required">Full name</label>
                <input type="text" class="form-control" name="registrant_name">
              </div>
              <div class="col-md-6">
                <label class="form-label required">Relationship to patient</label>
                <input type="text" class="form-control" name="registrant_relation" placeholder="Parent, Spouse, Sibling…">
              </div>
              <div class="col-md-6">
                <label class="form-label required">Mobile (primary)</label>
                <input type="text" class="form-control" name="registrant_phone1" placeholder="01XXXXXXXXX">
              </div>
              <div class="col-md-6">
                <label class="form-label">Alternate phone</label>
                <input type="text" class="form-control" name="registrant_phone2" placeholder="">
              </div>
              <div class="col-md-6">
                <label class="form-label">Email</label>
                <input type="email" class="form-control" name="registrant_email" placeholder="name@example.com">
              </div>
              <div class="col-md-6">
                <label class="form-label">Preferred contact</label>
                <select class="form-select" name="preferred_contact">
                  <!-- values now lowercase to match ENUM('call','sms','whatsapp','email') -->
                  <option value="">Choose…</option>
                  <option value="call">Call</option>
                  <option value="sms">SMS</option>
                  <option value="whatsapp">WhatsApp</option>
                  <option value="email">Email</option>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label">Address (if different)</label>
                <input type="text" class="form-control" name="registrant_address" placeholder="House, Road, Area, Thana, District">
              </div>
              <div class="col-12 form-check mt-2">
                <input class="form-check-input" type="checkbox" id="chkGuardian" name="is_legal_guardian" value="1">
                <label class="form-check-label" for="chkGuardian">I am the legal guardian / decision-maker</label>
              </div>
              <div class="col-12">
                <div class="form-hint">You may upload proof of guardianship later in the Intake (optional).</div>
              </div>
            </div>
          </div>

          <hr class="soft my-4">

          <!-- Account section -->
          <div>
            <div class="section-title mb-2">Account Login</div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label required">Email (username)</label>
                <input type="email" class="form-control" id="account_email" name="account_email" required placeholder="name@example.com">
                <div class="form-hint" id="emailFeedback">We’ll use this to sign you in.</div>
              </div>

              <!-- Password with eye toggle -->
              <div class="col-md-3">
                <label class="form-label required">Password</label>
                <div class="input-group">
                  <input type="password" class="form-control" id="account_password" name="account_password" minlength="6" required>
                  <button class="btn btn-outline-secondary" type="button" id="togglePass1" aria-label="Show password"><i class="bi bi-eye"></i></button>
                </div>
                <div class="form-hint">Min 6 characters.</div>
              </div>

              <!-- Confirm with eye toggle + inline mismatch hint -->
              <div class="col-md-3">
                <label class="form-label required">Confirm</label>
                <div class="input-group">
                  <input type="password" class="form-control" id="account_password2" name="account_password2" minlength="6" required>
                  <button class="btn btn-outline-secondary" type="button" id="togglePass2" aria-label="Show password"><i class="bi bi-eye"></i></button>
                </div>
                <div class="invalid-feedback" id="confirmInvalid">Passwords do not match.</div>
                <div class="form-hint" id="pwMatchHint"></div>
              </div>
            </div>
          </div>

          <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" id="chkConsent" name="consent" value="1">
            <label class="form-check-label" for="chkConsent">
              I agree to the <a href="#" onclick="return false;">privacy & data processing</a> for care delivery.
            </label>
          </div>

          <div class="d-flex align-items-center gap-2 mt-4">
            <button class="btn btn-accent px-4" type="submit" id="btnSubmit">
              <i class="bi bi-person-plus me-1"></i> Create Account
            </button>
            <a class="btn btn-outline-secondary" href="<?php echo safe(url('login.php')); ?>">Already have an account? Login</a>
          </div>
        </form>
      </div>

      <div class="text-center small-muted mt-3">© <?php echo date('Y'); ?> CGMS • Patient Services</div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
  const roleInput = document.getElementById('reg_role');
  const btnSelf = document.getElementById('btnSelf');
  const btnRelative = document.getElementById('btnRelative');
  const boxRegistrant = document.getElementById('boxRegistrant');
  const sepRegistrant = document.getElementById('sepRegistrant');
  const form = document.getElementById('regForm');

  function setRole(role){
    roleInput.value = role;
    if (role === 'relative') {
      btnRelative.classList.remove('btn-outline-primary'); btnRelative.classList.add('btn-primary');
      btnSelf.classList.remove('btn-primary'); btnSelf.classList.add('btn-outline-primary');
      boxRegistrant.classList.remove('d-none');
      sepRegistrant.classList.remove('d-none');
      setRequired('registrant_name', true);
      setRequired('registrant_relation', true);
      setRequired('registrant_phone1', true);
    } else {
      btnSelf.classList.remove('btn-outline-primary'); btnSelf.classList.add('btn-primary');
      btnRelative.classList.remove('btn-primary'); btnRelative.classList.add('btn-outline-primary');
      boxRegistrant.classList.add('d-none');
      sepRegistrant.classList.add('d-none');
      setRequired('registrant_name', false);
      setRequired('registrant_relation', false);
      setRequired('registrant_phone1', false);
    }
  }

  function setRequired(name, req){
    const el = form.querySelector(`[name="${name}"]`);
    if (!el) return;
    if (req) el.setAttribute('required','required');
    else el.removeAttribute('required');
  }

  btnSelf.addEventListener('click', ()=> setRole('self'));
  btnRelative.addEventListener('click', ()=> setRole('relative'));
  setRole('self'); // default

  // ----- Password eye toggles -----
  const pw1 = document.getElementById('account_password');
  const pw2 = document.getElementById('account_password2');
  const tog1 = document.getElementById('togglePass1');
  const tog2 = document.getElementById('togglePass2');

  function toggleEye(inp, btn){
    const icon = btn.querySelector('i');
    inp.type = (inp.type === 'password') ? 'text' : 'password';
    icon.classList.toggle('bi-eye');
    icon.classList.toggle('bi-eye-slash');
  }
  tog1.addEventListener('click', () => toggleEye(pw1, tog1));
  tog2.addEventListener('click', () => toggleEye(pw2, tog2));

  // ----- Inline confirm mismatch feedback -----
  const pwHint = document.getElementById('pwMatchHint');
  const confirmInvalid = document.getElementById('confirmInvalid');

  function updateMatchUI(){
    pw2.classList.remove('is-valid','is-invalid');
    if (!pw2.value) { pwHint.textContent = ''; pwHint.className = 'form-hint'; return; }
    if (pw1.value === pw2.value) {
      pw2.classList.add('is-valid');
      pwHint.textContent = 'Passwords match.';
      pwHint.className = 'form-hint text-success';
    } else {
      pw2.classList.add('is-invalid');
      pwHint.textContent = 'Passwords do not match.';
      pwHint.className = 'form-hint text-danger';
    }
  }
  pw1.addEventListener('input', updateMatchUI);
  pw2.addEventListener('input', updateMatchUI);

  // ----- Submit validation (kept same behaviour) -----
  form.addEventListener('submit', (e) => {
    const role = roleInput.value;
    const p1 = pw1.value.trim();
    const p2 = pw2.value.trim();

    if (p1.length < 6) { e.preventDefault(); alert('Password must be at least 6 characters.'); return; }
    if (p1 !== p2)      { e.preventDefault(); updateMatchUI(); alert('Passwords do not match.'); return; }
    if (!form.consent.checked) { e.preventDefault(); alert('Please accept the consent to continue.'); return; }
    if (role === 'relative') {
      if (!form.registrant_name.value.trim() || !form.registrant_relation.value.trim() || !form.registrant_phone1.value.trim()) {
        e.preventDefault(); alert('Please complete the Registrant/Relative details.'); return;
      }
    }
    if (!form.patient_name.value.trim() || !form.service_address.value.trim()) {
      e.preventDefault(); alert('Please complete Patient name and Service address.'); return;
    }
  });

  // ----- Live Email Availability (keeps look) -----
  const emailInput = document.getElementById('account_email');
  const emailFeedback = document.getElementById('emailFeedback');
  const btnSubmit = document.getElementById('btnSubmit');
  let timer = null;

  emailInput.addEventListener('input', () => {
    clearTimeout(timer);
    const v = emailInput.value.trim();
    emailInput.classList.remove('is-valid','is-invalid');
    btnSubmit.disabled = false;
    if (!v) { emailFeedback.textContent = 'We’ll use this to sign you in.'; emailFeedback.className = 'form-hint'; return; }

    timer = setTimeout(() => {
      fetch('<?php echo safe(url("patients/ajax_check_email.php")); ?>?email=' + encodeURIComponent(v))
        .then(r => r.json())
        .then(data => {
          if (data && data.exists) {
            emailInput.classList.add('is-invalid');
            emailFeedback.textContent = 'Email already exists. Try another.';
            emailFeedback.className = 'form-hint text-danger';
            btnSubmit.disabled = true;
          } else {
            emailInput.classList.add('is-valid');
            emailFeedback.textContent = 'Email is available.';
            emailFeedback.className = 'form-hint text-success';
            btnSubmit.disabled = false;
          }
        })
        .catch(() => {
          emailFeedback.textContent = 'We’ll use this to sign you in.';
          emailFeedback.className = 'form-hint';
          btnSubmit.disabled = false;
        });
    }, 400);
  });
})();
</script>
</body>
</html>
