<?php
session_start();
date_default_timezone_set('Asia/Manila');
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/daily-attendance.php';

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
$dailyMode = isMultiDayAttendanceEvent($event);
$attendanceLookup = $dailyMode
    ? 'daily_attendance?event_id=eq.' . rawurlencode($eventId) . '&participant_id=eq.' . rawurlencode($participantId) . '&attendance_date=eq.' . rawurlencode($now->format('Y-m-d')) . '&select=id,time_out'
    : 'attendance?event_id=eq.' . rawurlencode($eventId) . '&participant_id=eq.' . rawurlencode($participantId) . '&select=id,time_out';
$checkExisting = $dailyMode
    ? supabaseServiceRequest($attendanceLookup, 'GET')
    : supabaseRequest($attendanceLookup, 'GET');

if (($checkExisting['code'] ?? 500) !== 200 || empty($checkExisting['data'])) {
    die("Error: You must check in first before you can time out.");
}

if (!empty($checkExisting['data'][0]['time_out'])) {
    die("Error: You have already timed out for this event.");
}

$faceProfileCheck = supabaseServiceRequest(
    'participant_face_profiles?participant_id=eq.' . rawurlencode((string)$participantId) . '&select=participant_id',
    'GET'
);
if (($faceProfileCheck['code'] ?? 500) >= 400) {
    die("Face verification is temporarily unavailable. Please contact the administrator.");
}
if (empty($faceProfileCheck['data'])) {
    die('Please <a href="face-registration.php">register your face</a> before timing out.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Time Out - Zentrix</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8f9fa; }
        .scanner-container { max-width: 600px; margin: 40px auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        #videoElement { width: 100%; border-radius: 8px; background: #000; }
        #canvas { display: none; }
        @media (max-width: 600px) { .scanner-container { margin: 18px auto; padding: 18px; } #videoElement { max-height: 58vh; object-fit: cover; } }
        @media (max-width: 360px) { .scanner-container { margin: 12px auto; padding: 13px; } .scanner-container h3 { font-size: 1.15rem; } .scanner-container .alert { overflow-wrap: anywhere; } }
    </style>
<link rel="stylesheet" href="../css/style.css?v=20261117-1">
</head>
<body class="participant-page">
<?php include '../includes/participant-nav.php'; ?>

<div class="container">
    <div class="scanner-container">
        <h3 class="mb-3 text-center text-warning text-dark"><i class="bi bi-box-arrow-left me-2"></i>Face & GPS Time Out</h3>
        <p class="text-muted text-center small mb-4">Position your face clearly in front of the camera to log your departure. You must be inside the marked venue or within 50 meters outside its boundary, with GPS accuracy of 50 meters or better.</p>
        <div id="statusAlert" class="alert alert-info text-center" role="alert">
            Initializing camera and requesting GPS coordinates...
        </div>
        <div id="faceDetectionStatus" class="alert alert-info text-center">Preparing camera and multi-angle verification...</div>
        <div class="mb-2 d-flex justify-content-between align-items-center"><span class="fw-semibold" id="angleStepLabel">Step 1 of 3 · Front</span><span class="small text-muted" id="angleProgressLabel">0 / 3 verified</span></div>
        <div class="progress mb-3" style="height:8px"><div id="angleProgressBar" class="progress-bar" style="width:0%"></div></div>
        <div class="position-relative mb-2"><video id="videoElement" autoplay playsinline></video><canvas id="canvas"></canvas></div>
        <p class="small text-muted text-center mb-3" id="angleInstruction">Look directly at the camera and keep your face centered.</p>
        <div class="mb-3 p-3 bg-light rounded border small">
            <div><strong>Latitude:</strong> <span id="latDisplay">Acquiring...</span></div>
            <div><strong>Longitude:</strong> <span id="lngDisplay">Acquiring...</span></div>
        </div>
        <button id="captureBtn" class="btn btn-warning fw-bold w-100 py-2" disabled><i class="bi bi-camera-fill me-2"></i> Capture Front Angle</button>
        <div class="text-center mt-3">
            
        </div>
    </div>
</div>

<script>
const video=document.getElementById('videoElement'),canvas=document.getElementById('canvas'),captureBtn=document.getElementById('captureBtn'),statusAlert=document.getElementById('statusAlert'),faceDetectionStatus=document.getElementById('faceDetectionStatus'),latDisplay=document.getElementById('latDisplay'),lngDisplay=document.getElementById('lngDisplay'),stepLabel=document.getElementById('angleStepLabel'),progressLabel=document.getElementById('angleProgressLabel'),progressBar=document.getElementById('angleProgressBar'),instruction=document.getElementById('angleInstruction');
const eventId=<?= json_encode((string)$eventId) ?>,attendanceAction='time-out';
const angles=[{key:'front',label:'Front',prompt:'Look directly at the camera and keep your face centered.'},{key:'left',label:'Left',prompt:'Slowly turn your head to your left. Keep your face visible.'},{key:'right',label:'Right',prompt:'Slowly turn your head to your right. Keep your face visible.'}];
let currentLatitude=null,currentLongitude=null,currentAccuracy=null,cameraStream=null,cameraReady=false,gpsReady=false,currentAngleIndex=0,isBusy=false,completed=false;
function alertBox(el,message,type){el.className='alert alert-'+type+' text-center';el.textContent=message;}
function frame(){if(!cameraReady||!video.videoWidth)throw Error('Camera is not ready yet.');canvas.width=video.videoWidth;canvas.height=video.videoHeight;canvas.getContext('2d').drawImage(video,0,0,canvas.width,canvas.height);return canvas.toDataURL('image/jpeg',0.88);}
function updateStep(){
if(currentAngleIndex<3){const angle=angles[currentAngleIndex];stepLabel.textContent='Step '+(currentAngleIndex+1)+' of 3 · '+angle.label;progressLabel.textContent=currentAngleIndex+' / 3 verified';progressBar.style.width=(currentAngleIndex/3*100)+'%';instruction.textContent=angle.prompt;captureBtn.innerHTML='<i class="bi bi-camera-fill me-2"></i> Capture '+angle.label+' Angle';}
else{stepLabel.textContent='Identity verified · Evidence photo';progressLabel.textContent='3 / 3 verified';progressBar.style.width='100%';instruction.textContent='Face the camera directly again. Capture your separate attendance evidence photo.';captureBtn.innerHTML='<i class="bi bi-camera-fill me-2"></i> Capture Evidence & Time Out';}
captureBtn.disabled=!(cameraReady&&gpsReady)||isBusy||completed;
}
async function startCamera(){try{if(!navigator.mediaDevices?.getUserMedia)throw Error('Camera access is not supported by this browser.');cameraStream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'user',width:{ideal:960},height:{ideal:720}},audio:false});video.srcObject=cameraStream;await video.play();cameraReady=true;alertBox(faceDetectionStatus,'Camera ready. Follow the front, left, and right prompts.','success');updateStep();if(gpsReady)alertBox(statusAlert,'Camera and GPS are ready. Complete all three face angles.','success');}catch(e){alertBox(faceDetectionStatus,e.message||'Unable to access the camera.','danger');}}
if(navigator.geolocation){navigator.geolocation.getCurrentPosition(p=>{currentLatitude=p.coords.latitude;currentLongitude=p.coords.longitude;currentAccuracy=p.coords.accuracy;latDisplay.textContent=currentLatitude;lngDisplay.textContent=currentLongitude;gpsReady=true;updateStep();if(cameraReady)alertBox(statusAlert,'Camera and GPS are ready. Complete all three face angles.','success');},()=>alertBox(statusAlert,'GPS Error: Please enable location services.','danger'),{enableHighAccuracy:true,timeout:15000,maximumAge:0});}else alertBox(statusAlert,'Geolocation is not supported by your browser.','danger');
captureBtn.addEventListener('click',async()=>{if(!cameraReady||!gpsReady||isBusy||completed)return;isBusy=true;updateStep();try{
if(currentAngleIndex<3){const angle=angles[currentAngleIndex],imageData=frame();alertBox(statusAlert,'Verifying '+angle.label.toLowerCase()+' face angle with Zentrix...','info');const r=await fetch('verify-face-angle.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({event_id:eventId,action:attendanceAction,angle:angle.key,image_data:imageData})});const result=await r.json();if(!r.ok||!result.success)throw Error(result.message||angle.label+' face verification failed.');currentAngleIndex++;updateStep();alertBox(statusAlert,result.message||angle.label+' angle verified. Continue to the next position.','success');}
else{const evidencePhoto=frame();alertBox(statusAlert,'All three angles passed. Submitting your separate front-facing evidence photo...','info');const r=await fetch('process-time-out.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({event_id:eventId,latitude:currentLatitude,longitude:currentLongitude,accuracy:currentAccuracy,photo_data:evidencePhoto})});const result=await r.json();if(!r.ok||!result.success)throw Error(result.message||'Server rejected time-out.');completed=true;alertBox(statusAlert,'Time-out successful! Redirecting...','success');captureBtn.innerHTML='<i class="bi bi-check-circle me-2"></i> Completed';setTimeout(()=>{window.location.href='dashboard.php';},2000);}
}catch(error){alertBox(statusAlert,error.message||'Verification failed. Please try again.','danger');}
finally{isBusy=false;updateStep();}
});
window.addEventListener('beforeunload',()=>{if(cameraStream)cameraStream.getTracks().forEach(t=>t.stop());});startCamera();
</script>
</body>
</html>
