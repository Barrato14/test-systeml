<?php
require_once 'config/database.php';
require_once 'includes/auth.php';

// If Supabase returns an error via query parameters
if (isset($_GET['error'])) {
    header('Location: login.php?error=' . urlencode($_GET['error_description'] ?? 'OAuth failed'));
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Authenticating...</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="height: 100vh;">
    <div class="text-center">
        <div class="spinner-border text-primary mb-3" role="status"></div>
        <h5>Completing your sign-in...</h5>
    </div>

<script>
// Supabase passes tokens in the URL hash fragment (#access_token=...)
const hash = window.location.hash.substring(1);
const params = new URLSearchParams(hash);
const accessToken = params.get('access_token');

if (accessToken) {
    // Send the access token to a backend PHP session setter script via POST
    fetch('api/auth-session.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ access_token: accessToken })
    })
    document.getElementById('loading').style.display = 'none';
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.location.href = data.redirect;
            } else {
                alert('Authentication sync failed: ' + data.message);
                window.location.href = '../login.php';
            }
        })
        .catch(err => {
            console.error(err);
            window.location.href = '../login.php';
        });
} else {
    alert('No access token found.');
    window.location.href = '../login.php';
}
</script>
</body>
</html>