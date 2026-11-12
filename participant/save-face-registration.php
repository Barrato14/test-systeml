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

$imageData = $input['image_data'] ?? '';
if (!is_string($imageData) || $imageData === '') {
    faceRegistrationRespond(false, 'A camera image is required. Please try again.');
}

$response = callEmisFaceService('register', [
    'participant_id' => (string)$_SESSION['user_id'],
    'image_data' => $imageData,
    'consent_given' => true
]);

$data = $response['data'] ?? [];
if (($response['code'] ?? 500) >= 300 || empty($data['success'])) {
    faceRegistrationRespond(false, $data['message'] ?? 'Unable to register your face. Please try again.', $response['code'] ?? 500);
}

faceRegistrationRespond(true, $data['message'] ?? 'Your face has been registered successfully.');
