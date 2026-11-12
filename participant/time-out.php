<?php
session_start();
date_default_timezone_set('Asia/Manila');
require_once '../config/database.php';
require_once '../includes/auth.php';

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role'] ?? '') !== 'participant') {
    header("Location: ../login.php");
    exit;
}

$eventId = $_GET['event_id'] ?? '';
$participantId = $_SESSION['user_id'];

if ($eventId === '') {
    die("Error: No event specified for time out.");
}

// Verify the event exists and has not ended.
$eventResponse = supabaseRequest(
    'events?id=eq.' . rawurlencode($eventId) . '&select=id,title,start_datetime,end_datetime',
    'GET'
);

if (($eventResponse['code'] ?? 500) >= 400 || empty($eventResponse['data'])) {
    die("Error: Event not found.");
}

$event = $eventResponse['data'][0];

if (empty($event['start_datetime']) || empty($event['end_datetime'])) {
    die("Error: This event has an incomplete schedule. Please contact the event organizer.");
}

$manila = new DateTimeZone('Asia/Manila');
$now = new DateTimeImmutable('now', $manila);
$eventStart = (new DateTimeImmutable($event['start_datetime']))->setTimezone($manila);
$eventEnd = (new DateTimeImmutable($event['end_datetime']))->setTimezone($manila);

if ($eventEnd <= $eventStart) {
    die("Error: This event has an invalid schedule.");
}

if ($now < $eventStart) {
    die("Time-out is not available before the event starts.");
}

if ($now >= $eventEnd) {
    die("Time-out is closed. This event ended on " . htmlspecialchars($eventEnd->format('F j, Y, g:i A')) . " (PHT).");
}

// Verify active check-in exists and has not timed out.
$checkExisting = supabaseRequest(
    'attendance?event_id=eq.' . rawurlencode($eventId) .
    '&participant_id=eq.' . rawurlencode($participantId) . '&select=id,time_out',
    'GET'
);

if (($checkExisting['code'] ?? 500) !== 200 || empty($checkExisting['data'])) {
    die("Error: You must check in first before you can time out.");
}

if (!empty($checkExisting['data'][0]['time_out'])) {
    die("Error: You have already timed out for this event.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Time Out - EMIS</title>
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
        <h3 class="mb-3 text-center text-warning text-dark"><i class="bi bi-box-arrow-left me-2"></i>Face & GPS Time Out</h3>
        <p class="text-muted text-center small mb-4">Position your face clearly in front of the camera to log your departure.</p>
        <div id="statusAlert" class="alert alert-info text-center" role="alert">
            Initializing camera and requesting GPS coordinates...
        </div>
        <div id="faceDetectionStatus" class="alert alert-info text-center">Loading face detection...</div>
        <div class="position-relative mb-3">
            <video id="videoElement" autoplay playsinline></video>
            <canvas id="canvas"></canvas>
        </div>
        <div class="mb-3 p-3 bg-light rounded border small">
            <div><strong>Latitude:</strong> <span id="latDisplay">Acquiring...</span></div>
            <div><strong>Longitude:</strong> <span id="lngDisplay">Acquiring...</span></div>
        </div>
        <button id="captureBtn" class="btn btn-warning fw-bold w-100 py-2" disabled>
            <i class="bi bi-shield-check me-2"></i> Capture Face & Time Out
        </button>
        <div class="text-center mt-3">
            
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.15/dist/face-api.js"></script>
<script src="../js/emis-face.js"></script>
<script>
const video = document.getElementById('videoElement');
const canvas = document.getElementById('canvas');
const captureBtn = document.getElementById('captureBtn');
const statusAlert = document.getElementById('statusAlert');
const latDisplay = document.getElementById('latDisplay');
const lngDisplay = document.getElementById('lngDisplay');

let currentLatitude = null;
let currentLongitude = null;
let faceDescriptor = null;
let faceReady = false;
let gpsReady = false;

function updateCaptureAvailability() {
    captureBtn.disabled = !(faceReady && gpsReady);
    if (faceReady && gpsReady) {
        statusAlert.className = 'alert alert-success text-center';
        statusAlert.textContent = 'Face detected and GPS acquired. You can proceed.';
    }
}

window.EMISFace.start(video, document.getElementById('faceDetectionStatus'), function (state) {
    faceReady = state.ready;
    faceDescriptor = state.ready ? window.EMISFace.getDescriptor() : null;
    updateCaptureAvailability();
}).catch(function () {
    faceReady = false;
    faceDescriptor = null;
    captureBtn.disabled = true;
});

if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
        position => {
            currentLatitude = position.coords.latitude;
            currentLongitude = position.coords.longitude;
            latDisplay.textContent = currentLatitude;
            lngDisplay.textContent = currentLongitude;
            gpsReady = true;
            statusAlert.className = 'alert alert-info text-center';
            statusAlert.textContent = 'GPS acquired. Waiting for your registered face to be detected...';
            updateCaptureAvailability();
        },
        () => {
            statusAlert.className = 'alert alert-danger text-center';
            statusAlert.textContent = 'GPS Error: Please enable location services.';
        },
        { enableHighAccuracy: true, timeout: 10000 }
    );
} else {
    statusAlert.className = 'alert alert-danger text-center';
    statusAlert.textContent = 'Geolocation is not supported by your browser.';
}

captureBtn.addEventListener('click', async () => {
    faceDescriptor = window.EMISFace.getDescriptor();
    if (!faceDescriptor) {
        statusAlert.className = 'alert alert-warning text-center';
        statusAlert.textContent = 'Your registered face must be detected before proceeding.';
        return;
    }
    captureBtn.disabled = true;
    captureBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing Time Out...';
    statusAlert.className = 'alert alert-info text-center';
    statusAlert.textContent = 'Snapping departure photo and recording time out...';

    try {
        if (!video.videoWidth || !video.videoHeight) {
            throw new Error('Camera is not ready. Please wait and try again.');
        }

        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        const photoDataUrl = canvas.toDataURL('image/jpeg', 0.85);

        const response = await fetch('process-time-out.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                event_id: <?= json_encode((string)$eventId) ?>,
                latitude: currentLatitude,
                longitude: currentLongitude,
                photo_data: photoDataUrl,
                face_descriptor: faceDescriptor
            })
        });

        const result = await response.json();
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Server rejected time out.');
        }

        statusAlert.className = 'alert alert-success text-center';
        statusAlert.textContent = 'Time Out Successful! Redirecting...';
        setTimeout(() => { window.location.href = 'dashboard.php'; }, 2000);
    } catch (err) {
        captureBtn.disabled = false;
        captureBtn.innerHTML = '<i class="bi bi-shield-check me-2"></i> Capture Face & Time Out';
        statusAlert.className = 'alert alert-danger text-center';
        statusAlert.textContent = 'Error: ' + err.message;
    }
});
</script>
</body>
</html>
