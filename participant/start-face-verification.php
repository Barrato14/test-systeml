<?php
session_start();
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/auth.php';

function challengeRespond($success, $message, $status = 200, $extra = []) {
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role'] ?? '') !== 'participant') {
    challengeRespond(false, 'Unauthorized access.', 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    challengeRespond(false, 'Method not allowed.', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$eventId = trim((string)($input['event_id'] ?? ''));
$action = (string)($input['action'] ?? '');
if ($eventId === '' || !in_array($action, ['check-in', 'time-out'], true)) {
    challengeRespond(false, 'A valid event and attendance action are required.');
}

$eventResponse = supabaseServiceRequest(
    'events?id=eq.' . rawurlencode($eventId) . '&select=id',
    'GET'
);
if (($eventResponse['code'] ?? 500) >= 400 || empty($eventResponse['data'])) {
    challengeRespond(false, 'Event not found. Face verification cannot continue.', 404);
}

if (!isset($_SESSION['face_angle_verifications']) || !is_array($_SESSION['face_angle_verifications'])) {
    $_SESSION['face_angle_verifications'] = [];
}

$participantId = (string)$_SESSION['user_id'];
$verificationKey = hash('sha256', $participantId . '|' . $eventId . '|' . $action);
$challengeId = bin2hex(random_bytes(32));
$now = time();

$_SESSION['face_angle_verifications'][$verificationKey] = [
    'participant_id' => $participantId,
    'event_id' => $eventId,
    'action' => $action,
    'challenge_id' => $challengeId,
    'verified' => [],
    'next_index' => 0,
    'completed' => false,
    'created_at' => $now,
    'expires_at' => $now + 180
];

challengeRespond(true, 'A new face verification challenge has started.', 200, [
    'challenge_id' => $challengeId,
    'required_angles' => ['front', 'left', 'right'],
    'expires_in' => 180
]);
?>