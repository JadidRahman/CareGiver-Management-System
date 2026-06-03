<?php
// verify-email.php (controller for OTP verify + resend)
session_start();
require_once __DIR__ . '/config.php';

/* ---- App meta (for links & email subject) ---- */
$appName = defined('APP_NAME') ? APP_NAME : 'CGMS';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$appUrl = defined('APP_URL') ? rtrim(APP_URL, '/') : ($scheme . '://' . $host . ($base ? $base : ''));

/* ---- Helpers ---- */
function only_digits($s)
{
  return preg_replace('/\D/', '', $s ?? '');
}
function go($url)
{
  header('Location: ' . $url);
  exit;
}

/* ---- Identify user to verify ---- */
$uid = (int) ($_POST['uid'] ?? $_GET['uid'] ?? $_SESSION['pending_user_id'] ?? 0);
if (!$uid) {
  go('caregiver-register.php?msg=session&type=danger');
}

/* =========================================================
 * RESEND OTP
 * GET /verify-email.php?uid=123&resend=1
 * =======================================================*/
if (isset($_GET['resend'])) {
  try {
    $otp = strval(random_int(100000, 999999));
    $exp = (new DateTime('+15 minutes'))->format('Y-m-d H:i:s');

    // Store new code & expiry
    $upd = $con->prepare("UPDATE users SET email_verification_code=?, email_verification_expires_at=? WHERE id=?");
    $upd->bind_param("ssi", $otp, $exp, $uid);
    $upd->execute();
    $upd->close();

    // Get recipient email
    $stmt = $con->prepare("SELECT email FROM users WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $stmt->bind_result($email);
    $found = $stmt->fetch();
    $stmt->close();

    if ($found && $email) {
      $verifyLink = $appUrl . "/verify-email.php?uid=" . urlencode((string) $uid);
      $emailHtml = "
        <h2>Verify your email</h2>
        <p>Use this one-time code to verify your email:</p>
        <div style='font-size:24px;font-weight:bold;letter-spacing:3px'>{$otp}</div>
        <p>Or click this link to verify: <a href='{$verifyLink}'>{$verifyLink}</a></p>
        <p>This code expires in 15 minutes.</p>
        <hr><small>{$appName}</small>";
      @send_email($email, 'Verify your email – ' . $appName, $emailHtml);
    } else {
      // Couldn't find user/email, send back to register
      go('caregiver-register.php?msg=nofound&type=danger');
    }

    // Back to OTP page with success flash
    go("success-caregiver.php?uid={$uid}&msg=resent&type=success");
  } catch (Throwable $t) {
    error_log('Resend OTP failed: ' . $t->getMessage());
    go("success-caregiver.php?uid={$uid}&msg=resend_failed&type=danger");
  }
}

/* =========================================================
 * VERIFY OTP (POST)
 * =======================================================*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $otp = only_digits($_POST['otp'] ?? '');
  if (strlen($otp) !== 6) {
    go("success-caregiver.php?uid={$uid}&msg=need6&type=warning");
  }

  // Load current verification info
  $stmt = $con->prepare("SELECT email, email_verification_code, email_verification_expires_at, email_verified_at, status FROM users WHERE id=? LIMIT 1");
  $stmt->bind_param("i", $uid);
  $stmt->execute();
  $stmt->bind_result($email, $code, $expires, $verifiedAt, $status);
  $found = $stmt->fetch();
  $stmt->close();

  if (!$found) {
    go('caregiver-register.php?msg=nofound&type=danger');
  }

  // Already verified
  if (!empty($verifiedAt)) {
    go('login.php?verified=1');
  }

  // No active code
  if (!$code) {
    go("success-caregiver.php?uid={$uid}&msg=nocode&type=warning");
  }

  // Expired?
  $now = new DateTime('now');
  $ex = $expires ? new DateTime($expires) : null;
  if ($ex && $now > $ex) {
    go("success-caregiver.php?uid={$uid}&msg=expired&type=warning");
  }

  // Match?
  if (!hash_equals($code, $otp)) {
    go("success-caregiver.php?uid={$uid}&msg=invalid&type=info");
  }

  // Success: mark verified
  $nowStr = (new DateTime())->format('Y-m-d H:i:s');
  $newStatus = ($status === 'pending') ? 'verified' : $status;

  $upd = $con->prepare("UPDATE users SET email_verified_at=?, email_verification_code=NULL, email_verification_expires_at=NULL, status=? WHERE id=?");
  $upd->bind_param("ssi", $nowStr, $newStatus, $uid);
  $upd->execute();
  $upd->close();

  // Clean up session flags
  unset($_SESSION['pending_user_id'], $_SESSION['pending_email']);
  $_SESSION['just_verified'] = 1;

  // Go to login
  go('login.php?verified=1');
}

/* If someone hits this file via GET without resend or POST */
http_response_code(405);
echo 'Method not allowed';
