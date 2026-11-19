<?php
require_once '../includes/session.php';
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/attendance-storage.php';

function uploadRespond($success, $message, $status = 200, $extra = [])
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role'] ?? '') !== 'participant') {
    uploadRespond(false, 'Unauthorized access.', 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    uploadRespond(false, 'Method not allowed.', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$eventId = trim((string)($input['event_id'] ?? ''));
$action = (string)($input['action'] ?? '');
$participantId = (string)$_SESSION['user_id'];

if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $eventId)
    || !in_array($action, ['check-in', 'time-out'], true)) {
    uploadRespond(false, 'Invalid attendance upload request.', 400);
}

$eventCheck = supabaseRequest(
    'events?id=eq.' . rawurlencode($eventId) . '&select=id',
    'GET'
);
if (($eventCheck['code'] ?? 500) >= 400 || empty($eventCheck['data'])) {
    uploadRespond(false, 'Event not found. Attendance evidence cannot be uploaded.', 404);
}

$verificationKey = hash('sha256', $participantId . '|' . $eventId . '|' . $action);
$verification = $_SESSION['face_angle_verifications'][$verificationKey] ?? null;
$requiredAngles = ['front', 'left', 'right'];
$verifiedAngles = is_array($verification['verified'] ?? null) ? $verification['verified'] : [];

if (
    !is_array($verification)
    || ($verification['participant_id'] ?? '') !== $participantId
    || ($verification['event_id'] ?? '') !== $eventId
    || ($verification['action'] ?? '') !== $action
    || empty($verification['completed'])
    || (int)($verification['expires_at'] ?? 0) < time()
    || array_diff($requiredAngles, array_keys($verifiedAngles))
) {
    uploadRespond(false, 'Complete face verification before uploading attendance evidence.', 403);
}

try {
    $path = $eventId . '/' . $participantId . '/' . $action . '-' . bin2hex(random_bytes(16)) . '.jpg';
    $upload = attendanceStorageCreateSignedUpload($path);

    if (!isset($_SESSION['attendance_uploads']) || !is_array($_SESSION['attendance_uploads'])) {
        $_SESSION['attendance_uploads'] = [];
    }
    $_SESSION['attendance_uploads'][$path] = [
        'participant_id' => $participantId,
        'event_id' => $eventId,
        'action' => $action,
        'expires_at' => time() + 600
    ];

    uploadRespond(true, 'Secure upload prepared.', 200, [
        'path' => $path,
        'signed_url' => $upload['signed_url']
    ]);
} catch (Throwable $e) {
    error_log('Attendance evidence upload preparation failed: ' . $e->getMessage());
    uploadRespond(false, 'Unable to prepare secure photo storage. Please try again.', 503);
}
?>
