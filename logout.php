<?php
require_once 'config/database.php';
require_once 'includes/auth.php';

if (function_exists('isLoggedIn') && isLoggedIn()) {
    // Logout intentionally clears the active PHP session only.
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $cookieParams = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $cookieParams['path'] ?? '/',
        'domain' => $cookieParams['domain'] ?? '',
        'secure' => (bool)($cookieParams['secure'] ?? false),
        'httponly' => (bool)($cookieParams['httponly'] ?? true),
        'samesite' => $cookieParams['samesite'] ?? 'Lax'
    ]);
}
session_destroy();

header("Location: login.php");
exit;
?>