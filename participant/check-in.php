<?php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

// Ensure the user is a logged-in participant
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role']) !== 'participant') {
    header("Location: ../login.php");
    exit;
}

// In a real scenario, you'd pass the event ID via URL, e.g., check-in.php?event_id=123
$eventId = $_GET['event_id'] ?? ''; 

if (empty($eventId)) {
    die("Error: No event specified for check-in.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Check-In - EMIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        #video-feed { width: 100%; max-width: 400px; border-radius: 8px; background: #000; }
        #canvas { display: none; }
    </style>
</head>
<body class="bg-light">

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6 text-center">
            <div class="card shadow-sm">
                <div class="card-header bg-dark text-white">
                    <h4 class="mb-0">Secure Check-In</h4>
                </div>
                <div class="card-body p-4">
                    <p class="text-muted mb-4">Please grant camera and location permissions to check in.</p>
                    
                    <!-- Camera Feed -->
                    <video id="video-feed" autoplay playsinline></video>
                    <canvas id="canvas"></canvas>
                    
                    <div id="status-message" class="mt-3"></div>

                    <button id="checkin-btn" class="btn btn-primary w-100 mt-4 py-2" disabled>
                        <i class="bi bi-camera me-2"></i> Capture Face & Check In
                    </button>
                    
                    <input type="hidden" id="event-id" value="<?= htmlspecialchars($eventId) ?>">
                    <input type="hidden" id="participant-id" value="<?= htmlspecialchars($_SESSION['user_id']) ?>">
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const video = document.getElementById('video-feed');
    const canvas = document.getElementById('canvas');
    const checkinBtn = document.getElementById('checkin-btn');
    const statusMsg = document.getElementById('status-message');
    let userLocation = null;

    // 1. Initialize Camera
    async function startCamera() {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "user" } });
            video.srcObject = stream;
        } catch (err) {
            statusMsg.innerHTML = '<div class="alert alert-danger">Error accessing camera: ' + err.message + '</div>';
        }
    }

    // 2. Get Location
    function getLocation() {
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    userLocation = {
                        lat: position.coords.latitude,
                        lng: position.coords.longitude
                    };
                    checkinBtn.disabled = false; // Enable button once location is ready
                },
                (err) => {
                    statusMsg.innerHTML = '<div class="alert alert-warning">Location access required for check-in.</div>';
                }
            );
        } else {
            statusMsg.innerHTML = '<div class="alert alert-danger">Geolocation is not supported by this browser.</div>';
        }
    }

    // 3. Handle Check-In Click
    checkinBtn.addEventListener('click', () => {
        if (!userLocation) return;
        
        checkinBtn.disabled = true;
        checkinBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Processing...';

        // Capture snapshot from video
        const context = canvas.getContext('2d');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        context.drawImage(video, 0, 0, canvas.width, canvas.height);
        
        // Convert image to base64 data URL
        const photoData = canvas.toDataURL('image/jpeg', 0.8);
        const eventId = document.getElementById('event-id').value;
        const participantId = document.getElementById('participant-id').value;

        // Send data to PHP backend
        fetch('../api/attendance/record-checkin.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                event_id: eventId,
                participant_id: participantId,
                photo: photoData,
                latitude: userLocation.lat,
                longitude: userLocation.lng
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                statusMsg.innerHTML = '<div class="alert alert-success">Check-in successful!</div>';
                // Stop camera
                video.srcObject.getTracks().forEach(track => track.stop());
                checkinBtn.style.display = 'none';
            } else {
                statusMsg.innerHTML = '<div class="alert alert-danger">Error: ' + data.error + '</div>';
                checkinBtn.disabled = false;
                checkinBtn.innerHTML = '<i class="bi bi-camera me-2"></i> Try Again';
            }
        })
        .catch(err => {
            statusMsg.innerHTML = '<div class="alert alert-danger">Network error occurred.</div>';
            checkinBtn.disabled = false;
            checkinBtn.innerHTML = '<i class="bi bi-camera me-2"></i> Try Again';
        });
    });

    // Start process on load
    startCamera();
    getLocation();
</script>

</body>
</html>