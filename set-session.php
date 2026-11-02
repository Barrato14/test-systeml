<?php
session_start();
require_once 'config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    $email = trim($_POST['email']);
    $fullName = trim($_POST['full_name'] ?? 'Google User');

    // Check if user profile exists
    $response = supabaseRequest('profiles?email=eq.' . urlencode($email), 'GET');
    $rows = [];
    if (is_array($response)) {
        $rows = isset($response['data']) ? $response['data'] : $response;
    }
    
    $user = (!empty($rows) && isset($rows[0])) ? $rows[0] : null;

    // Auto-create profile if it doesn't exist yet
    if (!$user) {
        $nameParts = explode(' ', $fullName, 2);
        $payload = [
            'first_name' => $nameParts[0],
            'last_name' => $nameParts[1] ?? '',
            'email' => $email,
            'role' => 'user',
            'is_verified' => true
        ];
        supabaseRequest('profiles', 'POST', $payload);
        $user = $payload;
    }

    // Set session variables securely
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'] ?? '';
    $_SESSION['user_email'] = $email;
    $_SESSION['user_role'] = $user['role'] ?? 'user';
    $_SESSION['user_name'] = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? $fullName));

    // Redirect straight to the participant dashboard
    header('Location: participant/dashboard.php');
    exit;
}

header('Location: login.php');
exit;