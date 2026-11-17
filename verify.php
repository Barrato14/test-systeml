<?php
require_once 'config/database.php';

$token = trim((string)($_POST['token'] ?? $_GET['token'] ?? ''));
$message = '';
$isSuccess = false;
$showConfirmation = false;

if ($token === '') {
    $message = 'Invalid or missing verification token.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Only consume the verification token after the user explicitly presses Verify.
    // Email security scanners commonly open links automatically with GET requests.
    $response = supabaseRequest('profiles?verification_token=eq.' . urlencode($token) . '&select=id,is_verified', 'GET');
    $rows = is_array($response) && isset($response['data']) ? $response['data'] : $response;

    if (!is_array($rows) || empty($rows) || !isset($rows[0]['id'])) {
        $message = 'This verification link is invalid or has already been used. If your account is still unverified, request a new verification email.';
    } elseif (!empty($rows[0]['is_verified'])) {
        $isSuccess = true;
        $message = 'Your email is already verified. You can log in to your account.';
    } else {
        $userId = $rows[0]['id'];
        $updateResponse = supabaseRequest('profiles?id=eq.' . urlencode((string)$userId) . '&verification_token=eq.' . urlencode($token), 'PATCH', [
            'is_verified' => true,
            'verification_token' => null
        ]);

        if (!is_array($updateResponse) || ($updateResponse['code'] ?? 500) >= 400) {
            $message = 'We could not verify your email right now. Please try again.';
        } else {
            $isSuccess = true;
            $message = 'Your email has been successfully verified! You can now log in to your account.';
        }
    }
} else {
    // GET displays a confirmation page only. It never marks the account verified.
    $response = supabaseRequest('profiles?verification_token=eq.' . urlencode($token) . '&select=id,is_verified', 'GET');
    $rows = is_array($response) && isset($response['data']) ? $response['data'] : $response;

    if (!is_array($rows) || empty($rows)) {
        $message = 'This verification link is invalid or has already been used. If your account is still unverified, request a new verification email.';
    } elseif (!empty($rows[0]['is_verified'])) {
        $isSuccess = true;
        $message = 'Your email is already verified. You can log in to your account.';
    } else {
        $showConfirmation = true;
        $message = 'Your verification link is ready. Press the button below to verify your email address.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Email Verification - Zentrix</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://unpkg.com/@phosphor-icons/web"></script><link rel="stylesheet" href="css/style.css?v=20261117-6"><meta name="theme-color" content="#F8F6FB">
</head>
<body class="auth-page">
<main class="auth-standalone"><section class="auth-standalone-card">
<div class="brand-logo"><i class="ph-fill ph-ticket"></i><span>Zentrix</span></div>
<div class="auth-standalone-icon"><i class="ph-fill <?= $isSuccess ? 'ph-check-circle' : ($showConfirmation ? 'ph-envelope-simple' : 'ph-warning-circle') ?>"></i></div>
<h1><?= $isSuccess ? 'Email verified.' : ($showConfirmation ? 'Verify your email.' : 'Verification unavailable.') ?></h1>
<p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
<?php if ($showConfirmation): ?>
<form method="POST" action="verify.php"><input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>"><button type="submit" class="btn-primary">Verify my email</button></form>
<?php else: ?>
<a class="btn-primary" href="login.php"><?= $isSuccess ? 'Continue to sign in' : 'Return to sign in' ?></a>
<?php endif; ?>
<p class="auth-standalone-footer">Zentrix · Your campus, connected.</p>
</section></main>
</body></html>