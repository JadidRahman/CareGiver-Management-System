<?php
session_start();
require_once __DIR__ . '/config.php';

/* ====== Static Admin Credential (change these) ====== */
const ADMIN_EMAIL_PLAIN = 'admin@cgms.local';
const ADMIN_PASS_PLAIN  = 'Admin@123';  // for demo only

/* ====== Helpers ====== */
function safe($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function go($path){ header('Location: ' . url($path)); exit; }

/* CSRF */
if (empty($_SESSION['csrf_login'])) $_SESSION['csrf_login'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_login'];

/* Flash from verification */
$verifiedBanner = isset($_GET['verified']);
/* Success message from registration redirect */
$okFromGet = isset($_GET['ok']) ? (string)$_GET['ok'] : '';
/* Optional error from query */
$errFromGet = isset($_GET['err']) ? (string)$_GET['err'] : '';

/* If already logged in, bounce to their dashboard */
if (!empty($_SESSION['user_role'])) {
    $role = $_SESSION['user_role'];
    $map = [
        'admin'     => 'admin/dashboard.php',
        'caregiver' => 'caregiver/dashboard.php',
        'patient'   => 'patients/dashboard.php', // fixed: patients/
        'user'      => 'user/dashboard.php',
    ];
    if (isset($map[$role])) go($map[$role]);
}

/* ====== Handle POST ====== */
$error = $errFromGet ?: null; // prefer POST errors later; but show ?err= if present
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_login'] ?? '', $_POST['csrf'] ?? '')) {
        $error = 'Invalid session token. Please refresh and try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $pass  = (string)($_POST['password'] ?? '');

        // 1) Static admin (demo)
        if ($email === ADMIN_EMAIL_PLAIN && $pass === ADMIN_PASS_PLAIN) {
            $_SESSION['user_id']    = 0;
            $_SESSION['user_email'] = $email;
            $_SESSION['user_role']  = 'admin';
            $_SESSION['user_name']  = 'Administrator';
            go('admin/dashboard.php');
        }

        // 2) Database users
        try {
            $stmt = $con->prepare("SELECT id, role, email, password_hash, status, email_verified_at
                                   FROM users WHERE email=? LIMIT 1");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->bind_result($id, $role, $dbEmail, $hash, $status, $verifiedAt);
            $found = $stmt->fetch();
            $stmt->close();

            if (!$found) {
                $error = 'Account not found.';
            } elseif (!password_verify($pass, $hash)) {
                $error = 'Wrong password.';
            } elseif (empty($verifiedAt) && $role !== 'admin') {
                // If you never want email verification, comment the next line:
                $error = 'Please verify your email before logging in.';
            } elseif ($status === 'suspended') {
                $error = 'Your account is suspended. Contact support.';
            } else {
                $_SESSION['user_id']    = (int)$id;
                $_SESSION['user_email'] = $dbEmail;
                $_SESSION['user_role']  = $role;
                $_SESSION['user_name']  = explode('@', $dbEmail)[0];

                $routes = [
                    'admin'     => 'admin/dashboard.php',
                    'caregiver' => 'caregiver/dashboard.php',
                    'patient'   => 'patients/dashboard.php', // fixed: patients/
                    'user'      => 'user/dashboard.php',
                ];
                go($routes[$role] ?? 'user/dashboard.php');
            }
        } catch (Throwable $t) {
            error_log('Login error: ' . $t->getMessage());
            $error = 'Unexpected error. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Login | CGMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root{ --pri:#1d4ed8; --acc:#06b6d4; --ink:#0f172a; --mut:#64748b; }
        body{
            min-height:100vh; display:grid; place-items:center;
            background: radial-gradient(900px 600px at 10% -10%, #e0f2fe 0%, transparent 60%),
                       radial-gradient(900px 600px at 110% 10%, #e0fffb 0%, transparent 60%), #f8fafc;
        }
        .card{ border-radius:18px; box-shadow:0 18px 48px rgba(2,6,23,.10); }
        .brand{ font-weight:800; background:linear-gradient(90deg, var(--pri), var(--acc)); -webkit-background-clip:text; color:transparent; letter-spacing:.3px; }
        .btn-primary{ background:linear-gradient(90deg, var(--pri), #3b82f6); border:none; }
    </style>
</head>
<body>
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
      <div class="card p-4 p-md-5">
        <div class="text-center mb-3">
          <a href="<?php echo safe(url('index.php')); ?>" class="brand h3 text-decoration-none">CGMS</a>
          <p class="text-muted mb-0">Care Giver Management System</p>
        </div>

        <?php if ($verifiedBanner): ?>
          <div class="alert alert-success small">Email verified successfully. Please sign in.</div>
        <?php endif; ?>

        <?php if (!empty($okFromGet)): ?>
          <div class="alert alert-success small">
            <i class="bi bi-check2-circle me-1"></i><?php echo safe($okFromGet); ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
          <div class="alert alert-danger small">
            <i class="bi bi-exclamation-triangle me-1"></i><?php echo safe($error); ?>
          </div>
        <?php endif; ?>

        <form method="post" class="mt-2">
          <input type="hidden" name="csrf" value="<?php echo safe($csrf); ?>">
          <div class="mb-3">
            <label class="form-label">Email</label>
            <input name="email" type="email" class="form-control" required autofocus>
          </div>
          <div class="mb-2">
            <label class="form-label">Password</label>
            <input name="password" type="password" class="form-control" required>
          </div>
          <div class="d-grid mt-3">
            <button class="btn btn-primary" type="submit"><i class="bi bi-box-arrow-in-right me-1"></i>Sign In</button>
          </div>
        </form>

        <div class="d-flex justify-content-between mt-3">
          <a class="small" href="<?php echo safe(url('caregiver-register.php')); ?>">Become a Caregiver</a>
          <a class="small" href="<?php echo safe(url('success-caregiver.php')); ?>">Have an OTP?</a>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
