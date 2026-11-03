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
</head>
<body>

<div class="container">
    <div class="scanner-container">
        <h3 class="mb-3 text-center text-primary"><i class="bi bi-camera-fill me-2"></i>Face & GPS Check-In</h3>
        <p class="text-muted text-center small mb-4">Position your face clearly in front of the camera. Make sure your location permission is allowed.</p>

        <!-- Status Alert Area -->
        <div id="statusAlert" class="alert alert-info text-center" role="alert">
            Initializing camera and requesting GPS coordinates...
        </div>

        <!-- Camera Viewport -->
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
            <a href="dashboard.php" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left me-1"></i> Back to Dashboard</a>
        </div>
    </div>
</div>

<script>
const video = document.getElementById('videoElement');
const canvas = document.getElementById('canvas');
const captureBtn = document.getElementById('captureBtn');
const statusAlert = document.getElementById('statusAlert');
const latDisplay = document.getElementById('latDisplay');
const lngDisplay = document.getElementById('lngDisplay');

let currentLatitude = null;
let currentLongitude = null;
let mediaStream = null;

// 1. Access Webcam
navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480 }, audio: false })
    .then(stream => {
        mediaStream = stream;
        video.srcObject = stream;
        statusAlert.className = 'alert alert-warning text-center';
        statusAlert.innerHTML = 'Camera ready. Waiting for GPS location...';
    })
    .catch(err => {
        statusAlert.className = 'alert alert-danger text-center';
        statusAlert.innerHTML = 'Camera Error: Please allow camera permissions in your browser.';
    });

// 2. Access GPS Geolocation
if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
        position => {
            currentLatitude = position.coords.latitude;
            currentLongitude = position.coords.longitude;
            
            latDisplay.textContent = currentLatitude;
            lngDisplay.textContent = currentLongitude;

            statusAlert.className = 'alert alert-success text-center';
            statusAlert.innerHTML = 'Ready! GPS coordinates acquired. Click below to check in.';
            captureBtn.disabled = false;
        },
        error => {
            statusAlert.className = 'alert alert-danger text-center';
            statusAlert.innerHTML = 'GPS Error: Please enable location services to check in.';
        },
        { enableHighAccuracy: true, timeout: 10000 }
    );
} else {
    statusAlert.className = 'alert alert-danger text-center';
    statusAlert.innerHTML = 'Geolocation is not supported by your browser.';
}

// 3. Capture and Submit Handler
captureBtn.addEventListener('click', async () => {
    captureBtn.disabled = true;
    captureBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing Check-In...';
    statusAlert.className = 'alert alert-info text-center';
    statusAlert.innerHTML = 'Snapping photo and recording check-in...';

    // Draw video frame to canvas
    canvas.width = video.videoWidth || 640;
    canvas.height = video.videoHeight || 480;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    const photoDataUrl = canvas.toDataURL('image/jpeg', 0.85);

    // Prepare payload to send to backend processing script
    try {
        const response = await fetch('process-check-in.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                event_id: '<?= $eventId ?>',
                participant_id: '<?= $participantId ?>',
                latitude: currentLatitude,
                longitude: currentLongitude,
                photo_data: photoDataUrl
            })
        });

        const result = await response.json();

        if (result.success) {
            statusAlert.className = 'alert alert-success text-center';
            statusAlert.innerHTML = '<strong>Check-in Successful!</strong> Redirecting to dashboard...';
            setTimeout(() => {
                window.location.href = 'dashboard.php';
            }, 2000);
        } else {
            throw new Error(result.message || 'Server rejected check-in.');
        }
    } catch (err) {
        captureBtn.disabled = false;
        captureBtn.innerHTML = '<i class="bi bi-shield-check me-2"></i> Capture Face & Check In';
        statusAlert.className = 'alert alert-danger text-center';
        statusAlert.innerHTML = 'Error: ' + err.message;
    }
});
</script>
</body>
</html>