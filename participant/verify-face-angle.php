<?php
require_once '../includes/session.php';
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
$challengeId = (string)($input['challenge_id'] ?? '');
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
if (!preg_match('/^[a-f0-9]{64}$/', $challengeId)) {
    angleRespond(false, 'Your face verification session is invalid. Start the scan again.', 403);
}

if (!isset($_SESSION['face_angle_verifications']) || !is_array($_SESSION['face_angle_verifications'])) {
    angleRespond(false, 'Start a new face verification session before scanning.', 403);
}
$verificationKey = hash('sha256', $participantId . '|' . $eventId . '|' . $action);
$state = $_SESSION['face_angle_verifications'][$verificationKey] ?? null;
$requiredAngles = ['front', 'left', 'right'];
if (
    !is_array($state)
    || !hash_equals((string)($state['challenge_id'] ?? ''), $challengeId)
    || ($state['participant_id'] ?? '') !== $participantId
    || ($state['event_id'] ?? '') !== $eventId
    || ($state['action'] ?? '') !== $action
    || (int)($state['expires_at'] ?? 0) < time()
    || !empty($state['completed'])
) {
    angleRespond(false, 'Your face verification session expired or is no longer valid. Start the scan again.', 403);
}
$expectedAngle = $requiredAngles[(int)($state['next_index'] ?? -1)] ?? null;
if ($expectedAngle === null || $angle !== $expectedAngle) {
    angleRespond(false, 'The face angles must be verified in the requested order. Restart the scan.', 409);
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

$state['verified'][$angle] = true;
$state['next_index'] = (int)$state['next_index'] + 1;
$state['expires_at'] = min((int)$state['created_at'] + 180, time() + 180);
$state['completed'] = $state['next_index'] === count($requiredAngles);
$_SESSION['face_angle_verifications'][$verificationKey] = $state;

angleRespond(true, ucfirst($angle) . ' face angle verified.', 200, [
    'verified_count' => $state['next_index'],
    'required_count' => count($requiredAngles),
    'verification_complete' => $state['completed']
]);
?>