<?php
require_once 'config/database.php';
require_once 'includes/auth.php';

if (function_exists('isLoggedIn') && isLoggedIn()) {
    redirectByRole($_SESSION['user_role']);
}

$error = '';

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
        $isVerified = $user['is_verified'] ?? false;
        if ($isVerified === false || $isVerified === 'false' || $isVerified === 0) {
            $error = "Please check your Gmail inbox and click the verification link before logging in.";
        } else {
            // Proceed with login session setup
            session_regenerate_id(true); // Prevent session fixation
            $_SESSION['user_id'] = $user['id'] ?? '';
            $_SESSION['user_email'] = $user['email'] ?? $email;
            $_SESSION['user_role'] = $user['role'] ?? 'user';
            $_SESSION['user_name'] = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
            
            redirectByRole($_SESSION['user_role']);
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Sign In - Eventora Platform</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Phosphor Icons (Modern SaaS Icons) -->
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/style.css">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0B1020">
</head>
<body>
    <main class="auth-layout">
        
        <!-- Left Section: Branding & Visuals -->
        <section class="auth-showcase">
            <div class="showcase-content">
                <div class="brand-logo">
                    <i class="ph-fill ph-ticket"></i>
                    <span>Eventora</span>
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
                <span>Eventora</span>
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
                            <input type="email" id="email" name="email" placeholder="name@company.com" required autocomplete="email">
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
                    <a href="https://tnvrgvcijqccqucwxqyr.supabase.co/auth/v1/authorize?provider=google&redirect_to=https://test-systeml.onrender.com/auth-callback.php" class="btn-social">
                        <img src="https://cdn.jsdelivr.net/gh/devicons/devicon/icons/google/google-original.svg" alt="Google Logo" class="social-icon">
                        Continue with Google
                    </a>
                </div>

                <p class="register-prompt">
                    Don't have an account? <a href="register.php">Create one</a>
                </p>
            </div>

            <footer class="auth-footer">
                <p>&copy; <?= date('Y') ?> Eventora. All rights reserved.</p>
                <div class="footer-links">
                    <a href="privacy.php">Privacy Policy</a>
                    <a href="terms.php">Terms of Service</a>
                </div>
            </footer>
        </section>
    </main>

    <script src="js/auth.js"></script>
    <script src="/js/pwa.js"></script>
</body>
</html>
