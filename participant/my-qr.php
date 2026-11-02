<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/mail.php'; // Required for sending the email
require_once '../vendor/autoload.php'; 

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

// Ensure the user is logged in as a participant
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Participant') {
    header('Location: ../login.php');
    exit();
}

$participant_id = $_SESSION['user_id'];
$eventId = $_GET['event_id'] ?? null;

if (!$eventId) {
    die("Event ID is required.");
}

// 1. Verify the participant has an 'Approved' registration
$regResponse = supabaseRequest("event_registrations?event_id=eq.$eventId&participant_id=eq.$participant_id&status=eq.Approved", 'GET');
$rows = isset($regResponse['data']) && is_array($regResponse['data']) ? $regResponse['data'] : (is_array($regResponse) ? $regResponse : []);
$registration = !empty($rows) ? $rows[0] : null;

if (!$registration) {
    die("You do not have an approved registration for this event.");
}

$regId = $registration['id'];

// 2. Fetch existing token or generate a new one
$tokenResponse = supabaseRequest("qr_tokens?registration_id=eq.$regId", 'GET');
$tokenRows = isset($tokenResponse['data']) && is_array($tokenResponse['data']) ? $tokenResponse['data'] : (is_array($tokenResponse) ? $tokenResponse : []);
$existingToken = !empty($tokenRows) ? $tokenRows[0] : null;

$rawToken = '';

if ($existingToken) {
    $rawToken = $existingToken['token_hash']; 
} else {
    $rawToken = bin2hex(random_bytes(32)); 
    $payload = [
        'registration_id' => $regId,
        'token_hash' => $rawToken,
        'status' => 'Active'
    ];
    supabaseRequest('qr_tokens', 'POST', $payload);
}

// 3. Configure and Generate the PNG QR Code (PNG is required for email attachments)
$options = new QROptions([
    'version'      => 5,
    'outputType'   => QRCode::OUTPUT_IMAGE_PNG,
    'eccLevel'     => QRCode::ECC_L,
    'scale'        => 5,
    'imageBase64'  => false // Raw data for email attachment
]);

$qrcode = new QRCode($options);
$qrData = json_encode(['token' => $rawToken, 'event_id' => $eventId]);
$qrRawImage = $qrcode->render($qrData);

// Convert to Base64 strictly for displaying on the webpage
$qrBase64 = 'data:image/png;base64,' . base64_encode($qrRawImage);

// 4. Handle Email Request
$emailSuccess = '';
$emailError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_email'])) {
    // Assuming you store email and name in session during login
    $userEmail = $_SESSION['user_email'] ?? 'test@example.com';
    $userName = $_SESSION['user_name'] ?? 'Participant';
    $subject = "Your QR Pass for Event";
    $body = "<h3>Your Event Pass is Ready!</h3><p>Please find your QR code pass attached to this email. Present it at the entrance for scanning.</p>";
    
    if (sendSystemEmail($userEmail, $userName, $subject, $body, $qrRawImage, 'Event-QR-Pass.png')) {
        $emailSuccess = "Your QR pass has been emailed to $userEmail!";
    } else {
        $emailError = "Failed to send the email. Please check your SMTP settings.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My QR Pass - EMIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="card shadow-sm mx-auto" style="max-width: 400px;">
        <div class="card-header bg-primary text-white text-center">
            <h4>Your Event QR Pass</h4>
        </div>
        <div class="card-body text-center">
            
            <?php if ($emailSuccess): ?>
                <div class="alert alert-success"><?= $emailSuccess ?></div>
            <?php endif; ?>
            
            <?php if ($emailError): ?>
                <div class="alert alert-danger"><?= $emailError ?></div>
            <?php endif; ?>

            <p class="text-muted">Present this QR code to the staff at the event entrance.</p>
            
            <div class="mb-3 d-flex justify-content-center bg-white p-3 border rounded">
                <img src="<?= $qrBase64 ?>" alt="QR Pass" class="img-fluid">
            </div>
            
            <p class="small text-danger fw-bold">Do not share this QR code with anyone else.</p>
            
            <form method="POST">
                <button type="submit" name="send_email" class="btn btn-success w-100 mb-2">Email Me a Copy</button>
            </form>
            
            <a href="dashboard.php" class="btn btn-outline-secondary w-100">Back to Dashboard</a>
        </div>
    </div>
</div>
</body>
</html>