<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireAuth() {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit;
    }
}

function requireRole($allowedRoles) {
    requireAuth();
    if (!in_array($_SESSION['user_role'], $allowedRoles)) {
        http_response_code(403);
        die("Access Denied: Insufficient permissions for this module.");
    }
}

// Updated to use Supabase REST API instead of PDO
function logAudit($profileId, $action, $details) {
    // Make sure supabaseRequest is available
    if (function_exists('supabaseRequest')) {
        supabaseRequest('audit_logs', 'POST', [
            'profile_id' => $profileId,
            'action' => $action,
            'details' => $details,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    }
}

function redirectByRole($role) {
    // Use relative paths so it doesn't break when running inside a subfolder
    switch ($role) {
        case 'Administrator': 
            header("Location: admin/dashboard.php"); 
            break;
        case 'Event Organizer': 
            header("Location: organizer/dashboard.php"); 
            break;
        case 'Staff': 
            header("Location: staff/dashboard.php"); 
            break;
        default: 
            header("Location: participant/dashboard.php"); 
            break;
    }
    exit;
}
?>