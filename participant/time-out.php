<?php
require_once '../includes/session.php';
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
        #videoElement { width: 100%; border-radius: 8px; background: #000; transform: scaleX(-1); }
        .attendance-scan-frame { position:absolute; inset:0; overflow:hidden; border-radius:8px; pointer-events:none; }
        .attendance-scan-frame:before { content:""; position:absolute; width:min(58%,250px); aspect-ratio:.78; left:50%; top:50%; transform:translate(-50%,-50%); border:2px solid rgba(167,139,250,.9); border-radius:48% 48% 43% 43%; box-shadow:0 0 0 999px rgba(10,5,20,.13),0 0 22px rgba(167,139,250,.4); }
        .attendance-scan-line { position:absolute; left:18%; right:18%; top:20%; height:2px; opacity:0; background:#a78bfa; box-shadow:0 0 12px 3px rgba(167,139,250,.8); }
        .attendance-scan-frame.scanning .attendance-scan-line { opacity:1; animation:attendanceScanSweep 1.5s ease-in-out infinite alternate; }
        @keyframes attendanceScanSweep { from { top:20%; } to { top:80%; } }
        .attendance-scan-percent { position:absolute; right:12px; bottom:12px; padding:6px 10px; border-radius:20px; background:rgba(12,8,22,.8); color:#fff; font-size:12px; font-weight:700; }
        #canvas { display: none; }
        @media (max-width: 600px) { .scanner-container { margin: 18px auto; padding: 18px; } #videoElement { max-height: 58vh; object-fit: cover; } }
        @media (max-width: 360px) { .scanner-container { margin: 12px auto; padding: 13px; } .scanner-container h3 { font-size: 1.15rem; } .scanner-container .alert { overflow-wrap: anywhere; } }
    </style>
<link rel="stylesheet" href="../css/style.css?v=20261117-5">
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
        <div class="position-relative mb-2"><video id="videoElement" autoplay playsinline></video><canvas id="canvas"></canvas><div id="attendanceScanFrame" class="attendance-scan-frame"><div class="attendance-scan-line"></div><span id="attendanceScanPercent" class="attendance-scan-percent">READY · 0%</span></div></div>
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

<script src="../js/zentrix-face-pose.js"></script>
<script>
const video=document.getElementById('videoElement'),canvas=document.getElementById('canvas'),captureBtn=document.getElementById('captureBtn'),statusAlert=document.getElementById('statusAlert'),faceDetectionStatus=document.getElementById('faceDetectionStatus'),latDisplay=document.getElementById('latDisplay'),lngDisplay=document.getElementById('lngDisplay'),stepLabel=document.getElementById('angleStepLabel'),progressLabel=document.getElementById('angleProgressLabel'),progressBar=document.getElementById('angleProgressBar'),instruction=document.getElementById('angleInstruction'),scanFrame=document.getElementById('attendanceScanFrame'),scanPercent=document.getElementById('attendanceScanPercent');
const eventId=<?= json_encode((string)$eventId) ?>,attendanceAction='time-out';
const angles=[{key:'front',label:'FRONT',prompt:'Look straight into the camera. Keep your head upright and face centered.'},{key:'left',label:'LEFT',prompt:'Now slowly turn your head to your LEFT. Keep your eyes visible.'},{key:'right',label:'RIGHT',prompt:'Now slowly turn your head to your RIGHT. Keep your eyes visible.'}];
let currentLatitude=null,currentLongitude=null,currentAccuracy=null,cameraStream=null,cameraReady=false,gpsReady=false,currentAngleIndex=0,isBusy=false,completed=false,scanStarted=false,scanFinished=false,challengeId=null;
function alertBox(el,message,type){el.className='alert alert-'+type+' text-center';el.textContent=message;}
function frame(){if(!cameraReady||!video.videoWidth)throw Error('Camera is not ready yet.');canvas.width=video.videoWidth;canvas.height=video.videoHeight;canvas.getContext('2d').drawImage(video,0,0,canvas.width,canvas.height);return canvas.toDataURL('image/jpeg',0.88);}
function wait(ms){return new Promise(resolve=>setTimeout(resolve,ms));}
function updateStep(){
if(!scanStarted){stepLabel.textContent='Ready to scan';progressLabel.textContent='0 / 3 verified';progressBar.style.width='0%';instruction.textContent='Press Start Face Scan once. Zentrix will guide you through front, left, and right automatically.';captureBtn.innerHTML='<i class="bi bi-play-circle me-2"></i> Start Face Scan';}
else if(currentAngleIndex<3){const angle=angles[currentAngleIndex];stepLabel.textContent='Step '+(currentAngleIndex+1)+' of 3 · '+angle.label;instruction.textContent=angle.prompt;captureBtn.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span> Scanning '+angle.label+'...';}
else{stepLabel.textContent='Identity verified · Evidence photo';progressLabel.textContent='3 / 3 verified';progressBar.style.width='100%';instruction.textContent='Face the camera directly again. Take your separate front-facing attendance evidence photo.';captureBtn.innerHTML='<i class="bi bi-camera-fill me-2"></i> Capture Evidence & Time Out';}
captureBtn.disabled=!(cameraReady&&gpsReady)||isBusy||completed|| (scanStarted&&!scanFinished&&currentAngleIndex===0);
}
async function startCamera(){try{if(!navigator.mediaDevices?.getUserMedia)throw Error('Camera access is not supported by this browser.');cameraStream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'user',width:{ideal:960},height:{ideal:720}},audio:false});video.srcObject=cameraStream;await video.play();cameraReady=true;alertBox(faceDetectionStatus,'Camera ready. Press Start Face Scan once and follow the prompts.','success');updateStep();if(gpsReady)alertBox(statusAlert,'Camera and GPS are ready. Start your guided face scan.','success');}catch(e){alertBox(faceDetectionStatus,e.message||'Unable to access the camera.','danger');}}
if(navigator.geolocation){navigator.geolocation.getCurrentPosition(p=>{currentLatitude=p.coords.latitude;currentLongitude=p.coords.longitude;currentAccuracy=p.coords.accuracy;latDisplay.textContent=currentLatitude;lngDisplay.textContent=currentLongitude;gpsReady=true;updateStep();if(cameraReady)alertBox(statusAlert,'Camera and GPS are ready. Start your guided face scan.','success');},()=>alertBox(statusAlert,'GPS Error: Please enable location services.','danger'),{enableHighAccuracy:true,timeout:15000,maximumAge:0});}else alertBox(statusAlert,'Geolocation is not supported by your browser.','danger');
async function runFaceScan(){
scanStarted=true;isBusy=true;updateStep();
try{
alertBox(statusAlert,'Starting a secure attendance verification session...','info');
const challengeResponse=await fetch('start-face-verification.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({event_id:eventId,action:attendanceAction})});
const challengeResult=await challengeResponse.json();
if(!challengeResponse.ok||!challengeResult.success||!challengeResult.challenge_id)throw Error(challengeResult.message||'Unable to start secure face verification.');
challengeId=challengeResult.challenge_id;
for(let i=0;i<angles.length;i++){
const angle=angles[i];currentAngleIndex=i;updateStep();alertBox(statusAlert,'Turn to '+angle.label.toLowerCase()+'. The scan will capture automatically at 100%.','info');scanFrame.classList.add('scanning');
await window.ZentrixFacePose.captureAngle(video,angle.key,(percent)=>{scanPercent.textContent='POSE CHECK · '+percent+'%';progressLabel.textContent=angle.label+' · '+percent+'%';progressBar.style.width=((i*100+percent)/3)+'%';});
const imageData=frame();alertBox(statusAlert,'Sending '+angle.label.toLowerCase()+' scan for identity and liveness verification...','info');
const r=await fetch('verify-face-angle.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({event_id:eventId,action:attendanceAction,angle:angle.key,image_data:imageData,challenge_id:challengeId})});const result=await r.json();if(!r.ok||!result.success)throw Error(result.message||angle.label+' face verification failed.');
currentAngleIndex=i+1;scanFrame.classList.remove('scanning');scanPercent.textContent='VERIFIED · 100%';progressLabel.textContent=(i+1)+' / 3 verified';progressBar.style.width=((i+1)/3*100)+'%';alertBox(statusAlert,result.message||angle.label+' angle verified.','success');await wait(650);
}
scanFinished=true;alertBox(statusAlert,'All three face angles verified. Take your separate front-facing evidence photo to finish.','success');
}catch(error){scanStarted=false;currentAngleIndex=0;scanFinished=false;challengeId=null;scanFrame.classList.remove('scanning');scanPercent.textContent='RETRY · 0%';progressBar.style.width='0%';progressLabel.textContent='0 / 3 verified';alertBox(statusAlert,error.message||'Face scan failed. Press Start Face Scan to retry.','danger');}
finally{isBusy=false;updateStep();}
}
async function uploadAttendanceEvidence(dataUrl){
const blob=await (await fetch(dataUrl)).blob();
if(blob.size>2*1024*1024)throw Error('The attendance photo is too large. Please try again.');
const preparedResponse=await fetch('create-attendance-upload.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({event_id:eventId,action:attendanceAction})});
const prepared=await preparedResponse.json();
if(!preparedResponse.ok||!prepared.success||!prepared.signed_url||!prepared.path)throw Error(prepared.message||'Unable to prepare secure photo storage.');
const uploadResponse=await fetch(prepared.signed_url,{method:'PUT',headers:{'Content-Type':'image/jpeg','x-upsert':'false'},body:blob});
if(!uploadResponse.ok)throw Error('The attendance photo could not be uploaded securely. Please try again.');
return prepared.path;
}
captureBtn.addEventListener('click',async()=>{
if(!cameraReady||!gpsReady||isBusy||completed)return;
if(!scanFinished){if(!scanStarted)await runFaceScan();return;}
isBusy=true;updateStep();
try{const evidencePhoto=frame();alertBox(statusAlert,'Uploading your separate front-facing evidence photo securely...','info');const photoPath=await uploadAttendanceEvidence(evidencePhoto);const r=await fetch('process-time-out.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({event_id:eventId,latitude:currentLatitude,longitude:currentLongitude,accuracy:currentAccuracy,photo_path:photoPath})});const result=await r.json();if(!r.ok||!result.success)throw Error(result.message||'Server rejected time-out.');completed=true;alertBox(statusAlert,'Time Out successful! Redirecting...','success');captureBtn.innerHTML='<i class="bi bi-check-circle me-2"></i> Completed';setTimeout(()=>{window.location.href='dashboard.php';},2000);}
catch(error){alertBox(statusAlert,error.message||'Time Out failed. Please try again.','danger');}
finally{isBusy=false;updateStep();}
});
window.addEventListener('beforeunload',()=>{if(cameraStream)cameraStream.getTracks().forEach(t=>t.stop());});startCamera();
</script>
<link rel="stylesheet" href="../css/participant-content-refresh.css?v=20261118-premium1">
</body>
</html>
