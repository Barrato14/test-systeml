<?php
require_once __DIR__ . '/session.php';

function isLoggedIn() {
    return isset($_SESSION['user_id']) && (string)$_SESSION['user_id'] !== '';
}

function requireAuth() {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit;
    }
}

function normalizeZentrixRole($role) {
    return strtolower(trim(str_replace(['_', '-'], ' ', (string)$role)));
}

function userHasRole($allowedRoles) {
    $allowed = array_map('normalizeZentrixRole', (array)$allowedRoles);
    $current = normalizeZentrixRole($_SESSION['user_role'] ?? '');
    return $current !== '' && in_array($current, $allowed, true);
}

function requireRole($allowedRoles) {
    requireAuth();
    if (!userHasRole($allowedRoles)) {
        http_response_code(403);
        die("Access Denied: Insufficient permissions for this module.");
    }
}

function csrfToken($key = 'zentrix_csrf') {
    if (empty($_SESSION[$key]) || !is_string($_SESSION[$key])) {
        $_SESSION[$key] = bin2hex(random_bytes(32));
    }
    return $_SESSION[$key];
}

function verifyCsrfToken($submittedToken, $key = 'zentrix_csrf') {
    return is_string($submittedToken)
        && $submittedToken !== ''
        && isset($_SESSION[$key])
        && is_string($_SESSION[$key])
        && hash_equals($_SESSION[$key], $submittedToken);
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
    switch (normalizeZentrixRole($role)) {
        case 'administrator':
        case 'event organizer':
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