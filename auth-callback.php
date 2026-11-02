<?php
session_start();
require_once 'config/database.php';

// We will let the JavaScript grab the access token from the URL fragment (#) 
// and submit it via a standard form POST or direct parameter passing, 
// or even better, let's handle the session creation directly.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Logging in - EMIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="height: 100vh;">
    <div class="text-center">
        <div class="spinner-border text-primary mb-3" role="status"></div>
        <h5>Setting up your session...</h5>
    </div>

    <script>
        window.addEventListener('load', async () => {
            const hash = window.location.hash.substring(1);
            const params = new URLSearchParams(hash);
            const accessToken = params.get('access_token');

            if (accessToken) {
                try {
                    const response = await fetch('https://tnvrgvcijqccqucwxqyr.supabase.co/auth/v1/user', {
                        headers: {
                            'Authorization': 'Bearer ' + accessToken,
                            'apikey': 'sb_publishable_LHfcDCsaDzguV3uy8b7TOg_zzkc5nYW'
                        }
                    });
                    const sbUser = await response.json();
                    
                    if (sbUser && sbUser.email) {
                        // Create a hidden form to submit the user data securely via POST to a session creator script
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = 'set-session.php'; // We will point to a dedicated session setter

                        const emailInput = document.createElement('input');
                        emailInput.type = 'hidden';
                        emailInput.name = 'email';
                        emailInput.value = sbUser.email;
                        form.appendChild(emailInput);

                        const nameInput = document.createElement('input');
                        nameInput.type = 'hidden';
                        nameInput.name = 'full_name';
                        nameInput.value = sbUser.user_metadata?.full_name || sbUser.email.split('@')[0];
                        form.appendChild(nameInput);

                        document.body.appendChild(form);
                        form.submit();
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