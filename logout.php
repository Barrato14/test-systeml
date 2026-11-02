<?php
require_once 'config/database.php';
require_once 'includes/auth.php';

if (function_exists('isLoggedIn') && isLoggedIn()) {
    // Optional: If you want to log logout via Supabase REST API instead of PDO
    /*
    supabaseRequest('audit_logs', 'POST', [
        'user_id' => $_SESSION['user_id'] ?? null,
        'action' => 'Logout',
        'details' => 'User logged out'
    ]);
    */
}

session_unset();
session_destroy();

// Redirect back to login using a relative path so it doesn't break subfolders
header("Location: login.php");
exit;
?>