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
        session_regenerate_id(true); // Prevent session fixation
        $_SESSION['user_id'] = $user['id'] ?? '';
        $_SESSION['user_email'] = $user['email'] ?? $email;
        $_SESSION['user_role'] = $user['role'] ?? 'user';
        $_SESSION['user_name'] = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        
        redirectByRole($_SESSION['user_role']);
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-dark text-white"><h4>System Login</h4></div>
                <div class="card-body">
                    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-dark w-100">Login</button>
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
                        Don't have an account? <a href="register.php">Register here</a>.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>