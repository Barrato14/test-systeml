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
if (empty($faceProfileCheck['data'])) {
    die('Please <a href="face-registration.php">register your face</a> before checking in.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Check-In - EMIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8f9fa; }
        .scanner-container { max-width: 600px; margin: 40px auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        #videoElement { width: 100%; border-radius: 8px; background: #000; }
        #canvas { display: none; }
    </style>
<link rel="stylesheet" href="../css/style.css">
</head>
<body class="participant-page">
<?php include '../includes/participant-nav.php'; ?>


<div class="container">
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
</div>

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
</body>
</html>