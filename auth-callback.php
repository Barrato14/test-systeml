<?php
// auth-callback.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Logging in...</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="height: 100vh;">
    <div class="text-center">
        <div class="spinner-border text-primary mb-3" role="status"></div>
        <h5>Signing you in with Google...</h5>
    </div>

    <script>
        window.addEventListener('load', async () => {
            // Supabase returns the token in the URL fragment (hash)
            const hash = window.location.hash.substring(1);
            const params = new URLSearchParams(hash);
            const accessToken = params.get('access_token');

            if (accessToken) {
                try {
                    // Verify and fetch user details from Supabase using the token
                    const response = await fetch('https://tnvrgvcijqccqucwxqyr.supabase.co/auth/v1/user', {
                        headers: {
                            'Authorization': 'Bearer ' + accessToken,
                            'apikey': 'YOUR_SUPABASE_ANON_KEY' // Replace with your public Supabase anon key
                        }
                    });
                    const user = await response.json();
                    
                    if (user && user.email) {
                        // Successfully authenticated, redirect to your dashboard or landing page
                        window.location.href = 'dashboard.php';
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