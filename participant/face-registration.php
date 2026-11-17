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
    'participant_face_profiles?participant_id=eq.' . rawurlencode((string)$_SESSION['user_id']) . '&select=participant_id,updated_at,face_front_descriptor,face_left_descriptor,face_right_descriptor',
    'GET'
);
$existingProfile = (($existingFaceResponse['code'] ?? 500) < 400) ? ($existingFaceResponse['data'][0] ?? null) : null;
$hasRegisteredFace = $existingProfile !== null;
$hasMultiAngleFace = $hasRegisteredFace
    && $existingProfile['face_front_descriptor'] !== null
    && $existingProfile['face_left_descriptor'] !== null
    && $existingProfile['face_right_descriptor'] !== null;
$needsFaceUpgrade = $hasRegisteredFace && !$hasMultiAngleFace;
$lastUpdated = $hasRegisteredFace ? ($existingProfile['updated_at'] ?? '') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Face Registration - Zentrix</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../css/style.css?v=20261117-1">
    <style>
        .face-registration-card { max-width: 720px; margin: 35px auto; padding: 28px; background: #fff; border-radius: 14px; box-shadow: 0 4px 18px rgba(15,23,42,.07); }
        #faceRegistrationVideo { width: 100%; max-height: 420px; background: #111827; border-radius: 12px; object-fit: cover; transform: scaleX(-1); }
        .face-scan-frame { position: absolute; inset: 0; overflow: hidden; border-radius: 12px; pointer-events: none; }
        .face-scan-frame:before { content: ""; position: absolute; width: min(58%, 250px); aspect-ratio: .78; left: 50%; top: 50%; transform: translate(-50%,-50%); border: 2px solid rgba(167,139,250,.9); border-radius: 48% 48% 43% 43%; box-shadow: 0 0 0 999px rgba(10,5,20,.13),0 0 22px rgba(167,139,250,.4); }
        .face-scan-line { position: absolute; left: 18%; right: 18%; top: 20%; height: 2px; opacity: 0; background: #a78bfa; box-shadow: 0 0 12px 3px rgba(167,139,250,.8); }
        .face-scan-frame.scanning .face-scan-line { opacity: 1; animation: faceScanSweep 1.5s ease-in-out infinite alternate; }
        @keyframes faceScanSweep { from { top: 20%; } to { top: 80%; } }
        .face-scan-percent { position: absolute; right: 12px; bottom: 12px; padding: 6px 10px; border-radius: 20px; background: rgba(12,8,22,.8); color: #fff; font-size: 12px; font-weight: 700; }
        .face-privacy-note { font-size: .9rem; color: #64748b; }
        @media (max-width: 600px) { .face-registration-card { margin: 18px auto; padding: 18px; } #faceRegistrationVideo { max-height: 58vh; } }
        @media (max-width: 360px) { .face-registration-card { margin: 12px auto; padding: 13px; border-radius: 11px; } .face-registration-card .d-flex.align-items-center { align-items: flex-start !important; gap: 10px !important; } .face-registration-card .btn { width: 100%; white-space: normal; } }
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

        <?php if ($hasMultiAngleFace): ?>
            <div class="alert alert-success">Your front, left, and right face templates are registered. Duplicate registration is disabled.</div>
        <?php elseif ($needsFaceUpgrade): ?>
            <div class="alert alert-warning">Your account has an older single-angle face template. Complete this three-angle upgrade to use the new attendance verification. Your existing attendance history will remain unchanged.</div>
        <?php else: ?>
            <div class="alert alert-info">You have not registered a face yet. Register before using face-based attendance.</div>
        <?php endif; ?>

        <?php if (!$hasMultiAngleFace): ?>
        <div id="faceRegistrationStatus" class="alert alert-info text-center">Preparing camera...</div>
        <div class="mb-2 d-flex justify-content-between align-items-center">
            <span class="fw-semibold" id="faceRegistrationStep">Step 1 of 3 · Front</span>
            <span class="small text-muted" id="faceRegistrationProgress">0 / 3 captured</span>
        </div>
        <div class="progress mb-3" style="height:8px"><div id="faceRegistrationProgressBar" class="progress-bar" style="width:0%"></div></div>
        <div class="position-relative mb-2"><video id="faceRegistrationVideo" autoplay muted playsinline></video><div id="faceScanFrame" class="face-scan-frame"><div class="face-scan-line"></div><span id="faceScanPercent" class="face-scan-percent">READY · 0%</span></div></div>
        <p class="small text-muted mt-2 mb-3" id="faceRegistrationInstruction">Look directly at the camera and keep your face centered.</p>

        <div class="form-check my-3">
            <input class="form-check-input" type="checkbox" id="faceConsent">
            <label class="form-check-label" for="faceConsent">
                I consent to Zentrix storing a biometric face template linked to my account and using it only to verify my identity for event check-in and time-out.
            </label>
        </div>

        <p class="face-privacy-note">Zentrix stores a mathematical face template rather than using your registration photo as the matching record. You can request assistance from the administrator if you want your face registration removed. Face matching can make mistakes and does not by itself prove that a live person is present.</p>

        <button type="button" id="saveFaceButton" class="btn btn-primary w-100" disabled><i class="bi bi-camera-fill me-2"></i> Capture Front Angle</button>
        <?php else: ?>
        <div class="alert alert-secondary mb-0">Your three-angle face registration is complete. It cannot be replaced from this page.</div>
        <?php endif; ?>
    </section>
</main>

<script>
const faceVideo=document.getElementById('faceRegistrationVideo');
if(faceVideo){
const faceStatus=document.getElementById('faceRegistrationStatus'),consentCheckbox=document.getElementById('faceConsent'),saveFaceButton=document.getElementById('saveFaceButton');
const stepLabel=document.getElementById('faceRegistrationStep'),progressLabel=document.getElementById('faceRegistrationProgress'),progressBar=document.getElementById('faceRegistrationProgressBar'),instruction=document.getElementById('faceRegistrationInstruction'),scanFrame=document.getElementById('faceScanFrame'),scanPercent=document.getElementById('faceScanPercent');
const csrfToken=<?= json_encode($_SESSION['face_registration_csrf']) ?>;
const angles=[{key:'front',label:'FRONT',prompt:'Look straight into the camera. Keep your head upright and face centered.'},{key:'left',label:'LEFT',prompt:'Now slowly turn your head to your LEFT. Keep your eyes visible.'},{key:'right',label:'RIGHT',prompt:'Now slowly turn your head to your RIGHT. Keep your eyes visible.'}];
let cameraStream=null,cameraReady=false,registrationSaved=false,scanStarted=false,scanBusy=false;const angleImages={};
function status(message,type='info'){faceStatus.textContent=message;faceStatus.className='alert alert-'+type+' text-center';}
function updateReady(){saveFaceButton.disabled=!cameraReady||!consentCheckbox.checked||scanBusy||registrationSaved; if(!scanStarted){saveFaceButton.innerHTML='<i class="bi bi-play-circle me-2"></i> Start Automatic Face Scan';}}
function wait(ms){return new Promise(resolve=>setTimeout(resolve,ms));}
async function scanProgress(angle,index){
stepLabel.textContent='Scanning '+angle.label+' · '+(index+1)+' of 3';instruction.textContent=angle.prompt;status('Position your head now. Scanning will capture automatically when the progress reaches 100%.','info');scanFrame.classList.add('scanning');
for(let percent=0;percent<=100;percent+=2){scanPercent.textContent='SCANNING · '+percent+'%';progressLabel.textContent=angle.label+' · '+percent+'%';progressBar.style.width=((index*100+percent)/3)+'%';await wait(45);}
const c=document.createElement('canvas');c.width=faceVideo.videoWidth;c.height=faceVideo.videoHeight;c.getContext('2d').drawImage(faceVideo,0,0,c.width,c.height);angleImages[angle.key.toLowerCase()]=c.toDataURL('image/jpeg',0.9);scanFrame.classList.remove('scanning');scanPercent.textContent='CAPTURED · 100%';status(angle.label+' angle captured. Preparing the next position...','success');await wait(900);
}
async function startCamera(){try{if(!navigator.mediaDevices?.getUserMedia)throw Error('Camera access is not supported by this browser.');cameraStream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'user',width:{ideal:960},height:{ideal:720}},audio:false});faceVideo.srcObject=cameraStream;await faceVideo.play();cameraReady=true;status('Camera ready. Give consent, then start the automatic scan.','success');updateReady();}catch(e){status(e.message||'Unable to access camera permission.','danger');}}
consentCheckbox.addEventListener('change',updateReady);
saveFaceButton.addEventListener('click',async()=>{
if(!cameraReady||!consentCheckbox.checked||scanBusy||registrationSaved)return;
if(!scanStarted){scanStarted=true;scanBusy=true;saveFaceButton.disabled=true;saveFaceButton.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span>Scanning...';try{
for(let i=0;i<angles.length;i++)await scanProgress(angles[i],i);
stepLabel.textContent='Three angles captured';progressLabel.textContent='3 / 3 captured';progressBar.style.width='100%';instruction.textContent='All three angles are captured. Review your position and save your registration.';scanPercent.textContent='COMPLETE · 100%';status('Scan complete. Your three images are ready to register.','success');saveFaceButton.innerHTML='<i class="bi bi-shield-check me-2"></i> Save Three-Angle Registration';saveFaceButton.disabled=false;
}catch(e){scanStarted=false;status(e.message||'Scanning failed. Please try again.','danger');saveFaceButton.disabled=false;saveFaceButton.innerHTML='<i class="bi bi-arrow-repeat me-2"></i> Retry Face Scan';}finally{scanBusy=false;}return;}
scanBusy=true;saveFaceButton.disabled=true;saveFaceButton.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span>Registering...';status('Saving all three face angles securely. Please wait.','info');
try{const r=await fetch('save-face-registration.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({angle_images:angleImages,consent_given:true,csrf_token:csrfToken})});const result=await r.json();if(!r.ok||!result.success)throw Error(result.message||'Face registration failed.');registrationSaved=true;status(result.message||'Three-angle registration complete.','success');saveFaceButton.innerHTML='<i class="bi bi-check-circle me-2"></i> Face Registered';}
catch(e){status(e.message,'danger');saveFaceButton.innerHTML='<i class="bi bi-arrow-repeat me-2"></i> Retry Registration';saveFaceButton.disabled=false;}
finally{scanBusy=false;}
});
window.addEventListener('beforeunload',()=>{if(cameraStream)cameraStream.getTracks().forEach(t=>t.stop());});updateReady();startCamera();
}
</script>
</body>
</html>
