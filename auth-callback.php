<?php
require_once 'includes/session.php';
require_once 'config/database.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Signing in - Zentrix</title>
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script><link rel="stylesheet" href="css/style.css?v=20261118-auth-premium"><meta name="theme-color" content="#342B46">
</head>
<body class="auth-callback-page">
    <main class="auth-layout">
        <section class="auth-form-wrapper">
            <div class="auth-form-container">
                <div class="brand-logo"><i class="ph-fill ph-ticket"></i><span>Zentrix</span></div>
                <div class="auth-callback-spinner" aria-hidden="true"><i class="ph ph-spinner-gap"></i></div>
                <header class="form-header">
                    <h2>Completing sign in</h2>
                    <p id="authStatus">Verifying your Google account...</p>
                </header>
                <div id="authError" class="alert-error" role="alert" hidden></div>
                <form id="sessionForm" method="POST" action="set-session.php">
                    <input type="hidden" name="access_token" id="accessToken">
                    <input type="hidden" name="auth_source" id="authSource">
                </form>
            </div>
        </section>
    </main>
    <script>
        (() => {
            const params = new URLSearchParams(window.location.hash.substring(1));
            const token = params.get('access_token');
            document.getElementById('authSource').value = new URLSearchParams(window.location.search).get('source') === 'register' ? 'register' : 'login';
            const error = params.get('error_description') || params.get('error');

            const showError = (message) => {
                document.getElementById('authStatus').textContent = 'We could not complete your sign in.';
                const box = document.getElementById('authError');
                box.hidden = false;
                box.textContent = message;
            };

            if (error) {
                showError(error.replace(/\+/g, ' '));
                return;
            }

            if (!token) {
                showError('No authentication token was returned. Please try again.');
                return;
            }

            document.getElementById('accessToken').value = token;
            document.getElementById('sessionForm').submit();
        })();
    </script>
</body>
</html>