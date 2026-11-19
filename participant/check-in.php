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
        <p class="text-muted text-center small mb-4">Position your face clearly in front of the camera. You must be inside the marked venue or within 50 meters outside its boundary, with GPS accuracy of 50 meters or better.</p>

        <!-- Status Alert Area -->
        <div id="statusAlert" class="alert alert-info text-center" role="alert">
            Initializing camera and requesting GPS coordinates...
        </div>

        <!-- Camera Viewport -->
        <div id="faceDetectionStatus" class="alert alert-info text-center">Preparing camera and multi-angle verification...</div>
        <div class="mb-2 d-flex justify-content-between align-items-center"><span class="fw-semibold" id="angleStepLabel">Step 1 of 3 · Front</span><span class="small text-muted" id="angleProgressLabel">0 / 3 verified</span></div>
        <div class="progress mb-3" style="height:8px"><div id="angleProgressBar" class="progress-bar" style="width:0%"></div></div>
        <div class="position-relative mb-2"><video id="videoElement" autoplay playsinline></video><canvas id="canvas"></canvas><div id="attendanceScanFrame" class="attendance-scan-frame"><div class="attendance-scan-line"></div><span id="attendanceScanPercent" class="attendance-scan-percent">READY · 0%</span></div></div>
        <p class="small text-muted text-center mb-3" id="angleInstruction">Look directly at the camera and keep your face centered.</p>

        <!-- Location Data Preview -->
        <div class="mb-3 p-3 bg-light rounded border small">
            <div><strong>Latitude:</strong> <span id="latDisplay">Acquiring...</span></div>
            <div><strong>Longitude:</strong> <span id="lngDisplay">Acquiring...</span></div>
            <div><strong>GPS accuracy:</strong> <span id="accuracyDisplay">Waiting for GPS...</span></div>
            <div class="mt-2 text-muted" id="gpsQualityMessage" aria-live="polite">Collecting location readings to find the most accurate fix...</div>
            <button type="button" id="retryGpsBtn" class="btn btn-outline-secondary btn-sm mt-2"><i class="bi bi-crosshair me-1"></i> Refresh GPS Location</button>
        </div>

        <!-- Action Button -->
        <button id="captureBtn" class="btn btn-primary fw-bold w-100 py-2" disabled><i class="bi bi-camera-fill me-2"></i> Capture Front Angle</button>

        <div class="text-center mt-3">
            
        </div>
        </div>
    <?php endif; ?>
</main>

<?php if (!$faceRegistrationRequired): ?>
<script src="../js/zentrix-face-pose.js"></script>
<script>
const video=document.getElementById('videoElement'),canvas=document.getElementById('canvas'),captureBtn=document.getElementById('captureBtn'),statusAlert=document.getElementById('statusAlert'),faceDetectionStatus=document.getElementById('faceDetectionStatus'),latDisplay=document.getElementById('latDisplay'),lngDisplay=document.getElementById('lngDisplay'),stepLabel=document.getElementById('angleStepLabel'),progressLabel=document.getElementById('angleProgressLabel'),progressBar=document.getElementById('angleProgressBar'),instruction=document.getElementById('angleInstruction'),scanFrame=document.getElementById('attendanceScanFrame'),scanPercent=document.getElementById('attendanceScanPercent');
const eventId=<?= json_encode((string)$eventId) ?>,attendanceAction='check-in';
const angles=[{key:'front',label:'FRONT',prompt:'Look straight into the camera. Keep your head upright and face centered.'},{key:'left',label:'LEFT',prompt:'Now slowly turn your head to your LEFT. Keep your eyes visible.'},{key:'right',label:'RIGHT',prompt:'Now slowly turn your head to your RIGHT. Keep your eyes visible.'}];
let currentLatitude=null,currentLongitude=null,currentAccuracy=null,cameraStream=null,cameraReady=false,gpsReady=false,currentAngleIndex=0,isBusy=false,completed=false,scanStarted=false,scanFinished=false,challengeId=null;
function alertBox(el,message,type){el.className='alert alert-'+type+' text-center';el.textContent=message;}
function frame(){if(!cameraReady||!video.videoWidth)throw Error('Camera is not ready yet.');canvas.width=video.videoWidth;canvas.height=video.videoHeight;canvas.getContext('2d').drawImage(video,0,0,canvas.width,canvas.height);return canvas.toDataURL('image/jpeg',0.88);}
function wait(ms){return new Promise(resolve=>setTimeout(resolve,ms));}
function updateStep(){
if(!scanStarted){stepLabel.textContent='Ready to scan';progressLabel.textContent='0 / 3 verified';progressBar.style.width='0%';instruction.textContent='Press Start Face Scan once. Zentrix will guide you through front, left, and right automatically.';captureBtn.innerHTML='<i class="bi bi-play-circle me-2"></i> Start Face Scan';}
else if(currentAngleIndex<3){const angle=angles[currentAngleIndex];stepLabel.textContent='Step '+(currentAngleIndex+1)+' of 3 · '+angle.label;instruction.textContent=angle.prompt;captureBtn.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span> Scanning '+angle.label+'...';}
else{stepLabel.textContent='Identity verified · Evidence photo';progressLabel.textContent='3 / 3 verified';progressBar.style.width='100%';instruction.textContent='Face the camera directly again. Take your separate front-facing attendance evidence photo.';captureBtn.innerHTML='<i class="bi bi-camera-fill me-2"></i> Capture Evidence & Check In';}
captureBtn.disabled=!(cameraReady&&gpsReady)||isBusy||completed|| (scanStarted&&!scanFinished&&currentAngleIndex===0);
}
async function startCamera(){try{if(!navigator.mediaDevices?.getUserMedia)throw Error('Camera access is not supported by this browser.');cameraStream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'user',width:{ideal:960},height:{ideal:720}},audio:false});video.srcObject=cameraStream;await video.play();cameraReady=true;alertBox(faceDetectionStatus,'Camera ready. Press Start Face Scan once and follow the prompts.','success');updateStep();if(gpsReady)alertBox(statusAlert,'Camera and GPS are ready. Start your guided face scan.','success');}catch(e){alertBox(faceDetectionStatus,e.message||'Unable to access the camera.','danger');}}
const accuracyDisplay=document.getElementById('accuracyDisplay'),gpsQualityMessage=document.getElementById('gpsQualityMessage'),retryGpsBtn=document.getElementById('retryGpsBtn');
let gpsWatchId=null,gpsAcquisitionTimer=null,gpsAcquisitionStarted=0,bestGpsPosition=null,gpsAcquiring=false;
function stopGpsAcquisition(){if(gpsWatchId!==null){navigator.geolocation.clearWatch(gpsWatchId);gpsWatchId=null;}if(gpsAcquisitionTimer){clearTimeout(gpsAcquisitionTimer);gpsAcquisitionTimer=null;}gpsAcquiring=false;retryGpsBtn.disabled=false;}
function finishGpsAcquisition(){
stopGpsAcquisition();
if(!bestGpsPosition||bestGpsPosition.coords.accuracy>50){
gpsReady=false;currentLatitude=null;currentLongitude=null;currentAccuracy=null;
accuracyDisplay.textContent=bestGpsPosition?'±'+Math.round(bestGpsPosition.coords.accuracy)+' m':'Unavailable';
gpsQualityMessage.textContent='GPS accuracy is still worse than 50 m. Move near a window or outdoors, keep location services enabled, then refresh GPS.';
alertBox(statusAlert,'GPS signal is not accurate enough yet. Refresh your location and try again.','warning');
updateStep();return;
}
const c=bestGpsPosition.coords;
currentLatitude=c.latitude;currentLongitude=c.longitude;currentAccuracy=c.accuracy;gpsReady=true;
latDisplay.textContent=currentLatitude.toFixed(6);lngDisplay.textContent=currentLongitude.toFixed(6);
accuracyDisplay.textContent='±'+Math.round(currentAccuracy)+' m';
gpsQualityMessage.textContent=currentAccuracy<=20?'Good GPS fix. Best reading collected.': 'Usable GPS fix. For better accuracy, try near a window or outdoors.';
updateStep();
if(cameraReady)alertBox(statusAlert,'Camera and GPS are ready. Start your guided face scan.','success');
}
function acquireGpsLocation(){
if(!navigator.geolocation){alertBox(statusAlert,'Geolocation is not supported by this browser.','danger');return;}
stopGpsAcquisition();gpsReady=false;bestGpsPosition=null;gpsAcquiring=true;retryGpsBtn.disabled=true;
currentLatitude=null;currentLongitude=null;currentAccuracy=null;
latDisplay.textContent='Searching...';lngDisplay.textContent='Searching...';accuracyDisplay.textContent='Collecting readings...';
gpsQualityMessage.textContent='Keep this page open. Zentrix is comparing location updates for up to 20 seconds.';
alertBox(statusAlert,'Finding your most accurate GPS reading. This may take up to 20 seconds...','info');updateStep();
gpsAcquisitionStarted=Date.now();
gpsWatchId=navigator.geolocation.watchPosition(position=>{
if(!bestGpsPosition||position.coords.accuracy<bestGpsPosition.coords.accuracy)bestGpsPosition=position;
const c=position.coords;
latDisplay.textContent=c.latitude.toFixed(6);lngDisplay.textContent=c.longitude.toFixed(6);
accuracyDisplay.textContent='±'+Math.round(c.accuracy)+' m (best: ±'+Math.round(bestGpsPosition.coords.accuracy)+' m)';
gpsQualityMessage.textContent=c.accuracy<=20?'Strong GPS reading found. Confirming the best fix...':'Improving GPS reading... keep the page open.';
if(Date.now()-gpsAcquisitionStarted>=10000&&bestGpsPosition.coords.accuracy<=20)finishGpsAcquisition();
},error=>{
if(!bestGpsPosition){stopGpsAcquisition();accuracyDisplay.textContent='Unavailable';gpsQualityMessage.textContent=error.code===1?'Location permission is blocked. Allow location access for this site.':'Unable to obtain a GPS reading. Check device location services and try again.';alertBox(statusAlert,gpsQualityMessage.textContent,'danger');updateStep();}
},{enableHighAccuracy:true,timeout:30000,maximumAge:0});
gpsAcquisitionTimer=setTimeout(finishGpsAcquisition,20000);
}
retryGpsBtn.addEventListener('click',acquireGpsLocation);
acquireGpsLocation();
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
try{const evidencePhoto=frame();alertBox(statusAlert,'Uploading your separate front-facing evidence photo securely...','info');const photoPath=await uploadAttendanceEvidence(evidencePhoto);const r=await fetch('process-check-in.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({event_id:eventId,latitude:currentLatitude,longitude:currentLongitude,accuracy:currentAccuracy,photo_path:photoPath})});const result=await r.json();if(!r.ok||!result.success)throw Error(result.message||'Server rejected check-in.');completed=true;alertBox(statusAlert,'Check In successful! Redirecting...','success');captureBtn.innerHTML='<i class="bi bi-check-circle me-2"></i> Completed';setTimeout(()=>{window.location.href='dashboard.php';},2000);}
catch(error){alertBox(statusAlert,error.message||'Check In failed. Please try again.','danger');}
finally{isBusy=false;updateStep();}
});
window.addEventListener('beforeunload',()=>{if(cameraStream)cameraStream.getTracks().forEach(t=>t.stop());});startCamera();
</script>
<?php endif; ?>
<link rel="stylesheet" href="../css/participant-content-refresh.css?v=20261118-premium1">
</body>
</html>