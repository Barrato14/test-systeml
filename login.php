
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

    // Handle Supabase response
    $rows = [];

    if (is_array($response)) {
        if (isset($response['data']) && is_array($response['data'])) {
            $rows = $response['data'];
        } else {
            $rows = $response;
        }
    }

    $user = (!empty($rows) && isset($rows[0]) && is_array($rows[0]))
        ? $rows[0]
        : null;

    if ($user && isset($user['password_hash']) &&
        password_verify($password, $user['password_hash'])) {

        // Check email verification
        $isVerified = $user['is_verified'] ?? false;

        if ($isVerified === false || $isVerified === 'false' || $isVerified === 0) {

            $error = "Please check your Gmail inbox and click the verification link before logging in.";

        } else {

            // Create login session
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'] ?? '';
            $_SESSION['user_email'] = $user['email'] ?? $email;
            $_SESSION['user_role'] = $user['role'] ?? 'user';
            $_SESSION['user_name'] = trim(
                ($user['first_name'] ?? '') . ' ' .
                ($user['last_name'] ?? '')
            );

            redirectByRole($_SESSION['user_role']);
        }

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

    <title>Login - EMIS</title>

    <!-- Bootstrap -->
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">

    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#172554">

    <style>

        /* ==============================
           GENERAL
        ============================== */

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 25px 15px;
        }

        /* ==============================
           LOGIN CONTAINER
        ============================== */

        .login-container {
            width: 100%;
            max-width: 460px;
        }

        .login-card {
            background: #ffffff;
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 35px rgba(15, 23, 42, 0.08);
        }

        /* ==============================
           HEADER
        ============================== */

        .login-header {
            background: #172554;
            padding: 30px 25px;
            text-align: center;
            color: white;
        }

        .login-logo {
            width: 58px;
            height: 58px;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 14px;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0 auto 15px;
            font-size: 28px;
        }

        .login-header h3 {
            font-size: 25px;
            font-weight: 700;
            margin: 0 0 7px;
        }

        .login-header p {
            margin: 0;
            font-size: 13px;
            color: #cbd5e1;
        }

        /* ==============================
           FORM BODY
        ============================== */

        .login-body {
            padding: 30px;
        }

        .welcome-text {
            margin-bottom: 25px;
        }

        .welcome-text h5 {
            color: #172554;
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .welcome-text p {
            color: #64748b;
            font-size: 13px;
            margin: 0;
        }

        /* ==============================
           INPUT FIELDS
        ============================== */

        .form-label {
            color: #334155;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper > i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 17px;
            pointer-events: none;
        }

        .form-control {
            height: 47px;
            padding-left: 43px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            box-shadow: none !important;
        }

        .form-control:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12) !important;
        }

        .password-input {
            padding-right: 45px;
        }

        .toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 17px;
        }

        .toggle-password:hover {
            color: #172554;
        }

        /* ==============================
           LOGIN BUTTON
        ============================== */

        .login-btn {
            width: 100%;
            height: 47px;
            margin-top: 8px;
            background: #172554;
            border: none;
            border-radius: 8px;
            color: white;
            font-size: 15px;
            font-weight: 600;
            transition: 0.2s;
        }

        .login-btn:hover {
            background: #1e40af;
            color: white;
            transform: translateY(-1px);
        }

        /* ==============================
           DIVIDER
        ============================== */

        .divider {
            display: flex;
            align-items: center;
            gap: 15px;
            margin: 24px 0;
            color: #94a3b8;
            font-size: 12px;
        }

        .divider::before,
        .divider::after {
            content: "";
            flex: 1;
            height: 1px;
            background: #e2e8f0;
        }

        /* ==============================
           SOCIAL LOGIN
        ============================== */

        .social-btn {
            width: 100%;
            height: 45px;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            border-radius: 8px;
            background: white;
            font-size: 14px;
            font-weight: 500;
            transition: 0.2s;
            text-decoration: none;
            margin-bottom: 10px;
        }

        .google-btn {
            color: #334155;
            border: 1px solid #cbd5e1;
        }

        .google-btn i {
            color: #db4437;
            font-size: 17px;
        }

        .google-btn:hover {
            background: #f8fafc;
            border-color: #94a3b8;
            color: #172554;
        }

        .facebook-btn {
            color: #334155;
            border: 1px solid #cbd5e1;
        }

        .facebook-btn i {
            color: #1877f2;
            font-size: 17px;
        }

        .facebook-btn:hover {
            background: #f8fafc;
            border-color: #94a3b8;
            color: #172554;
        }

        /* ==============================
           REGISTER
        ============================== */

        .register-text {
            text-align: center;
            margin-top: 22px;
            color: #64748b;
            font-size: 13px;
        }

        .register-text a {
            color: #2563eb;
            font-weight: 600;
            text-decoration: none;
        }

        .register-text a:hover {
            text-decoration: underline;
        }

        /* ==============================
           ERROR MESSAGE
        ============================== */

        .alert-danger {
            font-size: 13px;
            border-radius: 8px;
            border: none;
            background: #fef2f2;
            color: #b91c1c;
            padding: 12px 15px;
        }

        /* ==============================
           FOOTER
        ============================== */

        .login-footer {
            text-align: center;
            margin-top: 20px;
            color: #94a3b8;
            font-size: 11px;
        }

        /* ==============================
           MOBILE RESPONSIVE
        ============================== */

        @media (max-width: 480px) {

            body {
                padding: 15px;
            }

            .login-body {
                padding: 24px 20px;
            }

            .login-header {
                padding: 25px 20px;
            }

            .login-header h3 {
                font-size: 22px;
            }
        }

    </style>
</head>

<body>

<div class="login-container">

    <div class="login-card">

        <!-- Header -->
        <div class="login-header">

            <div class="login-logo">
                <i class="bi bi-calendar-event"></i>
            </div>

            <h3>EMIS</h3>

            <p>Event Management and Information System</p>

        </div>

        <!-- Login Body -->
        <div class="login-body">

            <div class="welcome-text">
                <h5>Welcome Back!</h5>
                <p>Please enter your credentials to access your account.</p>
            </div>

            <!-- Error Message -->
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-circle me-2"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST">

                <!-- Email -->
                <div class="mb-3">

                    <label class="form-label">Email Address</label>

                    <div class="input-wrapper">

                        <i class="bi bi-envelope"></i>

                        <input
                            type="email"
                            name="email"
                            class="form-control"
                            placeholder="Enter your email"
                            autocomplete="email"
                            required
                        >

                    </div>

                </div>

                <!-- Password -->
                <div class="mb-3">

                    <label class="form-label">Password</label>

                    <div class="input-wrapper">

                        <i class="bi bi-lock"></i>

                        <input
                            type="password"
                            name="password"
                            id="password"
                            class="form-control password-input"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required
                        >

                        <button
                            type="button"
                            class="toggle-password"
                            onclick="togglePassword()"
                            aria-label="Show or hide password"
                        >
                            <i class="bi bi-eye" id="passwordIcon"></i>
                        </button>

                    </div>

                </div>

                <!-- Login Button -->
                <button type="submit" class="btn login-btn">
                    Login
                    <i class="bi bi-arrow-right ms-2"></i>
                </button>

            </form>

            <!-- Divider -->
            <div class="divider">
                OR CONTINUE WITH
            </div>

            <!-- Google Login -->
            <a
                href="https://tnvrgvcijqccqucwxqyr.supabase.co/auth/v1/authorize?provider=google&redirect_to=https://test-systeml.onrender.com/auth-callback.php"
                class="social-btn google-btn"
            >
                <i class="bi bi-google"></i>
                Continue with Google
            </a>

            <!-- Facebook Login -->
            <a
                href="https://tnvrgvcijqccqucwxqyr.supabase.co/auth/v1/authorize?provider=facebook&redirect_to=https://test-systeml.onrender.com/auth-callback.php"
                class="social-btn facebook-btn"
            >
                <i class="bi bi-facebook"></i>
                Continue with Facebook
            </a>

            <!-- Register -->
            <div class="register-text">
                Don't have an account?
                <a href="register.php">Create an account</a>
            </div>

        </div>

    </div>

    <!-- Footer -->
    <div class="login-footer">
        &copy; <?php echo date('Y'); ?> EMIS. All rights reserved.
    </div>

</div>

<!-- Show/Hide Password -->
<script>
function togglePassword() {

    const password = document.getElementById('password');
    const icon = document.getElementById('passwordIcon');

    if (password.type === 'password') {
        password.type = 'text';
        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');
    } else {
        password.type = 'password';
        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
    }

}
</script>

<script src="/js/pwa.js"></script>

</body>
</html>
