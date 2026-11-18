<?php
// This page lets a user request a password-reset link using their registered email.
// It checks the account, creates a secure temporary token, saves its hash, and emails the link through Brevo.
require_once 'config/database.php';
require_once 'includes/mail.php';

// These variables control the feedback shown after the form is submitted.
$message = '';
$error = '';
$email = '';

// Process the email form only when the user submits it.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Always show the same response so account existence is not disclosed.
        $message = 'If an account exists for that email, a password reset link will be sent shortly.';

        // Look up the profile by email. The public response stays generic to protect account privacy.
        $lookup = supabaseServiceRequest(
            'profiles?select=id,email,first_name,last_name&email=eq.' . rawurlencode($email),
            'GET'
        );
        $rows = $lookup['data'] ?? [];

        if (($lookup['code'] ?? 500) < 300 && is_array($rows) && !empty($rows[0])) {
            $profile = $rows[0];
            // Generate a random one-time token. Only its SHA-256 hash is stored in the database.
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresAt = gmdate('Y-m-d\TH:i:s\Z', time() + 600);

            // Save the token hash and its 10-minute expiration time to this user's profile.
            $saved = supabaseServiceRequest(
                'profiles?id=eq.' . rawurlencode((string)$profile['id']),
                'PATCH',
                [
                    'password_reset_token_hash' => $tokenHash,
                    'password_reset_expires_at' => $expiresAt
                ]
            );

            if (($saved['code'] ?? 500) >= 200 && ($saved['code'] ?? 500) < 300) {
                // Build the link sent to the user's email. The raw token is included only in this link.
                $resetLink = 'https://test-systeml.onrender.com/reset-password.php?token=' . urlencode($token);
                $firstName = htmlspecialchars($profile['first_name'] ?? 'there', ENT_QUOTES, 'UTF-8');
                $safeLink = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');
                $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;color:#202033">'
                    . '<h2>Password reset request</h2><p>Hi ' . $firstName . ',</p>'
                    . '<p>We received a request to reset your Zentrix password. Use the button below to choose a new password.</p>'
                    . '<p><a href="' . $safeLink . '" style="display:inline-block;padding:12px 20px;background:#7c3aed;color:#fff;text-decoration:none;border-radius:8px">Reset password</a></p>'
                    . '<p>This link expires in 10 minutes and can only be used once. If you did not request this, you can ignore this email.</p>'
                    . '<p>— Zentrix</p></div>';

                // Send the reset instructions using the existing Brevo email helper.
                $sent = sendSystemEmail($profile['email'], trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? '')), 'Reset Your Zentrix Password', $html);
                if ($sent !== true) {
                    error_log('Zentrix password reset email failed: ' . $sent);
                    // Do not reveal delivery or account details on the public page.
                }
            } else {
                error_log('Zentrix password reset token could not be saved: ' . json_encode($saved['data'] ?? []));
            }
        } elseif (($lookup['code'] ?? 500) >= 400) {
            error_log('Zentrix password reset lookup failed: ' . json_encode($lookup['data'] ?? []));
        }
    }
}
?>
<!-- The form below collects the registered email and displays request feedback. -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Zentrix</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link rel="stylesheet" href="css/style.css?v=20261118-auth-premium">
    <meta name="theme-color" content="#101014">
</head>
<body class="auth-forgot-page">
<main class="auth-layout">
    <section class="auth-showcase">
        <div class="showcase-content">
            <div class="brand-logo"><i class="ph-fill ph-ticket"></i><span>Zentrix</span></div>
            <div class="showcase-text"><h1>A secure way back in.</h1><p>Reset your password and return to the campus moments that matter.</p></div>
        </div>
    </section>
    <section class="auth-form-wrapper">
        <div class="mobile-logo"><i class="ph-fill ph-ticket"></i><span>Zentrix</span></div>
        <div class="auth-form-container">
            <header class="form-header"><h2>Forgot Password?</h2><p>Enter the email address linked to your Zentrix account. We'll send you a reset link if an account exists.</p></header>
            <?php if ($error): ?><div class="alert-error" role="alert"><i class="ph ph-warning-circle"></i><span><?= htmlspecialchars($error) ?></span></div><?php endif; ?>
            <?php if ($message): ?><div class="auth-success" role="status"><i class="ph ph-check-circle"></i><span><?= htmlspecialchars($message) ?></span></div><?php endif; ?>
            <form method="POST" action="forgot-password.php">
                <div class="input-group">
                    <label for="email">Email Address</label>
                    <div class="input-wrapper"><i class="ph ph-envelope-simple input-icon"></i><input type="email" id="email" name="email" placeholder="Enter your email address" value="<?= htmlspecialchars($email) ?>" required autocomplete="email"></div>
                </div>
                <button type="submit" class="btn-primary"><span class="btn-text">Send Reset Link</span><i class="ph-bold ph-arrow-right"></i></button>
            </form>
            <p class="register-prompt"><a href="login.php">Back to sign in</a></p>
        </div>
        <footer class="auth-footer"><p>&copy; <?= date('Y') ?> Zentrix. All rights reserved.</p></footer>
    </section>
</main>
<script src="js/auth-enhancements.js"></script>
</body>
</html>
