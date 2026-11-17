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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification - Zentrix</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6 text-center">
            <div class="card shadow-sm p-4">
                <?php if ($isSuccess): ?>
                    <h3 class="text-success mb-3">Verification Successful!</h3>
                    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                    <a href="login.php" class="btn btn-primary mt-3">Proceed to Login</a>
                <?php elseif ($showConfirmation): ?>
                    <h3 class="mb-3">Verify Your Email</h3>
                    <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
                    <form method="POST" action="verify.php">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn btn-primary mt-2">Verify My Email</button>
                    </form>
                <?php else: ?>
                    <h3 class="text-danger mb-3">Verification Failed</h3>
                    <div class="alert alert-danger"><?= htmlspecialchars($message) ?></div>
                    <a href="login.php" class="btn btn-outline-secondary mt-3">Return to Login</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>