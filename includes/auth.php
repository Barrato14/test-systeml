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
    $payload = [
        'profile_id' => $profileId,
        'action' => $action,
        'details' => $details,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
    ];

    if (function_exists('supabaseServiceRequest')) {
        $response = supabaseServiceRequest('audit_logs', 'POST', $payload);
    } elseif (function_exists('supabaseRequest')) {
        $response = supabaseRequest('audit_logs', 'POST', $payload);
    } else {
        error_log('Zentrix audit log could not be written: Supabase request helper unavailable.');
        return false;
    }

    if (!is_array($response) || ($response['code'] ?? 500) < 200 || ($response['code'] ?? 500) >= 300) {
        error_log('Zentrix audit log write failed: ' . json_encode($response['data'] ?? []));
        return false;
    }
    return true;
}

function redirectByRole($role) {
    // Use relative paths so it doesn't break when running inside a subfolder
    switch (strtolower(trim((string)$role))) {
        case 'administrator':
            header("Location: admin/dashboard.php");
            break;

        case 'event organizer':
            header("Location: admin/dashboard.php");
            break;

        case 'staff':
            header("Location: admin/dashboard.php");
            break;

        default:
            header("Location: participant/dashboard.php");
            break;
    }
    exit;
}
?>
