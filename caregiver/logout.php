<?php
// /caregiver/logout.php
// Logs the user out and redirects to the main login page.

declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config.php'; // must provide url()

// 1) Clear all session data
$_SESSION = [];

// 2) Kill the PHP session cookie
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

// 3) Clear any "remember me" style cookies your app may set
foreach (['remember_me', 'rememberme', 'cgms_auth', 'auth_token'] as $cookieName) {
    if (isset($_COOKIE[$cookieName])) {
        setcookie($cookieName, '', time() - 42000, '/', '', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), true);
    }
}

// 4) Destroy the session and rotate the ID
session_destroy();
session_start();
session_regenerate_id(true);
session_write_close();

// 5) Redirect to login
$loginUrl = function_exists('url') ? url('login.php') : '../login.php';
header('Location: ' . $loginUrl);
exit;
