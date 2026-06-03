<?php
session_start();

/* ===== helpers ===== */
function safe($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function showBrandControls(): bool {
  return (!empty($_SESSION['user_role']) && $_SESSION['user_role']==='admin') || isset($_GET['branding']);
}

/* ===== handle attach / remove partner logo ===== */
$partnerLogo = $_SESSION['partner_logo'] ?? null;

/* ===== hero image session ===== */
$heroImage = $_SESSION['hero_image'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // remove partner logo
  if (isset($_POST['remove_logo'])) {
    if ($partnerLogo && is_string($partnerLogo)) {
      $path = __DIR__ . '/' . $partnerLogo;
      if (is_file($path)) @unlink($path);
    }
    unset($_SESSION['partner_logo']);
    header('Location: index.php'); exit;
  }

  // upload partner logo
  if (isset($_FILES['partner_logo']) && is_uploaded_file($_FILES['partner_logo']['tmp_name'])) {
    $f = $_FILES['partner_logo'];
    $maxSize = 2 * 1024 * 1024; // 2MB
    $allowed = [
      'image/png'  => 'png',
      'image/jpeg' => 'jpg',
      'image/webp' => 'webp',
      'image/gif'  => 'gif',
    ];
    if ($f['error'] === UPLOAD_ERR_OK && $f['size'] <= $maxSize) {
      $mime = @mime_content_type($f['tmp_name']) ?: ($f['type'] ?? '');
      if (!isset($allowed[$mime])) {
        $info = @getimagesize($f['tmp_name']);
        $mime = $info['mime'] ?? $mime;
      }
      if (isset($allowed[$mime])) {
        $ext = $allowed[$mime];
        $dir = __DIR__ . '/uploads/logos';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $name = 'partner_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
        $dest = $dir . '/' . $name;
        if (@move_uploaded_file($f['tmp_name'], $dest)) {
          $_SESSION['partner_logo'] = 'uploads/logos/' . $name;
        }
      }
    }
    header('Location: index.php'); exit;
  }

  // remove hero image
  if (isset($_POST['remove_hero_image'])) {
    if (!empty($_SESSION['hero_image'])) {
      $path = __DIR__ . '/' . $_SESSION['hero_image'];
      if (is_file($path)) @unlink($path);
    }
    unset($_SESSION['hero_image']);
    header('Location: index.php'); exit;
  }

  // upload hero image
  if (isset($_FILES['hero_image']) && is_uploaded_file($_FILES['hero_image']['tmp_name'])) {
    $f = $_FILES['hero_image'];
    $maxSize = 4 * 1024 * 1024; // 4MB
    $allowed = [
      'image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/gif'=>'gif'
    ];
    if ($f['error'] === UPLOAD_ERR_OK && $f['size'] <= $maxSize) {
      $mime = @mime_content_type($f['tmp_name']) ?: ($f['type'] ?? '');
      if (!isset($allowed[$mime])) {
        $info = @getimagesize($f['tmp_name']);
        $mime = $info['mime'] ?? $mime;
      }
      if (isset($allowed[$mime])) {
        $ext = $allowed[$mime];
        $dir = __DIR__ . '/uploads/hero';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $name = 'hero_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
        $dest = $dir . '/' . $name;
        if (@move_uploaded_file($f['tmp_name'], $dest)) {
          $_SESSION['hero_image'] = 'uploads/hero/' . $name;
        }
      }
    }
    header('Location: index.php'); exit;
  }
}

$partnerLogo = $_SESSION['partner_logo'] ?? null;
$heroImage   = $_SESSION['hero_image'] ?? null;
$heroImage = $_SESSION['hero_image'] ?? null;
$defaultHero = 'uploads/logos/cgms.png';
if (!$heroImage && is_file(__DIR__ . '/' . $defaultHero)) {
  $heroImage = $defaultHero;
}

?>
<!DOCTYPE html>
<html lang="bn">

<head>
  <meta charset="utf-8" />
  <title>CGMS — কেয়ার গিভার ম্যানেজমেন্ট সিস্টেম</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />

  <!-- Bootstrap & Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

  <!-- Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">

  <style>
    :root {
      --cgms-primary: #1d4ed8;
      --cgms-accent: #06b6d4;
      --cgms-ink: #0f172a;
      --cgms-muted: #64748b;
      --cgms-bg: #f8fafc;
      --glass-bg: rgba(255, 255, 255, 0.65);
      --glass-brd: rgba(255, 255, 255, 0.35);
    }

    * { box-sizing: border-box; }

    body {
      font-family: 'Hind Siliguri', 'Inter', system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji", "Segoe UI Emoji";
      color: var(--cgms-ink);
      background:
        radial-gradient(1200px 800px at 15% -10%, #e0f2fe 0%, rgba(224, 242, 254, 0) 60%),
        radial-gradient(1000px 600px at 120% 10%, #e0fffb 0%, rgba(224, 255, 251, 0) 60%),
        var(--cgms-bg);
    }

    /* Topbar */
    .topbar {
      background: linear-gradient(90deg, rgba(6, 182, 212, .08), rgba(29, 78, 216, .08));
      font-size: .925rem;
      color: var(--cgms-muted);
    }
    .topbar a { color: var(--cgms-ink); text-decoration: none; }
    .topbar .badge { background: linear-gradient(90deg, var(--cgms-accent), var(--cgms-primary)); }

    /* Navbar */
    .navbar {
      backdrop-filter: blur(12px);
      background: rgba(255, 255, 255, .8) !important;
      border-bottom: 1px solid rgba(15, 23, 42, .06);
    }
    .brand-logo {
      font-weight: 800;
      letter-spacing: .2px;
      background: linear-gradient(90deg, var(--cgms-primary), var(--cgms-accent));
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
    }
    /* Brand marks */
    .partner-logo{
      max-height:46px; object-fit:contain; vertical-align:middle;
      margin-right:.65rem; padding-right:.65rem;
      border-right:1px solid rgba(15,23,42,.12); opacity:.95;
    }
    .cgms-mark{ display:inline-block; vertical-align:middle; }

    .btn-primary {
      background: linear-gradient(90deg, var(--cgms-primary), #3b82f6);
      border: none;
      box-shadow: 0 10px 20px rgba(29, 78, 216, .18);
    }
    .btn-accent {
      background: linear-gradient(90deg, var(--cgms-accent), #22d3ee);
      border: none;
      color: #052e2b;
      box-shadow: 0 10px 20px rgba(6, 182, 212, .18);
    }
    .btn-ghost {
      background: rgba(29, 78, 216, .08);
      border: 1px solid rgba(29, 78, 216, .18);
      color: var(--cgms-primary);
    }
    .btn:hover { opacity: .95; transform: translateY(-1px); transition: .2s ease; }

    /* Hero */
    .hero { position: relative; padding: 96px 0 64px; overflow: clip; }
    .hero::before {
      content: ""; position: absolute; inset: -10% -10% auto -10%; height: 65%;
      background:
        radial-gradient(600px 300px at 10% 20%, rgba(29, 78, 216, .18), transparent 60%),
        radial-gradient(700px 320px at 90% 10%, rgba(6, 182, 212, .18), transparent 60%);
      filter: blur(20px); z-index: 0;
    }
    .hero-card {
      position: relative; z-index: 1; background: var(--glass-bg); border: 1px solid var(--glass-brd);
      box-shadow: 0 12px 36px rgba(2, 6, 23, .08); border-radius: 24px; padding: 32px; backdrop-filter: blur(8px);
    }
    .stagger-up { opacity: 0; transform: translateY(18px); }
    .reveal { animation: up .7s ease forwards; }
    @keyframes up { to { opacity: 1; transform: translateY(0); } }

    /* KPI cards */
    .kpi-card {
      background: #fff; border-radius: 18px; border: 1px solid rgba(15, 23, 42, .06);
      padding: 20px; box-shadow: 0 10px 24px rgba(2, 6, 23, .06); transition: transform .2s;
    }
    .kpi-card:hover { transform: translateY(-3px); }
    .kpi-number { font-weight: 800; font-size: 2rem; }

    /* Glass cards */
    .glass {
      background: var(--glass-bg); border: 1px solid var(--glass-brd); backdrop-filter: blur(6px);
      border-radius: 18px; box-shadow: 0 8px 28px rgba(2, 6, 23, .06);
    }

    /* Steps */
    .step { border-left: 4px solid rgba(29, 78, 216, .14); padding-left: 16px; }

    /* Helper */
    .helper .form-control, .helper .form-select {
      border-radius: 12px; border: 1px solid rgba(15, 23, 42, .12);
    }
    .helper-result { border-left: 4px solid var(--cgms-accent); }

    /* Hero image helpers */
    .hero-img-cover{
  width:100%;
  height:100%;
  object-fit:contain;       /* show whole image */
  object-position:center;   /* center it */
  background:#fff;          /* optional: white bars if aspect ratio differs */
  border-radius:.75rem;
}

    .hero-img-wrap{position:relative;}
    .hero-edit{position:absolute;top:.5rem;right:.5rem;z-index:2;}
    .hero-remove{position:absolute;top:.5rem;left:.5rem;z-index:2;}

    /* Footer */
    footer { background: #061427; color: #cbd5e1; }
    footer a { color: #e2e8f0; text-decoration: none; }
    .footer-bottom { background: #051225; color: #9fb4cc; }

    @media (max-width: 991.98px) { .hero { padding-top: 72px; } }
  </style>
</head>

<body>

  <!-- Topbar -->
  <div class="topbar py-2">
    <div class="container d-flex align-items-center justify-content-between">
      <div class="d-flex align-items-center gap-3">
        <span><i class="bi bi-telephone-outbound me-1"></i>09677604604</span>
        <span class="d-none d-md-inline"><i class="bi bi-envelope-open me-1"></i>contact@bdpsychiatriccare.com</span>
      </div>
      <div class="d-flex align-items-center gap-2">
        <span class="badge rounded-pill text-white px-3 py-2">নতুন</span>
        <small>CGMS v1 • ঢাকা</small>
      </div>
    </div>
  </div>

  <!-- Navbar -->
<?php
  // Path to your uploaded PNG (change if needed)
  $partnerLogo = is_file(__DIR__ . '/uploads/logos/logo.png')
    ? 'uploads/logos/logo.png'
    : ''; // leave empty if none
?>
<nav class="navbar navbar-expand-lg sticky-top bg-white" style="border-bottom:1px solid rgba(15,23,42,.06);">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2" href="index.php" title="CGMS">
      <?php if ($partnerLogo): ?>
        <!-- LEFT: partner logo -->
        <img
          src="<?php echo safe($partnerLogo); ?>"
          alt="লোগো"
          class="partner-logo d-none d-sm-inline-block">
      <?php else: ?>
        <!-- LEFT: fallback mark -->
        <svg class="cgms-mark me-1 d-none d-sm-inline-block" width="28" height="28" viewBox="0 0 24 24" aria-hidden="true">
          <rect x="2" y="2" width="20" height="20" rx="6" fill="#10b981"/>
          <path d="M8 12l2.5 2.5L16 9" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      <?php endif; ?>
      <!-- RIGHT: blue CGMS wordmark -->
      <span class="brand-logo">CGMS</span>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center">
        <li class="nav-item"><a class="nav-link" href="#how">কীভাবে কাজ করে</a></li>
        <li class="nav-item"><a class="nav-link" href="#features">বৈশিষ্ট্যসমূহ</a></li>
        <li class="nav-item"><a class="nav-link" href="#helper">সেবা খুঁজুন</a></li>
        <li class="nav-item"><a class="nav-link" href="#contact">যোগাযোগ</a></li>

        <li class="nav-item ms-lg-3">
          <div class="btn-group">
            <a href="user-login.php" class="btn btn-primary btn-sm">
              <i class="bi bi-box-arrow-in-right me-1"></i>লগইন
            </a>
            <button class="btn btn-ghost btn-sm dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false"></button>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="login.php">অ্যাডমিন লগইন</a></li>
              <li><a class="dropdown-item" href="login.php">কেয়ারগিভার লগইন</a></li>
              <li><a class="dropdown-item" href="patients/register.php">রোগী/আত্মীয় লগইন</a></li>
            </ul>
          </div>
        </li>

        <li class="nav-item ms-2">
          <a href="#" class="btn btn-accent btn-sm" data-bs-toggle="modal" data-bs-target="#registerModal">
            <i class="bi bi-person-plus me-1"></i>রেজিস্ট্রেশন
          </a>
        </li>
      </ul>
    </div>
  </div>
</nav>

  <!-- Hidden upload forms -->
  <?php if (showBrandControls()): ?>
    <!-- Partner logo upload -->
    <form id="logoForm" class="d-none" method="post" enctype="multipart/form-data">
      <input type="file" id="partnerLogoInput" name="partner_logo" accept="image/png,image/jpeg,image/webp,image/gif">
    </form>
    <!-- Hero image upload -->
    <form id="heroImageForm" class="d-none" method="post" enctype="multipart/form-data">
      <input type="file" id="heroImageInput" name="hero_image" accept="image/png,image/jpeg,image/webp,image/gif">
    </form>
  <?php endif; ?>

  <!-- Register Modal -->
  <div class="modal fade" id="registerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0" style="border-radius: 18px;">
        <div class="modal-header border-0">
          <h5 class="modal-title">আপনার রেজিস্ট্রেশন নির্বাচন করুন</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body pt-0">
          <div class="d-grid gap-2">
            <a href="patients/register.php" class="btn btn-primary btn-lg">
              <i class="bi bi-person-heart me-2"></i> রোগী / আত্মীয় রেজিস্ট্রেশন
            </a>
            <a href="caregiver-register.php" class="btn btn-accent btn-lg">
              <i class="bi bi-briefcase-heart me-2"></i> কেয়ারগিভার রেজিস্ট্রেশন
            </a>
          </div>
        </div>
        <div class="modal-footer border-0">
          <small class="text-secondary">ইতোমধ্যে রেজিস্টার্ড? <a href="user-login.php">লগইন করুন</a></small>
        </div>
      </div>
    </div>
  </div>

  <!-- Hero -->
  <section class="hero">
    <div class="container">
      <div class="row g-4 align-items-center">
        <div class="col-lg-6">
          <div class="stagger-up hero-card mb-4" style="animation-delay:.05s;">
            <h1 class="display-5 fw-bold mb-3">প্রিয়জনের জন্য বিশ্বস্ত কেয়ারগিভার</h1>
            <p class="lead text-secondary mb-4">
              যাচাইকৃত কেয়ারগিভারের সাথে মিল, রিয়েল-টাইম ভিজিট ট্র্যাকিং ও নিরাপদ পেমেন্ট—সব এক জায়গায়।
            </p>
            <div class="d-flex gap-2">
              <a href="patients/register.php" class="btn btn-primary btn-lg">
                <i class="bi bi-search-heart me-2"></i>এখনই সেবা নিন
              </a>
              <a href="caregiver-register.php" class="btn btn-ghost btn-lg">
                <i class="bi bi-briefcase-heart me-2"></i>কেয়ারগিভার হিসেবে যোগ দিন
              </a>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-6 col-md-3">
              <div class="kpi-card text-center">
                <div class="kpi-number" data-counter="245">0</div>
                <div class="small text-secondary">কেয়ারগিভার</div>
              </div>
            </div>
            <div class="col-6 col-md-3">
              <div class="kpi-card text-center">
                <div class="kpi-number" data-counter="1290">0</div>
                <div class="small text-secondary">সেবা-প্রাপ্ত রোগী</div>
              </div>
            </div>
            <div class="col-6 col-md-3">
              <div class="kpi-card text-center">
                <div class="kpi-number" data-counter="98">0</div>
                <div class="small text-secondary">সন্তুষ্টি %</div>
              </div>
            </div>
            <div class="col-6 col-md-3">
              <div class="kpi-card text-center">
                <div class="kpi-number" data-counter="24">0</div>
                <div class="small text-secondary">২৪/৭ সহায়তা</div>
              </div>
            </div>
          </div>
        </div>

        <!-- RIGHT visual with uploadable image -->
        <div class="col-lg-6">
          <div class="stagger-up" style="animation-delay:.2s;">
            <div class="ratio ratio-16x9 glass p-2 hero-img-wrap">
              <?php if (!empty($heroImage)): ?>
                <img src="<?php echo safe($heroImage); ?>" alt="CGMS হিরো ছবি" class="hero-img-cover">
              <?php else: ?>
                <div class="d-flex align-items-center justify-content-center rounded h-100"
                     style="background:linear-gradient(135deg, rgba(29,78,216,.12), rgba(6,182,212,.12));">
                  <div class="text-center p-3">
                    <i class="bi bi-activity display-1 text-primary d-block mb-3"></i>
                    <h5 class="mb-1">লাইভ “Now Serving” ট্র্যাকিং</h5>
                    <p class="text-secondary mb-0">কে কাকে সেবা দিচ্ছে তা রিয়েল-টাইমে দেখুন।</p>
                  </div>
                </div>
              <?php endif; ?>

              <?php if (showBrandControls()): ?>
                <!-- Edit button (open file chooser) -->
                <button type="button" class="btn btn-ghost btn-sm hero-edit"
                        onclick="document.getElementById('heroImageInput').click()">
                  <i class="bi bi-image me-1"></i> ছবি বদলান
                </button>
                <?php if (!empty($heroImage)): ?>
                  <!-- Remove current hero image -->
                  <form method="post" class="hero-remove">
                    <input type="hidden" name="remove_hero_image" value="1">
                    <button class="btn btn-danger btn-sm" title="ছবি মুছুন"><i class="bi bi-x-lg"></i></button>
                  </form>
                <?php endif; ?>
              <?php endif; ?>
            </div>

            <div class="d-flex gap-3 mt-3">
              <div class="glass p-3 w-50">
                <div class="d-flex align-items-center">
                  <i class="bi bi-shield-check text-primary fs-3 me-2"></i>
                  <div>
                    <strong>যাচাইকৃত প্রোফাইল</strong><br>
                    <small class="text-secondary">আইডি ও ট্রেনিং যাচাইকৃত</small>
                  </div>
                </div>
              </div>
              <div class="glass p-3 w-50">
                <div class="d-flex align-items-center">
                  <i class="bi bi-credit-card-2-front text-primary fs-3 me-2"></i>
                  <div>
                    <strong>নিরাপদ পেমেন্ট</strong><br>
                    <small class="text-secondary">ইনভয়েস ও ShurjoPay-রেডি</small>
                  </div>
                </div>
              </div>
            </div>
          </div><!-- /stagger -->
        </div>
      </div>
    </div>
  </section>

  <!-- Features -->
  <section id="features" class="py-5">
    <div class="container">
      <h2 class="text-center fw-bold mb-4">কেন CGMS?</h2>
      <p class="text-center text-secondary mb-5">পরিবারবান্ধব, ক্লিনিক্যাল সেফটি-ফার্স্ট ও অপারেশনস-অপ্টিমাইজড প্ল্যাটফর্ম।</p>
      <div class="row g-4">
        <div class="col-md-4">
          <div class="glass p-4 h-100">
            <i class="bi bi-people-fill fs-1 text-primary mb-3"></i>
            <h5>স্মার্ট ম্যাচিং</h5>
            <p class="text-secondary mb-0">স্কিল, ভাষা, লিঙ্গ-পছন্দ, অ্যাভেইলেবিলিটি ও নিকটত্ব অনুযায়ী ম্যাচ।</p>
          </div>
        </div>
        <div class="col-md-4">
          <div class="glass p-4 h-100">
            <i class="bi bi-heart-pulse fs-1 text-primary mb-3"></i>
            <h5>কেয়ার চেকলিস্ট</h5>
            <p class="text-secondary mb-0">মেডস, ভাইটালস, মবিলিটি, হাইজিন ও ক্ষত-চিকিৎসার টাস্ক টেমপ্লেট।</p>
          </div>
        </div>
        <div class="col-md-4">
          <div class="glass p-4 h-100">
            <i class="bi bi-geo-alt fs-1 text-primary mb-3"></i>
            <h5>উপস্থিতি ও জিপিএস</h5>
            <p class="text-secondary mb-0">চেক-ইন/আউট, লোকেশন ভেরিফিকেশন ও লাইভ স্ট্যাটাস।</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- How it works -->
  <section id="how" class="py-5 bg-light">
    <div class="container">
      <h2 class="fw-bold mb-4 text-center">কীভাবে কাজ করে</h2>
      <div class="row g-4">
        <div class="col-md-3">
          <div class="glass p-4 h-100 step">
            <span class="badge bg-primary-subtle text-primary mb-2">ধাপ ১</span>
            <h6>রোগী/আত্মীয় নিবন্ধন</h6>
            <p class="text-secondary mb-0">প্রয়োজন ও পছন্দসহ একটি সহজ প্রোফাইল তৈরি করুন।</p>
          </div>
        </div>
        <div class="col-md-3">
          <div class="glass p-4 h-100 step">
            <span class="badge bg-primary-subtle text-primary mb-2">ধাপ ২</span>
            <h6>ম্যাচ পান</h6>
            <p class="text-secondary mb-0">আপনার কেস অনুযায়ী সেরা কেয়ারগিভার তালিকা।</p>
          </div>
        </div>
        <div class="col-md-3">
          <div class="glass p-4 h-100 step">
            <span class="badge bg-primary-subtle text-primary mb-2">ধাপ ৩</span>
            <h6>সেবা শুরু করুন</h6>
            <p class="text-secondary mb-0">তালিকা অনুযায়ী সেবা; আপনি লাইভ ট্র্যাক করবেন।</p>
          </div>
        </div>
        <div class="col-md-3">
          <div class="glass p-4 h-100 step">
            <span class="badge bg-primary-subtle text-primary mb-2">ধাপ ৪</span>
            <h6>রিভিউ দিন ও পেমেন্ট করুন</h6>
            <p class="text-secondary mb-0">অভিজ্ঞতা রেটিং ও নিরাপদ অনলাইন পেমেন্ট।</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Patient Helper -->
  <section id="helper" class="py-5">
    <div class="container">
      <div class="row g-4 align-items-stretch">
        <div class="col-lg-7">
          <div class="glass p-4 h-100">
            <div class="d-flex align-items-center mb-3">
              <i class="bi bi-search-heart fs-3 text-primary me-2"></i>
              <h4 class="mb-0">আপনার কেয়ার প্ল্যান খুঁজুন</h4>
            </div>
            <form class="helper" id="careHelper">
              <div class="row g-3">
                <div class="col-sm-6">
                  <label class="form-label">সেবার ধরন</label>
                  <select class="form-select" name="type" required>
                    <option value="">নির্বাচন করুন…</option>
                    <option>নার্সিং</option>
                    <option>অ্যাটেনড্যান্ট</option>
                    <option>ফিজিওথেরাপি</option>
                    <option>শিশু/নিউরো সাপোর্ট</option>
                    <option>বয়স্কদের সেবা</option>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label">শিফট</label>
                  <select class="form-select" name="shift" required>
                    <option value="">নির্বাচন করুন…</option>
                    <option>দিন (৮–১০ ঘ)</option>
                    <option>রাত (৮–১০ ঘ)</option>
                    <option>২৪ ঘন্টা</option>
                    <option>ঘণ্টাভিত্তিক</option>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label">রোগীর লিঙ্গ</label>
                  <select class="form-select" name="pgender">
                    <option value="">যেকোনো</option>
                    <option>পুরুষ</option>
                    <option>নারী</option>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label">কেয়ারগিভারের লিঙ্গ</label>
                  <select class="form-select" name="cgender">
                    <option value="">যেকোনো</option>
                    <option>পুরুষ</option>
                    <option>নারী</option>
                  </select>
                </div>
                <div class="col-sm-12">
                  <label class="form-label">এলাকা / লোকেশন</label>
                  <input type="text" class="form-control" name="area" placeholder="যেমন: ধানমন্ডি, ঢাকা">
                </div>
                <div class="col-sm-12">
                  <label class="form-label">বিশেষ প্রয়োজন (ঐচ্ছিক)</label>
                  <input type="text" class="form-control" name="needs"
                    placeholder="যেমন: ক্ষত চিকিৎসা, ক্যাথেটার, ডিমেনশিয়া সাপোর্ট">
                </div>
                <div class="col-12 d-flex gap-2 mt-1">
                  <button class="btn btn-primary" type="submit"><i class="bi bi-magic me-1"></i> প্ল্যান সাজেস্ট করুন</button>
                  <button class="btn btn-ghost" type="reset"><i class="bi bi-eraser me-1"></i> রিসেট</button>
                </div>
              </div>
            </form>

            <div id="helperResult" class="alert alert-info mt-3 d-none helper-result">
              <div class="d-flex">
                <i class="bi bi-lightbulb me-2 fs-4 text-accent"></i>
                <div>
                  <strong>প্রস্তাবিত:</strong>
                  <div class="small" id="planText">সাবমিট করার পর আমরা একটি প্ল্যান সাজেস্ট করব।</div>
                </div>
              </div>
            </div>

            <div class="mt-3">
              <a href="patients/register.php" class="btn btn-accent btn-lg">
                <i class="bi bi-person-plus me-2"></i>রেজিস্টার করুন ও ম্যাচ পান
              </a>
            </div>
          </div>
        </div>

        <div class="col-lg-5">
          <div class="glass p-4 h-100">
            <h5 class="mb-3">যা পরিবারগুলো পছন্দ করেন</h5>
            <div class="d-flex align-items-start gap-3 mb-3">
              <i class="bi bi-shield-lock fs-3 text-primary"></i>
              <div>
                <strong>সেফটি ফার্স্ট</strong>
                <p class="text-secondary mb-0">যাচাইকৃত আইডি, ট্রেনিং প্রুফ ও মডারেটেড রিভিউ।</p>
              </div>
            </div>
            <div class="d-flex align-items-start gap-3 mb-3">
              <i class="bi bi-clock-history fs-3 text-primary"></i>
              <div>
                <strong>সময়ে সেবা</strong>
                <p class="text-secondary mb-0">চেক-ইন/আউট রিমাইন্ডার ও লেট অ্যালার্ট।</p>
              </div>
            </div>
            <div class="d-flex align-items-start gap-3">
              <i class="bi bi-cash-coin fs-3 text-primary"></i>
              <div>
                <strong>স্বচ্ছ বিলিং</strong>
                <p class="text-secondary mb-0">ইনভয়েস, অনলাইন পেমেন্ট ও পেআউট ট্রান্সপারেন্সি।</p>
              </div>
            </div>
            <hr class="my-4">
            <div class="d-flex align-items-center">
              <img src="https://via.placeholder.com/48x48.png?text=CG" class="rounded-circle me-3" alt="">
              <div>
                <div class="fw-semibold">“ম্যাচিংটা একদম ঠিক ছিল, আর ট্র্যাকিং আমাদের নিশ্চিন্ত করেছে।”</div>
                <small class="text-secondary">— রোগীর কন্যা, ধানমন্ডি</small>
              </div>
            </div>
          </div>
        </div>
      </div><!-- /row -->
    </div>
  </section>

  <!-- CTA banner -->
  <section class="py-5 text-center">
    <div class="container">
      <div class="glass p-4">
        <h4 class="mb-2">শুরু করতে প্রস্তুত?</h4>
        <p class="text-secondary mb-3">কয়েক মিনিটে কেস তৈরি করুন, আমরা যাচাইকৃত কেয়ারগিভার মিলিয়ে দেব।</p>
        <a href="create-case.php" class="btn btn-primary btn-lg">
          <i class="bi bi-clipboard-plus me-2"></i>কেস তৈরি করুন
        </a>
      </div>
    </div>
  </section>

  <!-- Footer -->
  <footer id="contact" class="pt-5">
    <div class="container">
      <div class="row g-4">
        <div class="col-md-4">
          <h5 class="text-white mb-3">CGMS</h5>
          <p class="mb-2">কেয়ার গিভার ম্যানেজমেন্ট সিস্টেম — বিশ্বস্ত কেয়ারগিভারের সাথে পরিবারের সংযোগ।</p>
          <p class="small text-secondary">ঢাকা, বাংলাদেশ</p>
        </div>
        <div class="col-md-4">
          <h6 class="text-white mb-3">গুরুত্বপূর্ণ লিংক</h6>
          <ul class="list-unstyled">
            <li><a href="#">আমাদের সম্পর্কে</a></li>
            <li><a href="#">গোপনীয়তা নীতি</a></li>
            <li><a href="#">শর্তাবলি</a></li>
            <li><a href="#">বাতিল ও রিফান্ড নীতি</a></li>
            <li><a href="contact.php">যোগাযোগ</a></li>
          </ul>
        </div>
        <div class="col-md-4">
          <h6 class="text-white mb-3">যোগাযোগ করুন</h6>
          <p class="mb-1"><i class="bi bi-telephone text-primary me-2"></i>09677604604</p>
          <p class="mb-3"><i class="bi bi-envelope-open text-primary me-2"></i>contact@bdpsychiatriccare.com</p>
          <div class="d-flex gap-2">
            <a class="btn btn-primary btn-sm rounded-circle" href="#"><i class="fab fa-facebook-f"></i></a>
            <a class="btn btn-primary btn-sm rounded-circle" href="#"><i class="fab fa-youtube"></i></a>
            <a class="btn btn-primary btn-sm rounded-circle" href="#"><i class="fab fa-linkedin-in"></i></a>
            <a class="btn btn-primary btn-sm rounded-circle" href="#"><i class="fab fa-instagram"></i></a>
          </div>
        </div>
      </div>
    </div>
    <div class="footer-bottom py-3 mt-4">
      <div class="container text-center">
        <small>© <span id="year"></span> CGMS. সর্বস্বত্ব সংরক্ষিত।</small>
      </div>
    </div>
  </footer>

  <!-- JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Stagger reveal on load
    window.addEventListener('load', () => {
      document.querySelectorAll('.stagger-up').forEach((el, i) => {
        setTimeout(() => el.classList.add('reveal'), i * 80);
      });
      document.getElementById('year').textContent = new Date().getFullYear();
    });

    // Counter animation
    const counters = document.querySelectorAll('[data-counter]');
    const options = { root: null, threshold: .25 };
    const obs = new IntersectionObserver((entries, observer) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          const el = entry.target;
          const target = +el.getAttribute('data-counter');
          let start = 0;
          const step = Math.max(1, Math.ceil(target / 80));
          const tick = () => {
            start += step;
            if (start >= target) { el.textContent = target; return; }
            el.textContent = start;
            requestAnimationFrame(tick);
          };
          tick();
          observer.unobserve(el);
        }
      });
    }, options);
    counters.forEach(c => obs.observe(c));

    // Helper: suggest a simple plan (Bangla)
    const helperForm = document.getElementById('careHelper');
    const helperResult = document.getElementById('helperResult');
    const planText = document.getElementById('planText');
    helperForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const fd = new FormData(helperForm);
      const type = fd.get('type') || 'সেবা';
      const shift = fd.get('shift') || 'দিন';
      const area = (fd.get('area') || 'আপনার এলাকা').trim();
      const cgender = fd.get('cgender') || 'যেকোনো';
      const needs = fd.get('needs') || 'সাধারণ সহায়তা';
      const suggestion = `${type} • ${shift} শিফট • ${cgender} কেয়ারগিভার • ${area}। ফোকাস: ${needs}। আনুমানিক শুরু: যাচাই সম্পন্নের ২৪–৪৮ ঘণ্টার মধ্যে।`;
      planText.textContent = suggestion;
      helperResult.classList.remove('d-none');
      helperResult.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });

    // Auto-submit file pickers
    document.getElementById('partnerLogoInput')?.addEventListener('change', function(){
      if (this.files && this.files.length) this.form.submit();
    });
    document.getElementById('heroImageInput')?.addEventListener('change', function(){
      if (this.files && this.files.length) this.form.submit();
    });
  </script>
</body>
</html>
