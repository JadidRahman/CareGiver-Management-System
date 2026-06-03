<?php
// /admin/logout.php
session_start();
require_once __DIR__ . '/../config.php'; // provides url()

// Best-effort: clear known auth fields
unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['user_name']);

// Clear all session data
$_SESSION = [];

// Invalidate the session cookie if it exists
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy the session
@session_destroy();

// Compute where to send the user (root login)
$loginUrl = url('login.php');

// Redirect (with safe fallbacks)
if (!headers_sent()) {
    header('Location: ' . $loginUrl);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<title>Logging out…</title>
<meta http-equiv="refresh" content="0;url=<?php echo htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
<p>Redirecting to <a href="<?php echo htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8'); ?>">login</a>…</p>
<script>location.replace(<?php echo json_encode($loginUrl); ?>);</script>
</body></html>
