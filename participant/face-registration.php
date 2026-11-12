<?php
session_start();
date_default_timezone_set('Asia/Manila');
require_once '../config/database.php';
require_once '../includes/auth.php';

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role'] ?? '') !== 'participant') {
    header('Location: ../login.php');
    exit;
}

if (empty($_SESSION['face_registration_csrf'])) {
    $_SESSION['face_registration_csrf'] = bin2hex(random_bytes(32));
}

$existingFaceResponse = supabaseServiceRequest(
    'participant_face_profiles?participant_id=eq.' . rawurlencode((string)$_SESSION['user_id']) . '&select=participant_id,updated_at',
    'GET'
);
$hasRegisteredFace = (($existingFaceResponse['code'] ?? 500) < 400 && !empty($existingFaceResponse['data']));
$lastUpdated = $hasRegisteredFace ? ($existingFaceResponse['data'][0]['updated_at'] ?? '') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Face Registration - EMIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .face-registration-card { max-width: 720px; margin: 35px auto; padding: 28px; background: #fff; border-radius: 14px; box-shadow: 0 4px 18px rgba(15,23,42,.07); }
        #faceRegistrationVideo { width: 100%; max-height: 420px; background: #111827; border-radius: 12px; object-fit: cover; transform: scaleX(-1); }
        .face-privacy-note { font-size: .9rem; color: #64748b; }
    </style>
</head>
<body class="participant-page">
<?php include '../includes/participant-nav.php'; ?>
<main class="container">
    <section class="face-registration-card">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="rounded-3 bg-primary-subtle text-primary p-3"><i class="bi bi-person-bounding-box fs-3"></i></div>
            <div>
                <h2 class="h4 mb-1">Face Registration</h2>
                <p class="text-muted mb-0">Register the face that will be used for your event attendance.</p>
            </div>
        </div>

        <?php if ($hasRegisteredFace): ?>
            <div class="alert alert-success">A face is already registered to your account. A new registration will replace it.</div>
        <?php else: ?>
            <div class="alert alert-info">You have not registered a face yet. Register before using face-based attendance.</div>
        <?php endif; ?>

        <div id="faceRegistrationStatus" class="alert alert-info text-center">Preparing face recognition...</div>
        <video id="faceRegistrationVideo" autoplay muted playsinline></video>

        <div class="form-check my-3">
            <input class="form-check-input" type="checkbox" id="faceConsent">
            <label class="form-check-label" for="faceConsent">
                I consent to EMIS storing a biometric face template linked to my account and using it only to verify my identity for event check-in and time-out.
            </label>
        </div>

        <p class="face-privacy-note">EMIS stores a mathematical face template rather than using your registration photo as the matching record. You can request assistance from the administrator if you want your face registration removed. Face matching can make mistakes and does not by itself prove that a live person is present.</p>

        <button type="button" id="saveFaceButton" class="btn btn-primary w-100" disabled>
            <i class="bi bi-shield-check me-2"></i><?= $hasRegisteredFace ? 'Update Registered Face' : 'Register My Face' ?>
        </button>
    </section>
</main>

<script>
const faceVideo = document.getElementById('faceRegistrationVideo');
const faceStatus = document.getElementById('faceRegistrationStatus');
const consentCheckbox = document.getElementById('faceConsent');
const saveFaceButton = document.getElementById('saveFaceButton');
const csrfToken = <?= json_encode($_SESSION['face_registration_csrf']) ?>;
let cameraStream = null;
let cameraReady = false;
let registrationSaved = false;

function updateSaveButton() {
    saveFaceButton.disabled = registrationSaved || !(cameraReady && consentCheckbox.checked);
}

async function startRegistrationCamera() {
    try {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            throw new Error('Camera access is not supported by this browser.');
        }
        cameraStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 960 }, height: { ideal: 720 } },
            audio: false
        });
        faceVideo.srcObject = cameraStream;
        await faceVideo.play();
        cameraReady = true;
        faceStatus.textContent = 'Camera ready. Center your face, make sure only you are visible, then register.';
        faceStatus.className = 'alert alert-success text-center';
        updateSaveButton();
    } catch (error) {
        faceStatus.textContent = error.message || 'Unable to access the camera. Please allow camera permission.';
        faceStatus.className = 'alert alert-danger text-center';
    }
}

consentCheckbox.addEventListener('change', updateSaveButton);

saveFaceButton.addEventListener('click', async function () {
    if (!cameraReady || !consentCheckbox.checked || !faceVideo.videoWidth) {
        faceStatus.textContent = 'Camera readiness and your consent are required.';
        faceStatus.className = 'alert alert-warning text-center';
        return;
    }

    const captureCanvas = document.createElement('canvas');
    captureCanvas.width = faceVideo.videoWidth;
    captureCanvas.height = faceVideo.videoHeight;
    captureCanvas.getContext('2d').drawImage(faceVideo, 0, 0, captureCanvas.width, captureCanvas.height);
    const imageData = captureCanvas.toDataURL('image/jpeg', 0.9);

    saveFaceButton.disabled = true;
    saveFaceButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Detecting and registering face...';
    faceStatus.textContent = 'Python is checking your image and preparing your face template...';
    faceStatus.className = 'alert alert-info text-center';

    try {
        const response = await fetch('save-face-registration.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
                image_data: imageData,
                consent_given: true,
                csrf_token: csrfToken
            })
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Face registration failed.');

        faceStatus.textContent = result.message;
        faceStatus.className = 'alert alert-success text-center';
        registrationSaved = true;
        saveFaceButton.innerHTML = '<i class="bi bi-check-circle me-2"></i>Face Registered';
    } catch (error) {
        faceStatus.textContent = error.message;
        faceStatus.className = 'alert alert-danger text-center';
        saveFaceButton.innerHTML = '<i class="bi bi-shield-check me-2"></i>Try Again';
        updateSaveButton();
    }
});

window.addEventListener('beforeunload', function () {
    if (cameraStream) cameraStream.getTracks().forEach(track => track.stop());
});

startRegistrationCamera();
</script>
</body>
</html>
