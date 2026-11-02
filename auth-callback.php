<?php
session_start();
require_once 'config/database.php';

// Handle AJAX request from frontend JS to establish the PHP session
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (isset($input['email'])) {
        $email = $input['email'];
        $fullName = $input['full_name'] ?? 'Google User';
        
        // Check if user profile already exists in Supabase
        $response = supabaseRequest('profiles?email=eq.' . urlencode($email), 'GET');
        $rows = [];
        if (is_array($response)) {
            $rows = isset($response['data']) ? $response['data'] : $response;
        }
        
        $user = (!empty($rows) && isset($rows[0])) ? $rows[0] : null;
        
        // If user doesn't exist yet, auto-create their profile for social login
        if (!$user) {
            $nameParts = explode(' ', $fullName, 2);
            $firstName = $nameParts[0];
            $lastName = $nameParts[1] ?? '';
            
            $payload = [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'role' => 'user',
                'is_verified' => true
            ];
            supabaseRequest('profiles', 'POST', $payload);
            $user = $payload;
        }

        // Set up the PHP session variables
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'] ?? '';
        $_SESSION['user_email'] = $email;
        $_SESSION['user_role'] = $user['role'] ?? 'user';
        $_SESSION['user_name'] = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? $fullName));
        
        echo json_encode(['status' => 'success']);
        exit;
    }
    echo json_encode(['status' => 'error']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Authenticating - EMIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="height: 100vh;">
    <div class="text-center">
        <div class="spinner-border text-primary mb-3" role="status"></div>
        <h5>Completing your Google login...</h5>
    </div>

    <script>
        window.addEventListener('load', async () => {
            // Supabase returns the token in the URL fragment (hash)
            const hash = window.location.hash.substring(1);
            const params = new URLSearchParams(hash);
            const accessToken = params.get('access_token');

            if (accessToken) {
                try {
                    // Fetch user details from Supabase using the token
                    const response = await fetch('https://tnvrgvcijqccqucwxqyr.supabase.co/auth/v1/user', {
                        headers: {
                            'Authorization': 'Bearer ' + accessToken,
                            'apikey': 'sb_publishable_LHfcDCsaDzguV3uy8b7TOg_zzkc5nYW' // <-- Make sure your public Supabase anon key is here!
                        }
                    });
                    const sbUser = await response.json();
                    
                    if (sbUser && sbUser.email) {
                        // Send user info back to PHP to create the session
                        const sessionRes = await fetch('auth-callback.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                email: sbUser.email,
                                full_name: sbUser.user_metadata?.full_name || sbUser.email.split('@')[0]
                            })
                        });
                        const result = await sessionRes.json();
                        
                        if (result.status === 'success') {
                            window.location.href = 'participant/dashboard.php';
                        } else {
                            window.location.href = 'login.php?error=session_failed';
                        }
                    } else {
                        window.location.href = 'login.php?error=auth_failed';
                    }
                } catch (err) {
                    console.error('Auth error:', err);
                    window.location.href = 'login.php?error=server_error';
                }
            } else {
                window.location.href = 'login.php';
            }
        });
    </script>
</body>
</html>
