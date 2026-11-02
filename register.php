<?php
require_once 'config/database.php';
require_once 'includes/mail.php';
require_once 'includes/auth.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name']);
    $lastName = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = 'user'; // default role

    // Check if email already exists
    $existing = supabaseRequest('profiles?email=eq.' . urlencode($email), 'GET');
    $rows = [];
    if (is_array($existing)) {
        $rows = isset($existing['data']) ? $existing['data'] : $existing;
    }

    if (!empty($rows)) {
        $error = "An account with this email already exists.";
    } else {
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $verificationToken = bin2hex(random_bytes(32));

        // Insert user profile into Supabase
        $payload = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password_hash' => $passwordHash,
            'role' => $role,
            'is_verified' => false,
            'verification_token' => $verificationToken
        ];

        $insertResponse = supabaseRequest('profiles', 'POST', $payload);

        if ($insertResponse) {
            // Send verification email via Resend API
            $verificationLink = "https://test-systeml.onrender.com/verify.php?token=" . $verificationToken;
            $subject = "Verify Your Account - EMIS";
            $htmlBody = "<p>Hi " . htmlspecialchars($firstName) . ",</p>"
                      . "<p>Thank you for registering. Please click the link below to verify your email address:</p>"
                      . "<p><a href='" . $verificationLink . "'>Verify My Account</a></p>"
                      . "<p>If you did not request this, please ignore this email.</p>";

            $emailResult = sendSystemEmail($email, $firstName . ' ' . $lastName, $subject, $htmlBody);

            if ($emailResult === true) {
                $success = "Registration successful! Please check your Gmail inbox to verify your account.";
            } else {
                $error = "Registration saved, but mail failed: " . $emailResult;
            }
        } else {
            $error = "Failed to create user profile in the database.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - EMIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>
<body class="bg-light">
<div class="container mt-5 mb-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-dark text-white"><h4>Create an Account</h4></div>
                <div class="card-body">
                    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
                    
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">First Name</label>
                            <input type="text" name="first_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Last Name</label>
                            <input type="text" name="last_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-dark w-100">Register</button>
                    </form>

                    <div class="text-center my-3 text-muted">OR</div>
                    <div class="d-grid gap-2">
                        <a href="https://tnvrgvcijqccqucwxqyr.supabase.co/auth/v1/authorize?provider=google&redirect_to=https://test-systeml.onrender.com/auth-callback.php" class="btn btn-outline-danger">
                            <i class="bi bi-google"></i> Continue with Google
                        </a>
                        <a href="https://tnvrgvcijqccqucwxqyr.supabase.co/auth/v1/authorize?provider=facebook&redirect_to=https://test-systeml.onrender.com/auth-callback.php" class="btn btn-outline-primary">
                            <i class="bi bi-facebook"></i> Continue with Facebook
                        </a>
                    </div>

                    <div class="mt-3 text-center">
                        Already have an account? <a href="login.php">Login here</a>.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>