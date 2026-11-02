<?php
require_once 'config/database.php'; 
require_once 'includes/auth.php';
require_once 'includes/mail.php';   

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
        // Check if email exists
        $checkResponse = supabaseRequest('profiles?email=eq.' . urlencode($email), 'GET');
        $existingRows = isset($checkResponse['data']) ? $checkResponse['data'] : $checkResponse;
        
        if (!empty($existingRows)) {
            $error = "Email is already registered.";
        } else {
            // Generate a unique dynamic verification token
            $verificationToken = bin2hex(random_bytes(32));
            $hash = password_hash($password, PASSWORD_BCRYPT);
            
            $payload = [
                'email' => $email,
                'password_hash' => $hash,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'role' => $role,
                'verification_token' => $verificationToken,
                'is_verified' => false
            ];

            $insert = supabaseRequest('profiles', 'POST', $payload);
            $statusCode = $insert['code'] ?? 200;
            
            if ($statusCode === 201 || $statusCode === 200 || isset($insert['id']) || isset($insert[0]['id'])) {
                $success = "Registration successful! Please check your Gmail inbox to verify your account before logging in.";
                
                // Build dynamic verification link (Change yourdomain.com to your actual Render live URL)
                $verificationLink = "https://your-app.onrender.com/verify.php?token=" . $verificationToken;
                
                $subject = "Verify Your EMIS Account";
                $body = "
                    <h2>Hello " . htmlspecialchars($firstName) . ",</h2>
                    <p>Thank you for registering! Please click the button below to verify your email address:</p>
                    <p><a href='$verificationLink' style='background: #0d6efd; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Verify Email Address</a></p>
                    <p>Or paste this link into your browser:<br><a href='$verificationLink'>$verificationLink</a></p>
                ";
                
                sendSystemEmail($email, "$firstName $lastName", $subject, $body);
                
            } else {
                $error = "Registration failed. Please try again.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
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
                        <div class="mb-3"><label class="form-label">First Name</label><input type="text" name="first_name" class="form-control" required></div>
                        <div class="mb-3"><label class="form-label">Last Name</label><input type="text" name="last_name" class="form-control" required></div>
                        <div class="mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" required></div>
                        <div class="mb-3"><label class="form-label">Password</label><input type="password" name="password" class="form-control" required></div>
                        <button type="submit" class="btn btn-primary w-100">Register</button>
                    </form>
                    <div class="mt-3 text-center">Already have an account? <a href="login.php">Login here</a>.</div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>