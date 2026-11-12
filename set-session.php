<?php
session_start();
require_once 'config/database.php';

function socialLoginFailure($message) {
    http_response_code(401);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Sign in failed</title><link rel="stylesheet" href="css/style.css"></head><body><main class="auth-layout"><section class="auth-form-wrapper"><div class="auth-form-container"><header class="form-header"><h2>Unable to sign in</h2><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></header><p class="register-prompt"><a href="login.php">Return to sign in</a></p></div></section></main></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

$accessToken = trim($_POST['access_token'] ?? '');
if ($accessToken === '') {
    socialLoginFailure('The identity provider did not return an access token. Please try again.');
}

// Validate the token directly with Supabase Auth. Never trust an email or name
// posted by the browser to establish an authenticated application session.
$authUrl = rtrim($supabaseUrl, '/') . '/auth/v1/user';
$ch = curl_init($authUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPGET => true,
    CURLOPT_HTTPHEADER => [
        'apikey: ' . $apiKey,
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json'
    ],
    CURLOPT_TIMEOUT => 15
]);
$authResponse = curl_exec($ch);
$authStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$authError = curl_error($ch);
curl_close($ch);

if ($authResponse === false || $authError || $authStatus !== 200) {
    socialLoginFailure('Supabase could not verify your sign-in. Please try again.');
}

$authUser = json_decode($authResponse, true);
$email = strtolower(trim($authUser['email'] ?? ''));
$authUserId = $authUser['id'] ?? '';
$emailConfirmed = !empty($authUser['email_confirmed_at']) || !empty($authUser['confirmed_at']);

if ($email === '' || $authUserId === '') {
    socialLoginFailure('The provider did not return an email address for this account. Check your provider permissions and try again.');
}

if (!$emailConfirmed) {
    socialLoginFailure('Your Google or Facebook account did not return a confirmed email address. Please verify your email with that provider or use email registration.');
}

$metadata = $authUser['user_metadata'] ?? [];
$fullName = trim($metadata['full_name'] ?? $metadata['name'] ?? '');
if ($fullName === '') {
    $first = trim($metadata['given_name'] ?? $metadata['first_name'] ?? '');
    $last = trim($metadata['family_name'] ?? $metadata['last_name'] ?? '');
    $fullName = trim($first . ' ' . $last);
}
if ($fullName === '') {
    $fullName = strstr($email, '@', true) ?: 'Participant';
}
$nameParts = preg_split('/\s+/', $fullName, 2);
$firstName = $nameParts[0] ?? 'Participant';
$lastName = $nameParts[1] ?? '';

$profileResponse = supabaseRequest('profiles?email=eq.' . urlencode($email), 'GET');
$profileRows = is_array($profileResponse) ? ($profileResponse['data'] ?? []) : [];
$profile = (!empty($profileRows) && isset($profileRows[0]) && is_array($profileRows[0])) ? $profileRows[0] : null;

if (!$profile) {
    $payload = [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'role' => 'Participant',
        'is_verified' => true,
        'verification_token' => null
    ];

    $insertResponse = supabaseRequest('profiles', 'POST', $payload);
    if (($insertResponse['code'] ?? 500) >= 300) {
        socialLoginFailure('Your identity was verified, but your EMIS profile could not be created. Please contact the administrator.');
    }

    $profileResponse = supabaseRequest('profiles?email=eq.' . urlencode($email), 'GET');
    $profileRows = is_array($profileResponse) ? ($profileResponse['data'] ?? []) : [];
    $profile = (!empty($profileRows) && isset($profileRows[0]) && is_array($profileRows[0])) ? $profileRows[0] : null;

    if (!$profile) {
        socialLoginFailure('Your EMIS profile could not be loaded. Please try again.');
    }
} else {
    // Provider-confirmed email is sufficient for social sign-in; synchronize
    // an existing profile that was previously left unverified by registration.
    $profileId = $profile['id'] ?? null;
    if (!$profileId) {
        socialLoginFailure('Your EMIS profile is missing its account ID. Please contact the administrator.');
    }

    $updateResponse = supabaseRequest('profiles?id=eq.' . urlencode($profileId), 'PATCH', [
        'is_verified' => true,
        'verification_token' => null
    ]);

    if (($updateResponse['code'] ?? 500) >= 300) {
        socialLoginFailure('Your provider account is verified, but EMIS could not update your profile verification status. Please try again later.');
    }
}

session_regenerate_id(true);
$userRole = $profile['role'] ?? 'Participant';
$_SESSION['user_id'] = $profile['id'] ?? '';
$_SESSION['user_email'] = $email;
$_SESSION['user_role'] = $userRole;
$_SESSION['user_name'] = trim(($profile['first_name'] ?? $firstName) . ' ' . ($profile['last_name'] ?? $lastName));

if (strtolower($userRole) === 'administrator') {
    header('Location: admin/dashboard.php');
} elseif (strtolower($userRole) === 'event organizer') {
    header('Location: organizer/dashboard.php');
} elseif (strtolower($userRole) === 'staff') {
    header('Location: staff/dashboard.php');
} else {
    header('Location: participant/dashboard.php');
}
exit;
