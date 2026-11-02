<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$accessToken = $data['access_token'] ?? null;

if (!$accessToken) {
    echo json_encode(['success' => false, 'message' => 'Missing token']);
    exit();
}

// Fetch user info from Supabase Auth using the access token
$ch = curl_init(getenv('SUPABASE_URL') . '/auth/v1/user');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $accessToken,
    'apikey: ' . getenv('SUPABASE_ANON_KEY')
]);
$response = curl_exec($ch);
curl_close($ch);

$userData = json_decode($response, true);

if (!isset($userData['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid Supabase session']);
    exit();
}

$email = $userData['email'];
$metadata = $userData['user_metadata'] ?? [];
$firstName = $metadata['full_name'] ?? explode('@', $email)[0];
$lastName = $metadata['last_name'] ?? '';

// Check if user already exists in profiles table
$check = supabaseRequest('profiles?email=eq.' . urlencode($email), 'GET');
$rows = isset($check['data']) ? $check['data'] : $check;

if (empty($rows)) {
    // Automatically create profile for social login user
    $payload = [
        'email' => $email,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'role' => 'Participant',
        'password_hash' => 'OAUTH_USER' // Placeholder since password isn't used for social login
    ];
    $insert = supabaseRequest('profiles', 'POST', $payload);
    $userId = $insert['id'] ?? null;
} else {
    $userId = $rows[0]['id'];
    $role = $rows[0]['role'];
    $firstName = $rows[0]['first_name'];
}

// Set PHP Session variables
$_SESSION['user_id'] = $userId;
$_SESSION['user_email'] = $email;
$_SESSION['user_name'] = $firstName;
$_SESSION['user_role'] = $role ?? 'Participant';

// Determine role-based redirect
$redirectUrl = 'participant/dashboard.php';
if ($_SESSION['user_role'] === 'Admin') $redirectUrl = 'admin/events.php';

echo json_encode(['success' => true, 'redirect' => $redirectUrl]);