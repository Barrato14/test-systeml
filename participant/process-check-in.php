<?php
session_start();
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/face-service.php';
require_once '../includes/attendance-geofence.php';
require_once '../includes/daily-attendance.php';

function respond($success, $message) {
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role'] ?? '') !== 'participant') {
    respond(false, 'Unauthorized access.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(false, 'Method not allowed.');
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$eventId = $input['event_id'] ?? '';
$participantId = (string)$_SESSION['user_id'];
$latitude = $input['latitude'] ?? null;
$longitude = $input['longitude'] ?? null;
$gpsAccuracy = $input['accuracy'] ?? null;
$photoDataUrl = $input['photo_data'] ?? '';

if ($eventId === '' || !is_string($photoDataUrl) || $photoDataUrl === '') {
    respond(false, 'Missing required check-in data or photo.');
}

// Fetch event and validate its schedule on the server.
$eventResponse = supabaseRequest(
    'events?id=eq.' . rawurlencode($eventId) . '&select=id,title,start_datetime,end_datetime,latitude,longitude,venue_boundary',
    'GET'
);

if (($eventResponse['code'] ?? 500) >= 400 || empty($eventResponse['data'])) {
    respond(false, 'Event not found. Check-in cannot be processed.');
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
    respond(false, 'Check-in is not available yet. This event starts on ' . $eventStart->format('F j, Y, g:i A') . ' (PHT).');
}

if ($now >= $eventEnd) {
    respond(false, 'Check-in is closed. This event ended on ' . $eventEnd->format('F j, Y, g:i A') . ' (PHT).');
}

// Enforce the 50-meter event geofence on the server before face verification.
$geofence = checkEventGeofence($event, $latitude, $longitude, $gpsAccuracy);
if (!$geofence['allowed']) {
    respond(false, $geofence['message']);
}

// Multi-day events use one attendance row per participant per Manila calendar day.
$dailyMode = isMultiDayAttendanceEvent($event);
$attendanceDate = $now->format('Y-m-d');
$duplicateQuery = $dailyMode
    ? 'daily_attendance?event_id=eq.' . rawurlencode($eventId) . '&participant_id=eq.' . rawurlencode($participantId) . '&attendance_date=eq.' . rawurlencode($attendanceDate) . '&select=id'
    : 'attendance?event_id=eq.' . rawurlencode($eventId) . '&participant_id=eq.' . rawurlencode($participantId) . '&select=id';
$existingCheckIn = $dailyMode ? supabaseServiceRequest($duplicateQuery, 'GET') : supabaseRequest($duplicateQuery, 'GET');

if (($existingCheckIn['code'] ?? 500) >= 400) {
    respond(false, 'Unable to verify your attendance record. Please try again.');
}

if (!empty($existingCheckIn['data'])) {
    respond(false, 'You have already checked in for this event.');
}

$faceResponse = callEmisFaceService('verify', [
    'participant_id' => $participantId,
    'image_data' => $photoDataUrl
]);
$faceResult = $faceResponse['data'] ?? [];

if (($faceResponse['code'] ?? 500) >= 300 || empty($faceResult['success'])) {
    respond(false, $faceResult['message'] ?? 'Face verification failed. Please try again.');
}

$payload = [
    'event_id' => $eventId,
    'participant_id' => $participantId,
    'latitude' => $latitude !== null ? (float)$latitude : null,
    'longitude' => $longitude !== null ? (float)$longitude : null,
    'photo_url' => $photoDataUrl,
    'check_in_time' => $now->format('c'),
    'status' => 'Present'
];

if ($dailyMode) $payload['attendance_date'] = $attendanceDate;
$response = supabaseRequest($dailyMode ? 'daily_attendance' : 'attendance', 'POST', $payload);

if (($response['code'] ?? 500) >= 400) {
    respond(false, 'Failed to save check-in. Please try again.');
}

respond(true, 'Check-in recorded successfully!');
?>