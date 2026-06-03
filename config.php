<?php
/**
 * config.php — CGMS (Local)
 * - Safe MySQL connection ($con)
 * - App constants (APP_NAME, APP_URL)
 * - Optional PHPMailer via Gmail SMTP
 * - Helpers guarded with function_exists to avoid redeclare errors
 */

declare(strict_types=1);

/* ---------------------------------------
|  App constants
|---------------------------------------- */
if (!defined('APP_NAME'))  define('APP_NAME', 'CGMS');
if (!defined('APP_URL'))   define('APP_URL', 'http://localhost/cgms'); // no trailing slash
if (!defined('BASE_PATH')) define('BASE_PATH', __DIR__);
if (!defined('CURRENCY'))  define('CURRENCY', '৳');
if (!defined('SUPPORT_EMAIL')) define('SUPPORT_EMAIL', 'contact@bdpsychiatriccare.com');

date_default_timezone_set('Asia/Dhaka');

/* ---------------------------------------
|  Database settings (local)
|---------------------------------------- */
$DB_HOST = 'localhost';
$DB_NAME = 'cgms';
$DB_USER = 'root';
$DB_PASS = '';
$DB_PORT = 3306;

/* ---------------------------------------
|  MySQLi connection ($con)
|---------------------------------------- */
if (!isset($con) || !($con instanceof mysqli)) {
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
  try {
    $con = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
    $con->set_charset('utf8mb4');
    // Stronger SQL mode
    $con->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
  } catch (mysqli_sql_exception $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Database connection failed.');
  }
}

/* ---------------------------------------
|  PHPMailer (optional local include)
|  Place these files at: /lib/PHPMailer/src/
|  - PHPMailer.php
|  - SMTP.php
|  - Exception.php
|  If not present, send_email() will safely no-op.
|---------------------------------------- */
$phpmailerBase = BASE_PATH . '/lib/PHPMailer/src';
$hasMailer = is_file($phpmailerBase.'/PHPMailer.php')
          && is_file($phpmailerBase.'/SMTP.php')
          && is_file($phpmailerBase.'/Exception.php');

if ($hasMailer) {
  require_once $phpmailerBase.'/PHPMailer.php';
  require_once $phpmailerBase.'/SMTP.php';
  require_once $phpmailerBase.'/Exception.php';

  if (!function_exists('mailer')) {
    function mailer(): \PHPMailer\PHPMailer\PHPMailer {
      $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
      // $mail->SMTPDebug = 2; // uncomment for verbose debug during testing

      $mail->isSMTP();
      $mail->Host       = 'smtp.gmail.com';
      $mail->SMTPAuth   = true;
      $mail->Username   = 'bpcljadidrahman@gmail.com';   // Gmail
      $mail->Password   = '';            // App Password
      $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
      $mail->Port       = 587;

      $mail->setFrom('bpcljadidrahman@gmail.com', APP_NAME);
      $mail->addReplyTo('bpcljadidrahman@gmail.com', APP_NAME);
      $mail->isHTML(true);
      $mail->CharSet = 'UTF-8';
      return $mail;
    }
  }

  if (!function_exists('send_email')) {
    function send_email($to, string $subject, string $html): bool {
      try {
        $m = mailer();
        if (is_array($to)) { foreach ($to as $addr) { if ($addr) $m->addAddress($addr); } }
        else { $m->addAddress($to); }
        $m->Subject = $subject;
        $m->Body    = $html;
        $m->AltBody = strip_tags($html);
        $m->send();
        return true;
      } catch (\Throwable $e) {
        error_log('Email send error: ' . $e->getMessage());
        return false;
      }
    }
  }
} else {
  // Graceful fallback when PHPMailer is not present locally
  if (!function_exists('send_email')) {
    function send_email($to, string $subject, string $html): bool { return false; }
  }
}

/* ---------------------------------------
|  Small helpers (guarded)
|---------------------------------------- */
if (!function_exists('e')) {
  function e(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('mask_email')) {
  function mask_email(string $email): string {
    if (strpos($email, '@') === false) return $email;
    [$u, $d] = explode('@', $email, 2);
    $u2 = substr($u, 0, 1) . str_repeat('*', max(1, strlen($u) - 2)) . substr($u, -1);
    return $u2 . '@' . $d;
  }
}

if (!function_exists('url')) {
  function url(string $path = '/'): string {
    return rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
  }
}

if (!function_exists('redirect')) {
  function redirect(string $path, int $code = 302): void {
    header('Location: ' . url($path), true, $code);
    exit;
  }
}

if (!function_exists('asset')) {
  function asset(string $path): string {
    return url(ltrim($path, '/'));
  }
}
