<?php
session_start();
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/face-recognition.php';

function faceRegistrationRespond($success, $message, $status = 200) {
    http_response_code($status);
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role'] ?? '') !== 'participant') {
    faceRegistrationRespond(false, 'Unauthorized access.', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    faceRegistrationRespond(false, 'Method not allowed.', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$csrfToken = $input['csrf_token'] ?? '';

if (empty($_SESSION['face_registration_csrf']) || !hash_equals($_SESSION['face_registration_csrf'], (string)$csrfToken)) {
    faceRegistrationRespond(false, 'Your session security check expired. Refresh the page and try again.', 403);
}

$descriptor = $input['face_descriptor'] ?? null;
$consent = ($input['consent_given'] ?? false) === true;

if (!$consent) {
    faceRegistrationRespond(false, 'Please provide consent before registering your face.');
}

if (!validateFaceDescriptor($descriptor)) {
    faceRegistrationRespond(false, 'A clear single-face scan is required. Please try again.');
}

$participantId = $_SESSION['user_id'];
$payload = [
    'participant_id' => $participantId,
    'face_descriptor' => array_values(array_map('floatval', $descriptor)),
    'consent_given' => true,
    'consent_given_at' => date('c'),
    'updated_at' => date('c')
];

$response = supabaseServiceRequest(
    'participant_face_profiles?on_conflict=participant_id',
    'POST',
    $payload,
    'resolution=merge-duplicates,return=representation'
);

if (($response['code'] ?? 500) >= 300) {
    faceRegistrationRespond(false, 'Unable to save your face registration. Please check the server configuration and try again.', 500);
}

faceRegistrationRespond(true, 'Your face has been registered to your EMIS account successfully.');
