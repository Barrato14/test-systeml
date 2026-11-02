<?php
require_once 'config/database.php';

$token = $_GET['token'] ?? null;
$message = '';
$isSuccess = false;

if (!$token) {
    $message = "Invalid or missing verification token.";
} else {
    // 1. Find user with this token
    $response = supabaseRequest('profiles?verification_token=eq.' . urlencode($token), 'GET');
    $rows = isset($response['data']) ? $response['data'] : $response;

    if (empty($rows)) {
        $message = "Invalid or expired verification token.";
    } else {
        $userId = $rows[0]['id'];

        // 2. Update profile: set is_verified = true and clear the token so it can't be reused
        $payload = [
            'is_verified' => true,
            'verification_token' => null
        ];

        // Supabase REST patch syntax using id filter
        $updateResponse = supabaseRequest('profiles?id=eq.' . $userId, 'PATCH', $payload);
        
        $isSuccess = true;
        $message = "Your email has been successfully verified! You can now log in to your account.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Email Verification - EMIS</title>
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