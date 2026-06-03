<?php
session_start();
require_once __DIR__ . '/config.php'; // DB + helpers

/* ---- App meta fallbacks ---- */
$appName = defined('APP_NAME') ? APP_NAME : 'CGMS';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$appUrl = defined('APP_URL') ? rtrim(APP_URL, '/') : ($scheme . '://' . $host . ($base ? $base : ''));

/* ---- Helpers ---- */
if (!function_exists('mask_email')) {
  function mask_email($email)
  {
    if (strpos($email, '@') === false)
      return $email;
    [$u, $d] = explode('@', $email, 2);
    $u2 = substr($u, 0, 1) . str_repeat('*', max(1, strlen($u) - 2)) . substr($u, -1);
    return $u2 . '@' . $d;
  }
}
function safe($s)
{
  return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/* ---- Pull pending data (prefer session, fallback to GET) ---- */
$uid = $_SESSION['pending_user_id'] ?? ($_GET['uid'] ?? '');
$uid = (string) (int) $uid; // normalize
$email = $_SESSION['pending_email'] ?? '';

/* If we have a uid but no email in session, fetch it for display */
if ($uid && !$email) {
  try {
    $stmt = $con->prepare("SELECT email FROM users WHERE id=? LIMIT 1");
    $id = (int) $uid;
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->bind_result($dbEmail);
    if ($stmt->fetch())
      $email = $dbEmail;
    $stmt->close();
  } catch (Throwable $t) {
    // just skip showing email if lookup fails
  }
}

$masked = $email ? mask_email($email) : 'your email';
$sessionOK = !empty($uid);

/* ---- Flash messages from verify-email.php ---- */
$flashKey = $_GET['msg'] ?? null;
$flashType = $_GET['type'] ?? null; // info|success|warning|danger (bootstrap)
$flashMap = [
  'resent' => 'A new verification code has been sent to your email.',
  'resend_failed' => 'Could not resend code. Please try again.',
  'need6' => 'Please enter the 6-digit code.',
  'nocode' => 'No active code found. Click “Resend code”.',
  'expired' => 'That code expired. Click “Resend code” for a new one.',
  'invalid' => 'Invalid code. Please try again.',
  'session' => 'Your verification session is missing. Please register again.',
  'nofound' => 'Account not found. Please register again.',
];
$flashMsg = $flashKey && isset($flashMap[$flashKey]) ? $flashMap[$flashKey] : null;
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title>Registration Submitted | <?php echo safe($appName); ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root {
      --pri: #1d4ed8;
      --acc: #06b6d4;
      --ink: #0f172a;
      --mut: #64748b;
    }

    body {
      background: radial-gradient(900px 600px at 10% -10%, #e0f2fe 0%, transparent 60%),
        radial-gradient(900px 600px at 110% 10%, #e0fffb 0%, transparent 60%), #f8fafc;
    }

    .card {
      border-radius: 18px;
      box-shadow: 0 14px 40px rgba(2, 6, 23, .08);
    }

    .brand {
      font-weight: 800;
      background: linear-gradient(90deg, var(--pri), var(--acc));
      -webkit-background-clip: text;
      color: transparent;
    }

    .otp-input {
      letter-spacing: 6px;
      font-weight: 700;
      text-align: center;
      font-size: 1.1rem;
    }
  </style>
</head>

<body class="py-5">
  <nav class="navbar navbar-expand-lg">
    <div class="container">
      <a class="navbar-brand brand" href="index.php"><?php echo safe($appName); ?></a>
    </div>
  </nav>

  <div class="container my-3">
    <div class="row justify-content-center">
      <div class="col-lg-6">
        <div class="card p-4">
          <?php if ($sessionOK): ?>
            <div class="d-flex align-items-center mb-2">
              <div class="me-2">
                <span class="badge bg-primary-subtle text-primary border border-primary">Almost done</span>
              </div>
            </div>
            <h3 class="mb-2">Registration submitted 🎉</h3>
            <?php if ($flashMsg): ?>
              <div class="alert alert-<?php echo safe($flashType ?: 'info'); ?> small mb-3">
                <?php echo safe($flashMsg); ?>
              </div>
            <?php endif; ?>
            <p class="text-muted mb-3">
              We’ve sent a 6-digit verification code to <strong><?php echo safe($masked); ?></strong>.
              Please check your inbox (and spam) and enter the code below to verify your email.
            </p>

            <form class="mt-2" method="post" action="verify-email.php" id="otpForm">
              <input type="hidden" name="uid" value="<?php echo safe((string) $uid); ?>">
              <div class="mb-3">
                <label class="form-label">Enter OTP</label>
                <input name="otp" id="otp" class="form-control otp-input" maxlength="6" placeholder="______"
                  inputmode="numeric" pattern="\d*" required>
                <div class="form-text">The code expires in 15 minutes.</div>
              </div>
              <div class="d-flex align-items-center gap-2">
                <button class="btn btn-primary" type="submit">
                  <i class="bi bi-shield-check me-1"></i>Verify Email
                </button>
                <a class="btn btn-outline-secondary"
                  href="verify-email.php?uid=<?php echo urlencode((string) $uid); ?>&resend=1" id="resendLink">
                  <i class="bi bi-arrow-repeat me-1"></i>Resend code
                </a>
              </div>
            </form>

            <hr class="my-4">
            <div class="d-flex justify-content-between">
              <small class="text-muted">After verification, an admin will review your profile and activate your
                account.</small>
              <a class="small" href="index.php">Back to Home</a>
            </div>
          <?php else: ?>
            <h4 class="mb-2">Session expired</h4>
            <p class="text-muted">We couldn’t find your verification session. Please register again to receive a new code.
            </p>
            <a class="btn btn-primary" href="caregiver-register.php">Go to Caregiver Registration</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Restrict OTP to digits, auto-focus & optional auto-submit on 6 digits
    const otp = document.getElementById('otp');
    const form = document.getElementById('otpForm');
    if (otp) {
      otp.addEventListener('input', () => {
        otp.value = otp.value.replace(/\D/g, '').slice(0, 6);
        if (otp.value.length === 6) {
          // form.submit(); // enable if you want auto-submit after 6 digits
        }
      });
      otp.focus();
    }
  </script>
</body>

</html>