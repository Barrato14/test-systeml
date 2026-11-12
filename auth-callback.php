<?php
session_start();
require_once 'config/database.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Signing in - EMIS</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <main class="auth-layout">
        <section class="auth-form-wrapper">
            <div class="auth-form-container">
                <header class="form-header">
                    <h2>Completing sign in</h2>
                    <p id="authStatus">Verifying your Google account...</p>
                </header>
                <div id="authError" class="alert-error" role="alert" hidden></div>
                <form id="sessionForm" method="POST" action="set-session.php">
                    <input type="hidden" name="access_token" id="accessToken">
                </form>
            </div>
        </section>
    </main>
    <script>
        (() => {
            const params = new URLSearchParams(window.location.hash.substring(1));
            const token = params.get('access_token');
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