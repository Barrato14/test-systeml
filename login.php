<?php
require_once 'config/database.php';
require_once 'includes/auth.php';

if (function_exists('isLoggedIn') && isLoggedIn()) {
    redirectByRole($_SESSION['user_role']);
}

$error = (string)($_SESSION['social_login_error'] ?? '');
unset($_SESSION['social_login_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    // Query user profile by email using Supabase REST API
    $response = supabaseRequest('profiles?email=eq.' . urlencode($email), 'GET');
    
    // Safely handle both wrapped and flat response structures from Supabase
    $rows = [];
    if (is_array($response)) {
        if (isset($response['data']) && is_array($response['data'])) {
            $rows = $response['data'];
        } else {
            $rows = $response; // Direct array return case
        }
    }

    $user = (!empty($rows) && isset($rows[0]) && is_array($rows[0])) ? $rows[0] : null;

    if ($user && isset($user['password_hash']) && password_verify($password, $user['password_hash'])) {
        
        // --- VERIFICATION CHECK ADDED HERE ---
        $isActive = $user['is_active'] ?? true;

        if ($isActive === false || $isActive === 'false' || $isActive === 0 || $isActive === '0') {
            $error = "Your account has been deactivated. Please contact the administrator.";
        } else {
            $isVerified = $user['is_verified'] ?? false;
        
            if ($isVerified === false || $isVerified === 'false' || $isVerified === 0) {
                $error = "Please check your Gmail inbox and click the verification link before logging in.";
            } else {
                // Keep your existing session setup and redirect code here.
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'] ?? '';
                $_SESSION['user_email'] = $user['email'] ?? $email;
                $_SESSION['user_role'] = $user['role'] ?? 'user';
                $_SESSION['user_name'] = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        
                redirectByRole($_SESSION['user_role']);
            }
        }
        // -------------------------------------

    } else {
        $error = "Invalid email or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - Zentrix Platform</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Phosphor Icons (Modern SaaS Icons) -->
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/style.css?v=20261118-auth-premium">
    <link rel="icon" type="image/svg+xml" href="/pwa-icons/zentrix-icon.svg">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#101014">
</head>
<body class="auth-login-page">
    <main class="auth-layout">
        
        <!-- Left Section: Branding & Visuals -->
        <section class="auth-showcase">
            <div class="showcase-content">
                <div class="brand-logo">
                    <i class="ph-fill ph-ticket"></i>
                    <span>Zentrix</span>
                </div>
                
                <div class="showcase-text">
                    <h1>Every Great Event Starts Here.</h1>
                    <p>Manage, organize, and bring your events to life with one powerful platform.</p>
                </div>

                <!-- Abstract Decorative Event Cards -->
                <div class="visual-decorations">
                    <div class="floating-card card-1">
                        <div class="card-header">
                            <i class="ph-fill ph-calendar-check text-violet"></i>
                            <span>Global Tech Summit</span>
                        </div>
                        <div class="card-stats">
                            <div class="stat-bar w-75"></div>
                            <div class="stat-bar w-50"></div>
                        </div>
                    </div>
                    <div class="floating-card card-2">
                        <div class="card-header">
                            <i class="ph-fill ph-users text-light-purple"></i>
                            <span>Attendees</span>
                        </div>
                        <div class="card-stats">
                            <h3>1,204</h3>
                            <span class="status-badge">+12% this week</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Right Section: Sign-In Form -->
        <section class="auth-form-wrapper">
            <!-- Mobile Logo (Visible only on mobile) -->
            <div class="mobile-logo">
                <i class="ph-fill ph-ticket"></i>
                <span>Zentrix</span>
            </div>

            <div class="auth-form-container">
                <header class="form-header">
                    <h2>Welcome Back</h2>
                    <p>Sign in to continue managing your events.</p>
                </header>

                <?php if ($error): ?>
                    <div class="alert-error" role="alert">
                        <i class="ph ph-warning-circle"></i>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <form id="loginForm" method="POST" action="login.php" novalidate>
                    <!-- Email Field -->
                    <div class="input-group">
                        <label for="email">Email Address</label>
                        <div class="input-wrapper">
                            <i class="ph ph-envelope-simple input-icon"></i>
                            <input type="email" id="email" name="email" placeholder="Enter your email address" required autocomplete="email">
                        </div>
                        <span class="error-text" id="emailError">Please enter a valid email address.</span>
                    </div>

                    <!-- Password Field -->
                    <div class="input-group">
                        <label for="password">Password</label>
                        <div class="input-wrapper">
                            <i class="ph ph-lock-key input-icon"></i>
                            <input type="password" id="password" name="password" placeholder="••••••••" required autocomplete="current-password">
                            <button type="button" class="password-toggle" id="togglePassword" aria-label="Toggle password visibility">
                                <i class="ph ph-eye"></i>
                            </button>
                        </div>
                        <span class="error-text" id="passwordError">Password is required.</span>
                    </div>

                    <!-- Form Options -->
                    <div class="form-options">
                        <label class="checkbox-container">
                            <input type="checkbox" name="remember">
                            <span class="checkmark"></span>
                            <span class="checkbox-label">Keep me signed in</span>
                        </label>
                        <a href="forgot-password.php" class="forgot-link">Forgot password?</a>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit" class="btn-primary" id="submitBtn">
                        <span class="btn-text">Sign In</span>
                        <i class="ph-bold ph-spinner-gap spinner"></i>
                    </button>
                </form>

                <!-- Alternative Authentication -->
                <div class="divider">
                    <span>OR</span>
                </div>

                <div class="social-login">
                    <a href="https://tnvrgvcijqccqucwxqyr.supabase.co/auth/v1/authorize?provider=google&redirect_to=https://test-systeml.onrender.com/auth-callback.php%3Fsource%3Dlogin" class="btn-social">
                        <img src="https://cdn.jsdelivr.net/gh/devicons/devicon/icons/google/google-original.svg" alt="Google Logo" class="social-icon">
                        Continue with Google
                    </a>
                </div>

                <p class="register-prompt">
                    Don't have an account? <a href="register.php">Create one</a>
                </p>
            </div>

            <footer class="auth-footer">
                <p>&copy; <?= date('Y') ?> Zentrix. All rights reserved.</p>
                <div class="footer-links">
                    <button type="button" class="auth-legal-link" data-legal-open="privacy">Privacy Policy</button>
                    <button type="button" class="auth-legal-link" data-legal-open="terms">Terms of Service</button>
                </div>
            </footer>
        </section>
    </main>


    <div class="auth-legal-dialog" data-legal-dialog="privacy" hidden>
        <div class="auth-legal-backdrop" data-legal-close></div>
        <section class="auth-legal-panel" role="dialog" aria-modal="true" aria-labelledby="privacyPolicyTitle" tabindex="-1">
            <header class="auth-legal-header"><div><span class="auth-legal-eyebrow">EVENTORA · Zentrix</span><h2 id="privacyPolicyTitle">Privacy Policy</h2><p>How we collect, use, and protect your information.</p></div><button type="button" class="auth-legal-close" data-legal-close aria-label="Close Privacy Policy"><i class="ph ph-x"></i></button></header>
            <div class="auth-legal-content">
                <p class="auth-legal-updated">Last updated: November 15, 2026</p>
                <p>Zentrix is the user-facing platform of the Event Management and Information System (Zentrix). This policy explains how information is handled when you use the system to access campus events and attendance services.</p>
                <h3>1. Information We Collect</h3><p>Depending on the features you use, Zentrix may process:</p>
                <ul><li><strong>Account information:</strong> your name, email address, password hash, course, section, account role, and account verification or status information.</li><li><strong>Event information:</strong> events you browse, register for, or attend, including related registration and attendance records.</li><li><strong>Attendance information:</strong> check-in and time-out timestamps, attendance status, and the event associated with the record.</li><li><strong>Camera and face-verification information:</strong> images submitted during attendance verification and a mathematical face descriptor created when you register your face. The descriptor is used to compare your identity during attendance checks.</li><li><strong>Location information:</strong> GPS coordinates submitted during check-in or time-out when you use location-enabled attendance features.</li><li><strong>Technical and communication information:</strong> session information needed to keep you signed in, and email addresses and messages used for account verification and system notifications.</li></ul>
                <h3>2. How We Use Information</h3><p>Information is used to create and manage accounts, verify email addresses, provide event registration, verify attendance, maintain event and attendance records, send relevant notifications, and protect the system against unauthorized use.</p>
                <h3>3. Face Verification, Photos, and Location</h3><p>Camera access is used when you choose face registration or camera-based attendance verification. Face registration creates a face descriptor for matching; attendance submissions may include a camera image. Location coordinates may be recorded with an attendance submission. These features should only be used for their stated Zentrix purposes and in accordance with applicable school policies and laws.</p>
                <h3>4. Storage and Service Providers</h3><p>Zentrix uses Supabase services for application data and related storage. The system also uses an email delivery provider to send verification and notification messages, and a face-verification service to process face images and descriptors. Information may be processed by these providers to operate the features you request.</p>
                <h3>5. Access, Security, and Retention</h3><p>Access to records is controlled through the system's account and role checks. Reasonable technical and organizational measures are used to help protect information. No online service can guarantee absolute security. Records may be retained for as long as needed for event administration, attendance documentation, security, and legitimate institutional requirements, subject to applicable retention rules.</p>
                <h3>6. Your Choices</h3><p>You can choose whether to use optional camera and location-enabled features, although attendance functions that require them may not work without the relevant permission or submission. Keep your login credentials private and contact your school's Zentrix administrator or designated data protection contact to request access, correction, or clarification about your information.</p>
                <h3>7. Changes to This Policy</h3><p>This policy may be updated when Zentrix features or information practices change. The latest version will be made available through the login screen.</p>
                <h3>8. Contact</h3><p>For questions or requests concerning this policy or your information, contact your school's Zentrix administrator or designated data protection contact.</p>
            </div>
            <footer class="auth-legal-footer"><span>Zentrix · Zentrix</span><button type="button" class="auth-legal-done" data-legal-close>Close</button></footer>
        </section>
    </div>
    <div class="auth-legal-dialog" data-legal-dialog="terms" hidden>
        <div class="auth-legal-backdrop" data-legal-close></div>
        <section class="auth-legal-panel" role="dialog" aria-modal="true" aria-labelledby="termsOfServiceTitle" tabindex="-1">
            <header class="auth-legal-header"><div><span class="auth-legal-eyebrow">EVENTORA · Zentrix</span><h2 id="termsOfServiceTitle">Terms of Service</h2><p>Rules for using the campus event management system.</p></div><button type="button" class="auth-legal-close" data-legal-close aria-label="Close Terms of Service"><i class="ph ph-x"></i></button></header>
            <div class="auth-legal-content">
                <p class="auth-legal-updated">Effective date: November 15, 2026</p><p>By accessing or using Zentrix (Zentrix), you agree to these terms. If you do not agree, do not use the system.</p>
                <h3>1. Purpose of the System</h3><p>Zentrix supports campus event management, participant registration, event information, notifications, and attendance recording for authorized users.</p>
                <h3>2. Account Responsibilities</h3><ul><li>Provide accurate and current information when creating or using an account.</li><li>Keep your password and sign-in methods secure.</li><li>Do not share your account or allow another person to record attendance under your identity.</li><li>Notify the school's Zentrix administrator if you believe your account has been accessed without permission.</li></ul>
                <h3>3. Acceptable Use</h3><p>Use Zentrix only for legitimate school and event-related purposes. Do not attempt to access another user's account or records, interfere with system operations, upload malicious content, submit false information, or misuse event, attendance, camera, face-verification, or location features.</p>
                <h3>4. Event Registration and Attendance</h3><p>Event availability, eligibility, schedules, and participation requirements may be determined by the event organizer or school. Registering for an event does not guarantee admission unless the event details say otherwise. Attendance records must reflect your own participation. Do not falsify, manipulate, or submit another person's face image or location information.</p>
                <h3>5. Camera, Face Verification, and Location Features</h3><p>Some attendance workflows require camera access, face verification, and/or location coordinates. You are responsible for granting the relevant device permissions when using those features. Attempts to bypass or deceive attendance verification are prohibited. If verification fails or your device cannot provide the required information, contact the event organizer or Zentrix administrator for assistance.</p>
                <h3>6. Notifications and System Availability</h3><p>Zentrix may send account verification messages, event announcements, reminders, and service notifications. Delivery may depend on external providers. The system may be updated, temporarily unavailable, or changed to maintain and improve its services.</p>
                <h3>7. Privacy</h3><p>Your use of Zentrix is also subject to the Privacy Policy, which explains the information processed by the system, including account, event, attendance, face-verification, and location information.</p>
                <h3>8. Suspension or Restriction</h3><p>Access may be restricted or an account may be deactivated by authorized school personnel when required for security, policy enforcement, or legitimate administrative reasons.</p>
                <h3>9. Disclaimer</h3><p>Zentrix is provided to support school event operations. While reasonable efforts are made to keep information accurate and the service available, uninterrupted operation and error-free records cannot be guaranteed. Report suspected errors to the event organizer or Zentrix administrator.</p>
                <h3>10. Changes to These Terms</h3><p>These terms may be revised as the system or school requirements change. Continued use after updated terms are published means you acknowledge the revised terms.</p>
                <h3>11. Contact</h3><p>For questions about these terms or use of the system, contact your school's Zentrix administrator or designated system contact.</p>
            </div>
            <footer class="auth-legal-footer"><span>Zentrix · Zentrix</span><button type="button" class="auth-legal-done" data-legal-close>Close</button></footer>
        </section>
    </div>

    <script src="js/auth.js"></script>
    <script src="js/legal-modals.js?v=2"></script>
    <script src="/js/pwa.js"></script>
</body>
</html>
