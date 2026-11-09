
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
            $rows = $response;
        }
    }

    $user = (!empty($rows) && isset($rows[0]) && is_array($rows[0])) ? $rows[0] : null;

    if ($user && isset($user['password_hash']) && password_verify($password, $user['password_hash'])) {

        // Verification check
        $isVerified = $user['is_verified'] ?? false;
        if ($isVerified === false || $isVerified === 'false' || $isVerified === 0) {
            $error = "Please check your Gmail inbox and click the verification link before logging in.";
        } else {
            // Proceed with login session setup
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'] ?? '';
            $_SESSION['user_email'] = $user['email'] ?? $email;
            $_SESSION['user_role'] = $user['role'] ?? 'user';
            $_SESSION['user_name'] = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

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

    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#101b35">

    <style>
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            min-height: 100vh;
            background: #f4f6fb;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 35px;
            color: #17223b;
        }

        .login-container {
            width: 100%;
            max-width: 1100px;
            min-height: 650px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            background: #ffffff;
            border-radius: 22px;
            overflow: hidden;
            box-shadow: 0 20px 70px rgba(20, 35, 70, 0.10);
        }

        /* LEFT PANEL */

        .brand-panel {
            position: relative;
            background: linear-gradient(145deg, #101b35, #172d55 65%, #214c83);
            padding: 55px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
            color: #ffffff;
        }

        .brand-panel::before {
            content: "";
            position: absolute;
            width: 380px;
            height: 380px;
            border: 1px solid rgba(255,255,255,0.10);
            border-radius: 50%;
            top: 180px;
            right: -170px;
        }

        .brand-panel::after {
            content: "";
            position: absolute;
            width: 280px;
            height: 280px;
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 50%;
            bottom: -120px;
            left: -100px;
        }

        .brand-content,
        .brand-footer,
        .brand-logo {
            position: relative;
            z-index: 2;
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 13px;
        }

        .logo-icon {
            width: 48px;
            height: 48px;
            background: rgba(255,255,255,0.13);
            border: 1px solid rgba(255,255,255,0.18);
            border-radius: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logo-icon svg {
            width: 27px;
            height: 27px;
        }

        .logo-text {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 23px;
            font-weight: 800;
            letter-spacing: 1px;
        }

        .brand-content {
            margin-top: 55px;
            margin-bottom: 55px;
        }

        .brand-tag {
            display: inline-block;
            padding: 8px 14px;
            border: 1px solid rgba(255,255,255,0.20);
            background: rgba(255,255,255,0.08);
            border-radius: 30px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1.5px;
            color: #a9c9ff;
            margin-bottom: 27px;
        }

        .brand-content h1 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: clamp(34px, 4vw, 47px);
            line-height: 1.2;
            font-weight: 800;
            margin-bottom: 22px;
        }

        .brand-content h1 span {
            color: #71aaff;
        }

        .brand-content p {
            color: #b8c6dc;
            font-size: 14px;
            line-height: 1.9;
            max-width: 380px;
        }

        .brand-features {
            margin-top: 38px;
            display: flex;
            flex-direction: column;
            gap: 17px;
        }

        .feature {
            display: flex;
            align-items: center;
            gap: 13px;
            font-size: 13px;
            color: #d9e5f7;
        }

        .feature-check {
            width: 23px;
            height: 23px;
            border-radius: 50%;
            background: rgba(86, 157, 255, 0.18);
            color: #80b7ff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            flex-shrink: 0;
        }

        .brand-footer {
            color: #899cb9;
            font-size: 11px;
            letter-spacing: 0.3px;
        }

        /* RIGHT PANEL */

        .form-panel {
            padding: 65px 60px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .form-header {
            margin-bottom: 35px;
        }

        .form-header .welcome {
            color: #477ed0;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1.8px;
            text-transform: uppercase;
            margin-bottom: 12px;
        }

        .form-header h2 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: #17223b;
            font-size: 31px;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .form-header p {
            color: #8490a4;
            font-size: 13px;
        }

        /* ERROR MESSAGE */

        .error-message {
            padding: 14px 16px;
            background: #fff1f1;
            border: 1px solid #ffd5d5;
            border-left: 4px solid #e54848;
            border-radius: 9px;
            color: #bd3030;
            font-size: 12px;
            line-height: 1.6;
            margin-bottom: 22px;
        }

        /* FORM */

        .form-group {
            margin-bottom: 21px;
        }

        .form-group label {
            display: block;
            color: #34415a;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 9px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper input {
            width: 100%;
            height: 51px;
            padding: 0 16px 0 45px;
            border: 1px solid #e1e6ef;
            border-radius: 9px;
            outline: none;
            font-family: inherit;
            font-size: 13px;
            color: #17223b;
            background: #fbfcfe;
            transition: all 0.2s ease;
        }

        .input-wrapper input::placeholder {
            color: #a8b1c0;
        }

        .input-wrapper input:focus {
            border-color: #4d8be9;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(77, 139, 233, 0.10);
        }

        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a0b3;
            pointer-events: none;
        }

        .input-icon svg {
            width: 17px;
            height: 17px;
        }

        .password-input {
            padding-right: 48px !important;
        }

        .toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #8c98aa;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .toggle-password:hover {
            color: #477ed0;
        }

        .toggle-password svg {
            width: 19px;
            height: 19px;
        }

        /* LOGIN BUTTON */

        .login-button {
            width: 100%;
            height: 51px;
            border: none;
            border-radius: 9px;
            background: #2563b9;
            color: #ffffff;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 5px;
            transition: all 0.25s ease;
            box-shadow: 0 5px 14px rgba(37, 99, 185, 0.18);
        }

        .login-button:hover {
            background: #194f9b;
            transform: translateY(-1px);
            box-shadow: 0 7px 18px rgba(37, 99, 185, 0.25);
        }

        .login-button:active {
            transform: translateY(0);
        }

        /* DIVIDER */

        .divider {
            display: flex;
            align-items: center;
            gap: 15px;
            margin: 27px 0;
            color: #a4adbc;
            font-size: 11px;
            font-weight: 600;
        }

        .divider::before,
        .divider::after {
            content: "";
            flex: 1;
            height: 1px;
            background: #e9edf3;
        }

        /* SOCIAL BUTTONS */

        .social-buttons {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .social-button {
            height: 48px;
            border: 1px solid #e1e6ef;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 11px;
            text-decoration: none;
            color: #34415a;
            background: #ffffff;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .social-button:hover {
            background: #f7f9fc;
            border-color: #b8c9e2;
            transform: translateY(-1px);
        }

        .social-button svg {
            width: 18px;
            height: 18px;
        }

        /* REGISTER */

        .register-text {
            text-align: center;
            margin-top: 30px;
            color: #8490a4;
            font-size: 12px;
        }

        .register-text a {
            color: #2563b9;
            font-weight: 700;
            text-decoration: none;
        }

        .register-text a:hover {
            text-decoration: underline;
        }

        .security-note {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 7px;
            color: #a0aaba;
            font-size: 10px;
            margin-top: 27px;
        }

        .security-note svg {
            width: 13px;
            height: 13px;
        }

        /* RESPONSIVE */

        @media (max-width: 850px) {
            body {
                padding: 20px;
            }

            .login-container {
                max-width: 520px;
                grid-template-columns: 1fr;
            }

            .brand-panel {
                padding: 30px 35px;
            }

            .brand-content {
                margin: 35px 0;
            }

            .brand-content h1 {
                font-size: 34px;
            }

            .brand-content p {
                max-width: 100%;
            }

            .brand-features {
                margin-top: 22px;
            }

            .brand-footer {
                margin-top: 10px;
            }

            .form-panel {
                padding: 45px 35px;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 12px;
            }

            .login-container {
                border-radius: 15px;
            }

            .brand-panel {
                padding: 27px 25px;
            }

            .brand-content h1 {
                font-size: 29px;
            }

            .brand-content p {
                font-size: 12px;
            }

            .form-panel {
                padding: 38px 25px;
            }

            .form-header h2 {
                font-size: 27px;
            }
        }
    </style>
</head>

<body>

<div class="login-container">

    <!-- LEFT BRANDING PANEL -->
    <section class="brand-panel">

        <div class="brand-logo">
            <div class="logo-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="4" width="18" height="17" rx="3"/>
                    <path d="M16 2v4M8 2v4M3 10h18"/>
                    <path d="m9 15 2 2 4-4"/>
                </svg>
            </div>
            <span class="logo-text">EMIS</span>
        </div>

        <div class="brand-content">

            <div class="brand-tag">EVENT MANAGEMENT SYSTEM</div>

            <h1>
                Manage events.<br>
                Make things<br>
                <span>happen.</span>
            </h1>

            <p>
                Your centralized platform for managing events,
                coordinating activities, and keeping everything
                organized in one place.
            </p>

            <div class="brand-features">

                <div class="feature">
                    <span class="feature-check">✓</span>
                    Event scheduling and management
                </div>

                <div class="feature">
                    <span class="feature-check">✓</span>
                    Attendance and participant tracking
                </div>

                <div class="feature">
                    <span class="feature-check">✓</span>
                    Equipment and resource management
                </div>

            </div>
        </div>

        <div class="brand-footer">
            © <?php echo date('Y'); ?> EMIS. All rights reserved.
        </div>

    </section>


    <!-- RIGHT LOGIN PANEL -->
    <section class="form-panel">

        <div class="form-header">
            <div class="welcome">WELCOME BACK</div>
            <h2>Sign in to EMIS</h2>
            <p>Enter your account details to continue.</p>
        </div>

        <?php if ($error): ?>
            <div class="error-message">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">

            <!-- EMAIL -->
            <div class="form-group">

                <label for="email">Email Address</label>

                <div class="input-wrapper">

                    <span class="input-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <rect x="3" y="5" width="18" height="14" rx="2"/>
                            <path d="m3 7 9 6 9-6"/>
                        </svg>
                    </span>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="Enter your email address"
                        autocomplete="email"
                        required
                    >

                </div>
            </div>


            <!-- PASSWORD -->
            <div class="form-group">

                <label for="password">Password</label>

                <div class="input-wrapper">

                    <span class="input-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <rect x="4" y="10" width="16" height="11" rx="2"/>
                            <path d="M8 10V7a4 4 0 0 1 8 0v3"/>
                        </svg>
                    </span>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="password-input"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required
                    >

                    <button
                        type="button"
                        class="toggle-password"
                        id="togglePassword"
                        aria-label="Show password"
                    >
                        <svg id="eyeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>

                </div>
            </div>


            <!-- LOGIN -->
            <button type="submit" class="login-button">
                Sign In
            </button>

        </form>


        <!-- DIVIDER -->
        <div class="divider">
            OR CONTINUE WITH
        </div>


        <!-- SOCIAL LOGIN -->
        <div class="social-buttons">

            <a
                href="https://tnvrgvcijqccqucwxqyr.supabase.co/auth/v1/authorize?provider=google&redirect_to=https://test-systeml.onrender.com/auth-callback.php"
                class="social-button"
            >
                <svg viewBox="0 0 48 48">
                    <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                    <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.71 7.18l7.19 5.59c4.2-3.87 6.56-9.57 6.56-17.24z"/>
                    <path fill="#FBBC05" d="M10.53 28.59A14.4 14.4 0 0 1 9.75 24c0-1.59.27-3.13.76-4.59l-7.98-6.19A23.9 23.9 0 0 0 0 24c0 3.86.92 7.52 2.56 10.78l7.97-6.19z"/>
                    <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.91-5.79l-7.19-5.59c-1.99 1.33-4.52 2.12-8.72 2.12-6.26 0-11.57-4.22-13.47-9.91l-7.97 6.19C6.51 42.62 14.62 48 24 48z"/>
                </svg>
                Continue with Google
            </a>

            <a
                href="https://tnvrgvcijqccqucwxqyr.supabase.co/auth/v1/authorize?provider=facebook&redirect_to=https://test-systeml.onrender.com/auth-callback.php"
                class="social-button"
            >
                <svg viewBox="0 0 48 48">
                    <path fill="#1877F2" d="M48 24C48 10.75 37.25 0 24 0S0 10.75 0 24c0 11.98 8.78 21.91 20.25 23.71V30.94h-6.09V24h6.09v-5.29c0-6.01 3.58-9.33 9.05-9.33 2.62 0 5.36.47 5.36.47v5.89h-3.02c-2.98 0-3.91 1.85-3.91 3.75V24h6.65l-1.06 6.94h-5.59v16.77C39.22 45.91 48 35.98 48 24z"/>
                    <path fill="#fff" d="M33.32 30.94 34.38 24h-6.65v-4.51c0-1.9.93-3.75 3.91-3.75h3.02V9.85s-2.74-.47-5.36-.47c-5.47 0-9.05 3.32-9.05 9.33V24h-6.09v6.94h6.09v16.77a24.2 24.2 0 0 0 7.48 0V30.94h5.59z"/>
                </svg>
                Continue with Facebook
            </a>

        </div>


        <!-- REGISTER -->
        <div class="register-text">
            Don't have an account?
            <a href="register.php">Create an account</a>
        </div>


        <!-- SECURITY -->
        <div class="security-note">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"/>
                <path d="m9 12 2 2 4-4"/>
            </svg>
            Secure authentication powered by EMIS
        </div>

    </section>

</div>


<!-- SHOW / HIDE PASSWORD -->
<script>
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    const eyeIcon = document.getElementById('eyeIcon');

    togglePassword.addEventListener('click', function () {

        const isPassword = passwordInput.type === 'password';

        passwordInput.type = isPassword ? 'text' : 'password';

        togglePassword.setAttribute(
            'aria-label',
            isPassword ? 'Hide password' : 'Show password'
        );

        eyeIcon.innerHTML = isPassword
            ? '<path d="M3 3l18 18M10.58 10.59a2 2 0 0 0 2.83 2.83"/><path d="M9.88 5.09A10.8 10.8 0 0 1 12 4.8c6.5 0 10 7.2 10 7.2a14.8 14.8 0 0 1-3.1 4.1M6.61 6.61C3.63 8.5 2 12 2 12s3.5 7.2 10 7.2c1.5 0 2.86-.35 4.08-.94"/>'
            : '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>';
    });
</script>

<script src="/js/pwa.js"></script>

</body>
</html>
