<?php
session_start();
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/face-service.php';

function angleRespond($success, $message, $status = 200) {
    http_response_code($status);
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role'] ?? '') !== 'participant') {
    angleRespond(false, 'Unauthorized access.', 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    angleRespond(false, 'Method not allowed.', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$eventId = trim((string)($input['event_id'] ?? ''));
$action = (string)($input['action'] ?? '');
$angle = (string)($input['angle'] ?? '');
$imageData = $input['image_data'] ?? '';
$participantId = (string)$_SESSION['user_id'];

if ($eventId === '' || !in_array($action, ['check-in', 'time-out'], true)) {
    angleRespond(false, 'A valid event and attendance action are required.');
}
if (!in_array($angle, ['front', 'left', 'right'], true)) {
    angleRespond(false, 'A valid face angle is required.');
}
if (!is_string($imageData) || $imageData === '') {
    angleRespond(false, 'A camera image is required.');
}

$eventResponse = supabaseServiceRequest(
    'events?id=eq.' . rawurlencode($eventId) . '&select=id',
    'GET'
);
if (($eventResponse['code'] ?? 500) >= 400 || empty($eventResponse['data'])) {
    angleRespond(false, 'Event not found. Face verification cannot continue.', 404);
}

$faceResponse = callEmisFaceService('verify-angle', [
    'participant_id' => $participantId,
    'angle' => $angle,
    'image_data' => $imageData
]);
$faceResult = $faceResponse['data'] ?? [];

if (($faceResponse['code'] ?? 500) >= 300 || empty($faceResult['success']) || empty($faceResult['verified'])) {
    angleRespond(false, $faceResult['message'] ?? 'Face angle verification failed. Please try again.', $faceResponse['code'] ?? 403);
}

if (!isset($_SESSION['face_angle_verifications']) || !is_array($_SESSION['face_angle_verifications'])) {
    $_SESSION['face_angle_verifications'] = [];
}

$verificationKey = hash('sha256', $participantId . '|' . $eventId . '|' . $action);
$now = time();
$state = $_SESSION['face_angle_verifications'][$verificationKey] ?? [
    'participant_id' => $participantId,
    'event_id' => $eventId,
    'action' => $action,
    'verified' => [],
    'expires_at' => $now + 300
];

if (($state['expires_at'] ?? 0) < $now) {
    $state = [
        'participant_id' => $participantId,
        'event_id' => $eventId,
        'action' => $action,
        'verified' => [],
        'expires_at' => $now + 300
    ];
}

$state['verified'][$angle] = true;
$state['expires_at'] = $now + 300;
$_SESSION['face_angle_verifications'][$verificationKey] = $state;

$verifiedAngles = array_values(array_intersect(['front', 'left', 'right'], array_keys($state['verified'])));
angleRespond(true, ucfirst($angle) . ' face angle verified.', 200);
?>