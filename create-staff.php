
<?php
require_once 'config/database.php';


$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $message = 'Password must be at least 8 characters.';
    } elseif ($firstName === '' || $lastName === '') {
        $message = 'Please enter the account holder name.';
    } else {
        $existing = supabaseRequest(
            'profiles?email=eq.' . urlencode($email),
            'GET'
        );

        $rows = isset($existing['data']) && is_array($existing['data'])
            ? $existing['data']
            : $existing;

        if (is_array($rows) && !empty($rows)) {
            $message = 'An account with this email already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);

            $result = supabaseRequest('profiles', 'POST', [
                'email' => $email,
                'password_hash' => $hash,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'role' => 'Staff',
                'is_verified' => true
            ]);

            if (isset($result['message']) || isset($result['code'])) {
                $message = 'Database error: ' .
                    ($result['message'] ?? 'Could not create account.');
            } else {
                $message = 'Staff account created successfully!';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Create Staff Account</title>
<style>
body {
    margin: 0; min-height: 100vh; display: grid;
    place-items: center; background: #0b1020;
    font-family: Arial, sans-serif; color: white;
}
form {
    width: min(360px, calc(100% - 48px));
    padding: 32px; background: #171f35;
    border-radius: 16px;
}
h2 { margin-top: 0; }
input, button {
    box-sizing: border-box; width: 100%;
    padding: 13px; margin: 9px 0;
    border-radius: 8px; border: 1px solid #35415e;
}
input { background: #0b1020; color: white; }
button {
    background: #6c63ff; color: white;
    border: 0; font-weight: bold; cursor: pointer;
}
.message { color: #a5f3c1; }
</style>
</head>
<body>
<form method="POST">
    <h2>Create Staff Account</h2>
    <?php if ($message): ?>
        <p class="message"><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>

    <input name="first_name" placeholder="First name" required>
    <input name="last_name" placeholder="Last name" required>
    <input type="email" name="email" placeholder="Email" required>
    <input type="password" name="password"
           placeholder="Password (minimum 8 characters)" required>
    <button type="submit">Create Staff Account</button>
</form>
</body>
</html>
