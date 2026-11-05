<?php
session_start();
require_once 'config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    $email = strtolower(trim($_POST['email']));
    $fullName = trim($_POST['full_name'] ?? 'Google User');

    // Check if user profile already exists in Supabase
    $response = supabaseRequest('profiles?email=eq.' . urlencode($email), 'GET');
    $rows = [];
    if (is_array($response)) {
        $rows = isset($response['data']) ? $response['data'] : $response;
    }
    
    $user = (!empty($rows) && isset($rows[0])) ? $rows[0] : null;

    // If user doesn't exist yet, auto-create their profile
    if (!$user) {
        $nameParts = explode(' ', $fullName, 2);
        $payload = [
            'first_name' => $nameParts[0],
            'last_name' => $nameParts[1] ?? '',
            'email' => $email,
            'role' => 'Participant', // Default for brand new accounts
            'is_verified' => true
        ];
        
        // Insert new user
        supabaseRequest('profiles', 'POST', $payload);
        
        // RE-FETCH the user to get their newly generated database ID!
        $newResponse = supabaseRequest('profiles?email=eq.' . urlencode($email), 'GET');
        if (is_array($newResponse)) {
            $newRows = isset($newResponse['data']) ? $newResponse['data'] : $newResponse;
            $user = (!empty($newRows) && isset($newRows[0])) ? $newRows[0] : null;
        }
        
        // Failsafe check
        if (!$user) {
            header('Location: login.php?error=creation_failed');
            exit;
        }
    }

    // Set session variables securely
    session_regenerate_id(true);
    
    // Dynamically grab their actual role from the database
    $userRole = $user['role'] ?? 'Participant';
    
    $_SESSION['user_id'] = $user['id'] ?? '';
    $_SESSION['user_email'] = $email;
    $_SESSION['user_role'] = $userRole; // Respects Admin if they are an admin!
    $_SESSION['user_name'] = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

    // Redirect to the correct dashboard based on their role
    if (strtolower($userRole) === 'administrator') {
        header('Location: admin/dashboard.php');
    } else {
        header('Location: participant/dashboard.php');
    }
    exit;
}

header('Location: login.php');
exit;
