<?php
require_once 'config/database.php'; 
require_once 'includes/auth.php';
require_once 'includes/mail.php';   // Loads the SMTP mailer

if (function_exists('isLoggedIn') && isLoggedIn()) {
    redirectByRole($_SESSION['user_role']);
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name']);
    $lastName = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = 'Participant'; 

    if (empty($firstName) || empty($lastName) || empty($email) || empty($password)) {
        $error = "All fields are required.";
    } else {
        // 1. Check if email already exists
        $checkResponse = supabaseRequest('profiles?email=eq.' . urlencode($email), 'GET');
        
        $existingRows = [];
        if (is_array($checkResponse)) {
            if (isset($checkResponse['data']) && is_array($checkResponse['data'])) {
                $existingRows = $checkResponse['data'];
            } else {
                $existingRows = $checkResponse;
            }
        }
        
        if (!empty($existingRows)) {
            $error = "Email is already registered.";
        } else {
            // 2. Hash password and insert user
            $hash = password_hash($password, PASSWORD_BCRYPT);
            
            $payload = [
                'email' => $email,
                'password_hash' => $hash,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'role' => $role
            ];

            $insert = supabaseRequest('profiles', 'POST', $payload);
            $statusCode = $insert['code'] ?? 200;
            
            if ($statusCode === 201 || $statusCode === 200 || isset($insert['id']) || isset($insert[0]['id'])) {
                $success = "Registration successful! A welcome email has been sent to your inbox. You can now <a href='login.php'>login</a>.";
                
                // 3. Trigger the SMTP Welcome Email
                $subject = "Welcome to the Event Management Information System!";
                $body = "
                    <h2>Hello " . htmlspecialchars($firstName) . "!,</h2>
                    <p>Thank you for registering an account with EMIS.</p>
                    <p>You can now log in using your email (<strong>" . htmlspecialchars($email) . "</strong>) to explore upcoming events and manage your QR passes.</p>
                    <br>
                    <p>Best regards,<br><strong>EMIS Team</strong></p>
                ";
                
                // Sends the email using your SMTP configuration
                sendSystemEmail($email, "$firstName $lastName", $subject, $body);
                
            } else {
                $error = "Registration failed. Please try again. (" . json_encode($insert['data'] ?? $insert) . ")";
            }
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
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white"><h4>Create Account</h4></div>
                <div class="card-body">
                    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
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
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Register</button>
                    </form>
                    <div class="text-center my-3 text-muted">OR</div>
                    <div class="d-grid gap-2">
                        <!-- Replace YOUR_SUPABASE_URL with your actual Supabase project URL and YOUR_DOMAIN with your Render domain -->
                        <a href="https://YOUR_SUPABASE_URL.supabase.co/auth/v1/authorize?provider=google&redirect_to=https://YOUR_DOMAIN/auth-callback.php" class="btn btn-outline-danger">
                            <i class="bi bi-google"></i> Continue with Google
                        </a>
                        <a href="https://YOUR_SUPABASE_URL.supabase.co/auth/v1/authorize?provider=facebook&redirect_to=https://YOUR_DOMAIN/auth-callback.php" class="btn btn-outline-primary">
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