<?php
// /logout.php  — global logout handler
session_start();
require_once __DIR__ . '/config.php'; // for url()

// Clear session data
$_SESSION = [];

// Kill the session cookie (if any)
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'] ?? '/', $p['domain'] ?? '',
        $p['secure'] ?? false, $p['httponly'] ?? true
    );
}

// Optional: forget any app remember tokens if you ever set them
foreach (['remember_me','remember_token','auth'] as $ck) {
    if (isset($_COOKIE[$ck])) {
        setcookie($ck, '', time() - 3600, '/', '', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', true);
    }
}

session_destroy();

// Redirect to login
$dest = url('login.php');
header('Location: ' . $dest);

// Fallback in case headers already sent
echo '<!doctype html><meta http-equiv="refresh" content="0;url=' .
     htmlspecialchars($dest, ENT_QUOTES, 'UTF-8') . '"><a href="' .
     htmlspecialchars($dest, ENT_QUOTES, 'UTF-8') . '">Continue</a>';
exit;
