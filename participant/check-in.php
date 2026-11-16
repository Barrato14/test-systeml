<?php
session_start();
date_default_timezone_set('Asia/Manila');
require_once '../config/database.php';
require_once '../includes/auth.php';

// Ensure user is logged in as a Participant
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role']) !== 'participant') {
    header("Location: ../login.php");
    exit;
}

$userId = $_SESSION['user_id'];

// Check if Google/OAuth user has completed their profile (Course & Section)
$profileRes = supabaseRequest('profiles?id=eq.' . $userId . '&select=course,section', 'GET');
if ($profileRes['code'] === 200 && !empty($profileRes['data'])) {
    $profile = $profileRes['data'][0];
    if (empty($profile['course']) || empty($profile['section'])) {
        header("Location: complete-profile.php");
        exit;
    }
}

$eventId = $_GET['event_id'] ?? '';
$participantId = $userId;

if (empty($eventId)) {
    die("Error: No event specified for check-in.");
}

$faceProfileCheck = supabaseServiceRequest(
    'participant_face_profiles?participant_id=eq.' . rawurlencode((string)$participantId) . '&select=participant_id',
    'GET'
);
if (($faceProfileCheck['code'] ?? 500) >= 400) {
    die("Face verification is temporarily unavailable. Please contact the administrator.");
}
$faceRegistrationRequired = empty($faceProfileCheck['data']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Check-In - Zentrix</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8f9fa; }
        .checkin-main { padding-top: 54px; padding-bottom: 70px; }
        .checkin-prerequisite-card { width: min(100%, 620px); margin: 0 auto; padding: 38px; border: 1px solid #49345f; border-radius: 20px; background: #171222; box-shadow: 0 18px 50px rgba(0,0,0,.2); text-align: center; }
        .checkin-prerequisite-icon { display: grid; place-items: center; width: 72px; height: 72px; margin: 0 auto 20px; border: 1px solid #694b8b; border-radius: 20px; background: #2a1b3c; color: #d5b8fa; font-size: 30px; }
        .checkin-eyebrow { display: block; margin-bottom: 10px; color: #c4a5fd; font-size: 10px; font-weight: 800; letter-spacing: .16em; }
        .checkin-prerequisite-card h1 { margin: 0 0 12px; color: #f7f0ff; font-size: clamp(24px,4vw,30px); font-weight: 800; letter-spacing: -.035em; }
        .checkin-prerequisite-message { max-width: 470px; margin: 0 auto 22px; color: #b9adc9; font-size: 13px; line-height: 1.8; }
        .checkin-prerequisite-note { display: flex; align-items: flex-start; gap: 11px; margin: 0 0 22px; padding: 14px; border: 1px solid #3e304f; border-radius: 12px; background: #21182d; color: #c9bdd7; font-size: 11px; line-height: 1.65; text-align: left; }
        .checkin-prerequisite-note i { flex: 0 0 auto; color: #c4a5fd; font-size: 17px; }
        .checkin-register-button { display: inline-flex; align-items: center; justify-content: center; gap: 10px; width: 100%; min-height: 48px; padding: 12px 18px; border: 1px solid #8b5bc0; border-radius: 10px; background: #6d3c98; color: #fff!important; font-size: 13px; font-weight: 750; text-decoration: none; transition: background .18s ease,border-color .18s ease; }
        .checkin-register-button:hover { border-color: #b58be0; background: #7b49a8; }
        .checkin-back-link { display: inline-flex; align-items: center; gap: 7px; margin-top: 18px; color: #c8b3df!important; font-size: 11px; font-weight: 650; text-decoration: none; }
        .checkin-back-link:hover { color: #fff!important; }
        @media(max-width:600px) { .checkin-main { padding-top: 28px; padding-bottom: 45px; } .checkin-prerequisite-card { padding: 27px 20px; border-radius: 16px; } }
        html[data-participant-theme="light"] .checkin-prerequisite-card { border-color: #e3d9ec; background: #fff; box-shadow: 0 12px 35px rgba(40,20,60,.07); }
        html[data-participant-theme="light"] .checkin-prerequisite-card h1 { color: #30243b; }
        html[data-participant-theme="light"] .checkin-prerequisite-message { color: #71677d; }
        html[data-participant-theme="light"] .checkin-prerequisite-note { border-color: #e9e0ef; background: #faf7fd; color: #665a70; }
        html[data-participant-theme="light"] .checkin-back-link { color: #704795!important; }
        .scanner-container { max-width: 600px; margin: 40px auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        #videoElement { width: 100%; border-radius: 8px; background: #000; }
        #canvas { display: none; }
        @media (max-width: 600px) { .scanner-container { margin: 18px auto; padding: 18px; } #videoElement { max-height: 58vh; object-fit: cover; } }
        @media (max-width: 360px) { .scanner-container { margin: 12px auto; padding: 13px; } .scanner-container h3 { font-size: 1.15rem; } .scanner-container .alert { overflow-wrap: anywhere; } }
    </style>
<link rel="stylesheet" href="../css/style.css">
</head>
<body class="participant-page">
<?php include '../includes/participant-nav.php'; ?>


<main class="container checkin-main">
    <?php if ($faceRegistrationRequired): ?>
        <section class="checkin-prerequisite-card" role="status">
            <div class="checkin-prerequisite-icon"><i class="bi bi-person-bounding-box"></i></div>
            <span class="checkin-eyebrow">CHECK-IN REQUIREMENT</span>
            <h1>Face registration required</h1>
            <p class="checkin-prerequisite-message">Before you can check in to this event, you need to register your face. This allows Zentrix to verify your identity during attendance.</p>
            <div class="checkin-prerequisite-note"><i class="bi bi-shield-check"></i><span>Your face registration is linked to your participant account and is used for attendance verification.</span></div>
            <a class="checkin-register-button" href="face-registration.php"><i class="bi bi-camera-fill"></i> Register My Face <i class="bi bi-arrow-right"></i></a>
            <a class="checkin-back-link" href="my-events.php"><i class="bi bi-arrow-left"></i> Back to My Events</a>
        </section>
    <?php else: ?>
        <div class="scanner-container">
        <h3 class="mb-3 text-center text-primary"><i class="bi bi-camera-fill me-2"></i>Face & GPS Check-In</h3>
        <p class="text-muted text-center small mb-4">Position your face clearly in front of the camera. Make sure your location permission is allowed.</p>

        <!-- Status Alert Area -->
        <div id="statusAlert" class="alert alert-info text-center" role="alert">
            Initializing camera and requesting GPS coordinates...
        </div>

        <!-- Camera Viewport -->
        <div id="faceDetectionStatus" class="alert alert-info text-center">Loading face detection...</div>
        <div class="position-relative mb-3">
            <video id="videoElement" autoplay playsinline></video>
            <canvas id="canvas"></canvas>
        </div>

        <!-- Location Data Preview -->
        <div class="mb-3 p-3 bg-light rounded border small">
            <div><strong>Latitude:</strong> <span id="latDisplay">Acquiring...</span></div>
            <div><strong>Longitude:</strong> <span id="lngDisplay">Acquiring...</span></div>
        </div>

        <!-- Action Button -->
        <button id="captureBtn" class="btn btn-primary w-100 py-2 fw-bold" disabled>
            <i class="bi bi-shield-check me-2"></i> Capture Face & Check In
        </button>

        <div class="text-center mt-3">
            
        </div>
        </div>
    <?php endif; ?>
</main>

<?php if (!$faceRegistrationRequired): ?>
<script>
const video = document.getElementById('videoElement');
const canvas = document.getElementById('canvas');
const captureBtn = document.getElementById('captureBtn');
const statusAlert = document.getElementById('statusAlert');
const faceDetectionStatus = document.getElementById('faceDetectionStatus');
const latDisplay = document.getElementById('latDisplay');
const lngDisplay = document.getElementById('lngDisplay');

let currentLatitude = null;
let currentLongitude = null;
let cameraStream = null;
let cameraReady = false;
let gpsReady = false;

function updateCaptureAvailability() {
    captureBtn.disabled = !(cameraReady && gpsReady);
    if (cameraReady && gpsReady) {
        statusAlert.className = 'alert alert-success text-center';
        statusAlert.textContent = 'Camera and GPS are ready. Python will verify your face when you capture.';
    }
}

async function startCamera() {
    try {
        cameraStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 960 }, height: { ideal: 720 } },
            audio: false
        });
        video.srcObject = cameraStream;
        await video.play();
        cameraReady = true;
        faceDetectionStatus.className = 'alert alert-success text-center';
        faceDetectionStatus.textContent = 'Camera ready. Python will detect and verify one face during attendance.';
        updateCaptureAvailability();
    } catch (error) {
        faceDetectionStatus.className = 'alert alert-danger text-center';
        faceDetectionStatus.textContent = error.message || 'Unable to access the camera. Allow camera permission and try again.';
    }
}

if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
        position => {
            currentLatitude = position.coords.latitude;
            currentLongitude = position.coords.longitude;
            latDisplay.textContent = currentLatitude;
            lngDisplay.textContent = currentLongitude;
            gpsReady = true;
            updateCaptureAvailability();
        },
        () => {
            statusAlert.className = 'alert alert-danger text-center';
            statusAlert.textContent = 'GPS Error: Please enable location services to check in.';
        },
        { enableHighAccuracy: true, timeout: 10000 }
    );
} else {
    statusAlert.className = 'alert alert-danger text-center';
    statusAlert.textContent = 'Geolocation is not supported by your browser.';
}

captureBtn.addEventListener('click', async () => {
    if (!cameraReady || !gpsReady || !video.videoWidth) return;

    captureBtn.disabled = true;
    captureBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Verifying Face & Checking In...';
    statusAlert.className = 'alert alert-info text-center';
    statusAlert.textContent = 'Capturing image. Python is detecting and matching your registered face...';

    try {
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
        const photoDataUrl = canvas.toDataURL('image/jpeg', 0.88);

        const response = await fetch('process-check-in.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                event_id: <?= json_encode((string)$eventId) ?>,
                latitude: currentLatitude,
                longitude: currentLongitude,
                photo_data: photoDataUrl
            })
        });

        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Server rejected check-in.');

        statusAlert.className = 'alert alert-success text-center';
        statusAlert.textContent = 'Check-in successful! Redirecting to dashboard...';
        setTimeout(() => { window.location.href = 'dashboard.php'; }, 2000);
    } catch (error) {
        captureBtn.disabled = false;
        captureBtn.innerHTML = '<i class="bi bi-shield-check me-2"></i> Capture Face & Check In';
        statusAlert.className = 'alert alert-danger text-center';
        statusAlert.textContent = 'Error: ' + error.message;
    }
});

window.addEventListener('beforeunload', () => {
    if (cameraStream) cameraStream.getTracks().forEach(track => track.stop());
});

startCamera();
</script>
<?php endif; ?>
</body>
</html>