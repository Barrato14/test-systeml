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
    <title>Face Registration - Zentrix</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../css/style.css?v=20261117-1">
    <style>
        .face-registration-card { max-width: 720px; margin: 35px auto; padding: 28px; background: #fff; border-radius: 14px; box-shadow: 0 4px 18px rgba(15,23,42,.07); }
        #faceRegistrationVideo { width: 100%; max-height: 420px; background: #111827; border-radius: 12px; object-fit: cover; transform: scaleX(-1); }
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

        <?php if ($hasRegisteredFace): ?>
            <div class="alert alert-success">A face is already registered to your account. Face registration is locked to prevent duplicate registrations. Contact an administrator if your face needs to be reset.</div>
        <?php else: ?>
            <div class="alert alert-info">You have not registered a face yet. Register before using face-based attendance.</div>
        <?php endif; ?>

        <?php if (!$hasRegisteredFace): ?>
        <div id="faceRegistrationStatus" class="alert alert-info text-center">Preparing camera...</div>
        <div class="mb-2 d-flex justify-content-between align-items-center">
            <span class="fw-semibold" id="faceRegistrationStep">Step 1 of 3 · Front</span>
            <span class="small text-muted" id="faceRegistrationProgress">0 / 3 captured</span>
        </div>
        <div class="progress mb-3" style="height:8px"><div id="faceRegistrationProgressBar" class="progress-bar" style="width:0%"></div></div>
        <video id="faceRegistrationVideo" autoplay muted playsinline></video>
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
        <div class="alert alert-secondary mb-0">Your registered face cannot be replaced from this page.</div>
        <?php endif; ?>
    </section>
</main>

<script>
const faceVideo=document.getElementById('faceRegistrationVideo');
if(faceVideo){
const faceStatus=document.getElementById('faceRegistrationStatus'),consentCheckbox=document.getElementById('faceConsent'),saveFaceButton=document.getElementById('saveFaceButton');
const stepLabel=document.getElementById('faceRegistrationStep'),progressLabel=document.getElementById('faceRegistrationProgress'),progressBar=document.getElementById('faceRegistrationProgressBar'),instruction=document.getElementById('faceRegistrationInstruction');
const csrfToken=<?= json_encode($_SESSION['face_registration_csrf']) ?>;
const angles=[{key:'front',label:'Front',prompt:'Look directly at the camera and keep your face centered.'},{key:'left',label:'Left',prompt:'Slowly turn your head to your left. Keep your face visible.'},{key:'right',label:'Right',prompt:'Slowly turn your head to your right. Keep your face visible.'}];
let cameraStream=null,cameraReady=false,registrationSaved=false,currentStep=0;const angleImages={};
function status(message,type='info'){faceStatus.textContent=message;faceStatus.className='alert alert-'+type+' text-center';}
function updateStep(){
if(currentStep>=3){stepLabel.textContent='All three angles captured';progressLabel.textContent='3 / 3 captured';progressBar.style.width='100%';instruction.textContent='All angles captured. Save your registration.';saveFaceButton.innerHTML='<i class="bi bi-shield-check me-2"></i> Save Three-Angle Registration';saveFaceButton.disabled=!cameraReady||!consentCheckbox.checked||registrationSaved;return;}
const angle=angles[currentStep];stepLabel.textContent='Step '+(currentStep+1)+' of 3 · '+angle.label;progressLabel.textContent=currentStep+' / 3 captured';progressBar.style.width=(currentStep/3*100)+'%';instruction.textContent=angle.prompt;saveFaceButton.innerHTML='<i class="bi bi-camera-fill me-2"></i> Capture '+angle.label+' Angle';saveFaceButton.disabled=!cameraReady||!consentCheckbox.checked||registrationSaved;
}
async function startCamera(){try{if(!navigator.mediaDevices?.getUserMedia)throw Error('Camera access is not supported by this browser.');cameraStream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'user',width:{ideal:960},height:{ideal:720}},audio:false});faceVideo.srcObject=cameraStream;await faceVideo.play();cameraReady=true;status('Camera ready. Follow each head-position instruction.','success');updateStep();}catch(e){status(e.message||'Unable to access camera permission.','danger');}}
consentCheckbox.addEventListener('change',updateStep);
saveFaceButton.addEventListener('click',async()=>{
if(!cameraReady||!consentCheckbox.checked||!faceVideo.videoWidth||registrationSaved)return;
if(currentStep<3){const angle=angles[currentStep],c=document.createElement('canvas');c.width=faceVideo.videoWidth;c.height=faceVideo.videoHeight;c.getContext('2d').drawImage(faceVideo,0,0,c.width,c.height);angleImages[angle.key]=c.toDataURL('image/jpeg',0.9);currentStep++;updateStep();status(angle.label+' angle captured. '+(currentStep<3?'Continue with the next position.':'All angles captured. Save your registration.'),'success');return;}
saveFaceButton.disabled=true;saveFaceButton.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span>Registering three angles...';status('Python is processing all three face angles. Please wait.','info');
try{const r=await fetch('save-face-registration.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({angle_images:angleImages,consent_given:true,csrf_token:csrfToken})});const result=await r.json();if(!r.ok||!result.success)throw Error(result.message||'Face registration failed.');registrationSaved=true;status(result.message||'Three-angle registration complete.','success');saveFaceButton.innerHTML='<i class="bi bi-check-circle me-2"></i> Face Registered';}
catch(e){status(e.message,'danger');saveFaceButton.innerHTML='<i class="bi bi-arrow-repeat me-2"></i> Retry Registration';saveFaceButton.disabled=false;}
});
window.addEventListener('beforeunload',()=>{if(cameraStream)cameraStream.getTracks().forEach(t=>t.stop());});updateStep();startCamera();
}
</script>
</body>
</html>
