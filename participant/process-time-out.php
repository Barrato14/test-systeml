<?
$faceVerification = verifyParticipantFace($participantId, $faceDescriptor);
if (!$faceVerification['success']) {
    respond(false, $faceVerification['message']);
}
php
session_start();
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/face-recognition.php';

function respond($success, $message) {
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role'] ?? '') !== 'participant') {
    respond(false, 'Unauthorized access.');
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$eventId = $input['event_id'] ?? '';
$participantId = $_SESSION['user_id']; // Use the authenticated session.
$latitude = $input['latitude'] ?? null;
$longitude = $input['longitude'] ?? null;
$photoDataUrl = $input['photo_data'] ?? '';
$faceDescriptor = $input['face_descriptor'] ?? null;

if ($eventId === '' || $photoDataUrl === '') {
    respond(false, 'Missing required time-out data or photo.');
}

// Fetch event and validate its end time on the server.
$eventResponse = supabaseRequest(
    'events?id=eq.' . rawurlencode($eventId) . '&select=id,title,start_datetime,end_datetime',
    'GET'
);

if (($eventResponse['code'] ?? 500) >= 400 || empty($eventResponse['data'])) {
    respond(false, 'Event not found. Time-out cannot be processed.');
}

$event = $eventResponse['data'][0];

if (empty($event['start_datetime']) || empty($event['end_datetime'])) {
    respond(false, 'This event has an incomplete schedule. Please contact the event organizer.');
}

try {
    $manila = new DateTimeZone('Asia/Manila');
    $now = new DateTimeImmutable('now', $manila);
    $eventStart = (new DateTimeImmutable($event['start_datetime']))->setTimezone($manila);
    $eventEnd = (new DateTimeImmutable($event['end_datetime']))->setTimezone($manila);
} catch (Exception $e) {
    respond(false, 'The event schedule is invalid. Please contact the event organizer.');
}

if ($eventEnd <= $eventStart) {
    respond(false, 'The event has an invalid schedule. Please contact the event organizer.');
}

if ($now < $eventStart) {
    respond(false, 'Time-out is not available before the event starts.');
}

if ($now >= $eventEnd) {
    respond(false, 'Time-out is closed. This event ended on ' . $eventEnd->format('F j, Y, g:i A') . ' (PHT).');
}

// Find the participant's attendance record.
$checkExisting = supabaseRequest(
    'attendance?event_id=eq.' . rawurlencode($eventId) .
    '&participant_id=eq.' . rawurlencode($participantId) . '&select=id,time_out',
    'GET'
);

if (($checkExisting['code'] ?? 500) >= 400 || empty($checkExisting['data'])) {
    respond(false, 'No check-in record was found. You must check in first.');
}

$attendance = $checkExisting['data'][0];

if (!empty($attendance['time_out'])) {
    respond(false, 'You have already timed out for this event.');
}

$recordId = $attendance['id'];

$payload = [
    'time_out' => $now->format('c'),
    'timeout_photo_url' => $photoDataUrl,
    'timeout_latitude' => $latitude !== null ? (float)$latitude : null,
    'timeout_longitude' => $longitude !== null ? (float)$longitude : null
];

$response = supabaseRequest(
    'attendance?id=eq.' . rawurlencode((string)$recordId),
    'PATCH',
    $payload
);

if (($response['code'] ?? 500) >= 400) {
    respond(false, 'Failed to record time-out in the database.');
}

respond(true, 'Time-out recorded successfully!');
?>
