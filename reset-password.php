// This page validates the emailed reset token and lets the account owner set a new password.
// It updates the stored password hash and clears the token so the link cannot be reused.
require_once 'config/database.php';

// Read the token from the email link (GET) or the submitted password form (POST).
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$tokenHash = $token !== '' ? hash('sha256', $token) : '';
$error = '';
$success = false;

// Find a matching token hash that has not expired. Invalid tokens return null.
function findValidResetToken($tokenHash) {
    if ($tokenHash === '') {
        return null;
    }

    $now = gmdate('Y-m-d\TH:i:s\Z');
    $result = supabaseServiceRequest(
        'profiles?select=id,email,password_reset_expires_at&password_reset_token_hash=eq.' . rawurlencode($tokenHash)
        . '&password_reset_expires_at=gt.' . rawurlencode($now),
        'GET'
    );

    $rows = $result['data'] ?? [];
    return (($result['code'] ?? 500) < 300 && is_array($rows) && !empty($rows[0])) ? $rows[0] : null;
}

// Check the token before displaying the new-password form.
$profile = findValidResetToken($tokenHash);

// Validate the new password only after the user submits the reset form.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!$profile) {
        $error = 'This password reset link is invalid, expired, or has already been used. Please request a new one.';
    } elseif (strlen($password) < 8) {
        $error = 'Your new password must be at least 8 characters long.';
    } elseif ($password !== $confirmPassword) {
        $error = 'The passwords do not match.';
    } else {
        // Hash the new password securely; never store the plain-text password.
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        // Update only the profile that still has this valid token, then clear token fields.
        $updated = supabaseServiceRequest(
            'profiles?password_reset_token_hash=eq.' . rawurlencode($tokenHash)
            . '&password_reset_expires_at=gt.' . rawurlencode($now),
            'PATCH',
            [
                'password_hash' => $passwordHash,
                'password_reset_token_hash' => null,
                'password_reset_expires_at' => null
            ]
        );

        $updatedRows = $updated['data'] ?? [];
        if (($updated['code'] ?? 500) >= 200 && ($updated['code'] ?? 500) < 300 && is_array($updatedRows) && count($updatedRows) === 1) {
            $success = true;
        } elseif (($updated['code'] ?? 500) >= 200 && ($updated['code'] ?? 500) < 300) {
            $error = 'This password reset link is invalid, expired, or has already been used. Please request a new one.';
        } else {
            error_log('Zentrix password reset update failed: ' . json_encode($updated['data'] ?? []));
            $error = 'We could not update your password right now. Please try again.';
        }
    }
}
?>
<!-- The page displays either the reset form, an error, or a success message. -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Zentrix</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link rel="stylesheet" href="css/style.css">
    <meta name="theme-color" content="#F8F6FB">
</head>
<body>
<main class="auth-layout">
    <section class="auth-showcase">
        <div class="showcase-content">
            <div class="brand-logo"><i class="ph-fill ph-ticket"></i><span>Zentrix</span></div>
            <div class="showcase-text"><h1>A fresh start, securely.</h1><p>Choose a new password to protect your Zentrix account.</p></div>
        </div>
    </section>
    <section class="auth-form-wrapper">
        <div class="mobile-logo"><i class="ph-fill ph-ticket"></i><span>Zentrix</span></div>
        <div class="auth-form-container">
            <?php if ($success): ?>
                <header class="form-header"><h2>Password Updated</h2><p>Your password has been changed successfully. You can now sign in using your new password.</p></header>
                <div class="auth-success" role="status"><i class="ph ph-check-circle"></i><span>Your password has been reset.</span></div>
                <p class="register-prompt"><a href="login.php">Back to sign in</a></p>
            <?php else: ?>
                <header class="form-header"><h2>Reset Password</h2><p>Enter a new password for your Zentrix account.</p></header>
                <?php if ($error): ?><div class="alert-error" role="alert"><i class="ph ph-warning-circle"></i><span><?= htmlspecialchars($error) ?></span></div><?php endif; ?>
                <?php if ($profile): ?>
                <form method="POST" action="reset-password.php" data-confirm-password="password" data-confirm-field="confirm_password">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="input-group"><label for="password">New Password</label><div class="input-wrapper"><i class="ph ph-lock-key input-icon"></i><input type="password" id="password" name="password" placeholder="At least 8 characters" minlength="8" required autocomplete="new-password"><button type="button" class="password-toggle" data-password-toggle="password" aria-label="Show password"><i class="ph ph-eye"></i></button></div></div>
                    <div class="input-group"><label for="confirm_password">Confirm New Password</label><div class="input-wrapper"><i class="ph ph-lock-key input-icon"></i><input type="password" id="confirm_password" name="confirm_password" placeholder="Enter your new password again" minlength="8" required autocomplete="new-password"><button type="button" class="password-toggle" data-password-toggle="confirm_password" aria-label="Show password"><i class="ph ph-eye"></i></button></div></div>
                    <button type="submit" class="btn-primary"><span class="btn-text">Update Password</span><i class="ph-bold ph-arrow-right"></i></button>
                </form>
                <?php else: ?>
                    <?php if (!$error): ?><div class="alert-error" role="alert"><i class="ph ph-warning-circle"></i><span>This password reset link is invalid, expired, or has already been used. Please request a new one.</span></div><?php endif; ?>
                    <p class="register-prompt"><a href="forgot-password.php">Request another reset link</a></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <footer class="auth-footer"><p>&copy; <?= date('Y') ?> Zentrix. All rights reserved.</p></footer>
    </section>
</main>
<script src="js/auth-enhancements.js"></script>
</body>
</html>
