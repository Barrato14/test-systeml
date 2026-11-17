<?php
session_start();
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/face-service.php';

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

if (($input['consent_given'] ?? false) !== true) {
    faceRegistrationRespond(false, 'Please provide consent before registering your face.');
}

$existingFaceResponse = supabaseServiceRequest(
    'participant_face_profiles?participant_id=eq.' . rawurlencode((string)$_SESSION['user_id']) . '&select=participant_id',
    'GET'
);
if (($existingFaceResponse['code'] ?? 500) >= 400) {
    faceRegistrationRespond(false, 'Unable to verify your existing face registration. Please try again.', 503);
}
if (!empty($existingFaceResponse['data'])) {
    faceRegistrationRespond(false, 'A face is already registered to this account. Contact an administrator if it needs to be reset.', 409);
}

$angleImages = $input['angle_images'] ?? null;
if (!is_array($angleImages)) {
    faceRegistrationRespond(false, 'Front, left, and right face images are required.');
}
foreach (['front', 'left', 'right'] as $angle) {
    if (!isset($angleImages[$angle]) || !is_string($angleImages[$angle]) || $angleImages[$angle] === '') {
        faceRegistrationRespond(false, 'The ' . $angle . ' face image is missing.');
    }
}

$response = callEmisFaceService('register-multi', [
    'participant_id' => (string)$_SESSION['user_id'],
    'angle_images' => $angleImages,
    'consent_given' => true
]);

$data = $response['data'] ?? [];
if (($response['code'] ?? 500) >= 300 || empty($data['success'])) {
    faceRegistrationRespond(false, $data['message'] ?? 'Unable to register your three face angles. Please try again.', $response['code'] ?? 500);
}

faceRegistrationRespond(true, $data['message'] ?? 'Your front, left, and right face angles have been registered successfully.');
?>