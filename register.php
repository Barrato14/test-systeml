<?php
require_once 'config/database.php';
require_once 'includes/mail.php';
require_once 'includes/auth.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name']);
    $lastName = trim($_POST['last_name']);
    $email = strtolower(trim($_POST['email'])); // Force lowercase to avoid duplicate casing issues
    $password = $_POST['password'];
    $course = $_POST['course'] ?? '';
    $section = $_POST['section'] ?? '';
    $role = 'Participant'; // Matches Supabase user_role enum

    if (empty($course) || empty($section)) {
        $error = "Please select both your course and year & section.";
    } else {
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

            // Insert user profile into Supabase including course and section
            $payload = [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'password_hash' => $passwordHash,
                'course' => $course,
                'section' => $section,
                'role' => $role,
                'is_verified' => false,
                'verification_token' => $verificationToken
            ];

            $insertResponse = supabaseRequest('profiles', 'POST', $payload);

            // Check if Supabase returned an error (like a 409 duplicate key)
            $errorCode = $insertResponse['code'] ?? ($insertResponse['data']['code'] ?? null);
            
            if ($errorCode == '23505' || $errorCode == 409) {
                $error = "An account with this email address already exists.";
            } elseif (isset($insertResponse['message']) || (isset($insertResponse['code']) && $insertResponse['code'] >= 400)) {
                $error = "Database Error: " . ($insertResponse['message'] ?? ($insertResponse['data']['message'] ?? 'Unknown database rejection.'));
            } else {
                // Send verification email via Brevo API
                $verificationLink = "https://test-systeml.onrender.com/verify.php?token=" . $verificationToken;
                $subject = "Verify Your Account - EMIS";
                $htmlBody = "<p>Hi " . htmlspecialchars($firstName) . ",</p>"
                          . "<p>Thank you for registering. Please click the link below to verify your email address:</p>"
                          . "<p><a href='" . $verificationLink . "'>Verify My Account</a></p>"
                          . "<p>If you did not request this, please ignore this email.</p>";

                $emailResult = sendSystemEmail($email, $firstName . ' ' . $lastName, $subject, $htmlBody);

                if ($emailResult === true) {
                    $success = "Registration successful! Please check your inbox to verify your account.";
                } else {
                    $error = "Registration saved, but mail failed: " . $emailResult;
                }
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
<title>Create Account - Eventora</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://unpkg.com/@phosphor-icons/web"></script>
<link rel="stylesheet" href="css/style.css">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#0B1020">
</head>
<body class="auth-register-page">
<main class="auth-layout">
<section class="auth-showcase">
<div class="showcase-content">
<div class="brand-logo"><i class="ph-fill ph-ticket"></i><span>Eventora</span></div>
<div class="showcase-text"><h1>Be Part of Every Great Event.</h1><p>Create your account to discover campus activities, register for events, and keep everything in one place.</p></div>
<div class="visual-decorations">
<div class="floating-card card-1"><div class="card-header"><i class="ph-fill ph-calendar-check text-violet"></i><span>Campus Events</span></div><div class="card-stats"><div class="stat-bar w-75"></div><div class="stat-bar w-50"></div></div></div>
<div class="floating-card card-2"><div class="card-header"><i class="ph-fill ph-users text-light-purple"></i><span>Your Community</span></div><div class="card-stats"><h3>Get involved</h3><span class="status-badge">Your next experience starts here</span></div></div>
</div>
</div>
</section>
<section class="auth-form-wrapper auth-register-wrapper">
<div class="mobile-logo"><i class="ph-fill ph-ticket"></i><span>Eventora</span></div>
<div class="auth-form-container auth-register-container">
<header class="form-header"><h2>Create your account</h2><p>Enter your details to join the campus community.</p></header>
<?php if ($error): ?><div class="alert-error" role="alert"><i class="ph ph-warning-circle"></i><span><?= htmlspecialchars($error) ?></span></div><?php endif; ?>
<?php if ($success): ?><div class="auth-success" role="status"><i class="ph ph-check-circle"></i><span><?= htmlspecialchars($success) ?></span></div><?php endif; ?>
<form id="registerForm" method="POST" action="register.php">
<div class="auth-register-grid">
<div class="input-group"><label for="first_name">First Name</label><div class="input-wrapper"><i class="ph ph-user input-icon"></i><input type="text" id="first_name" name="first_name" placeholder="Your first name" required autocomplete="given-name" value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>"></div></div>
<div class="input-group"><label for="last_name">Last Name</label><div class="input-wrapper"><i class="ph ph-user input-icon"></i><input type="text" id="last_name" name="last_name" placeholder="Your last name" required autocomplete="family-name" value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>"></div></div>
<div class="input-group"><label for="course">Course</label><div class="input-wrapper"><i class="ph ph-graduation-cap input-icon"></i><select id="course" name="course" required><option value="">Select your course</option><option value="ACT" <?= (($_POST['course'] ?? '') === 'ACT') ? 'selected' : '' ?>>ACT (Associate in Computer Technology)</option><option value="HM" <?= (($_POST['course'] ?? '') === 'HM') ? 'selected' : '' ?>>HM (Hotel Management)</option><option value="CT" <?= (($_POST['course'] ?? '') === 'CT') ? 'selected' : '' ?>>CT</option></select></div></div>
<div class="input-group"><label for="section">Year &amp; Section</label><div class="input-wrapper"><i class="ph ph-users-three input-icon"></i><select id="section" name="section" required><option value="">Select your section</option><optgroup label="Year 1"><option value="1A" <?= (($_POST['section'] ?? '') === '1A') ? 'selected' : '' ?>>1A</option><option value="1B" <?= (($_POST['section'] ?? '') === '1B') ? 'selected' : '' ?>>1B</option><option value="1C" <?= (($_POST['section'] ?? '') === '1C') ? 'selected' : '' ?>>1C</option></optgroup><optgroup label="Year 2"><option value="2A" <?= (($_POST['section'] ?? '') === '2A') ? 'selected' : '' ?>>2A</option><option value="2B" <?= (($_POST['section'] ?? '') === '2B') ? 'selected' : '' ?>>2B</option><option value="2C" <?= (($_POST['section'] ?? '') === '2C') ? 'selected' : '' ?>>2C</option></optgroup></select></div></div>
<div class="input-group auth-register-full"><label for="email">Email Address</label><div class="input-wrapper"><i class="ph ph-envelope-simple input-icon"></i><input type="email" id="email" name="email" placeholder="name@example.com" required autocomplete="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"></div></div>
<div class="input-group auth-register-full"><label for="password">Password</label><div class="input-wrapper"><i class="ph ph-lock-key input-icon"></i><input type="password" id="password" name="password" placeholder="Create a password" required autocomplete="new-password"></div></div>
</div>
<button type="submit" class="btn-primary auth-register-submit"><span class="btn-text">Create Account</span><i class="ph-bold ph-arrow-right"></i></button>
</form>
<div class="divider"><span>OR</span></div>
<div class="social-login">
<a href="https://tnvrgvcijqccqucwxqyr.supabase.co/auth/v1/authorize?provider=google&redirect_to=https://test-systeml.onrender.com/auth-callback.php" class="btn-social"><img src="https://cdn.jsdelivr.net/gh/devicons/devicon/icons/google/google-original.svg" alt="" class="social-icon">Continue with Google</a>
</div>
<p class="register-prompt">Already have an account? <a href="login.php">Sign in</a></p>
</div>
<footer class="auth-footer"><p>&copy; <?= date('Y') ?> Eventora. All rights reserved.</p><div class="footer-links"><a href="privacy.php">Privacy Policy</a><a href="terms.php">Terms of Service</a></div></footer>
</section>
</main>
<script src="/js/pwa.js"></script>
</body>
</html>
