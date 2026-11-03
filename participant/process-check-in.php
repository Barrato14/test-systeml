<?php
session_start();
header('Content-Type: application/json');
require_once '../config/database.php';
require_once '../includes/auth.php';

// Ensure user is logged in as a Participant
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role']) !== 'participant') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$eventId = $input['event_id'] ?? '';
$participantId = $input['participant_id'] ?? '';
$latitude = $input['latitude'] ?? null;
$longitude = $input['longitude'] ?? null;
$photoDataUrl = $input['photo_data'] ?? '';

if (empty($eventId) || empty($participantId) || empty($photoDataUrl)) {
    echo json_encode(['success' => false, 'message' => 'Missing required check-in data.']);
    exit;
}

// Optional: Decode base64 photo for storage or log verification
// For now, we package the attendance record to insert into Supabase
$payload = [
    'event_id' => $eventId,
    'participant_id' => $participantId,
    'latitude' => $latitude,
    'longitude' => $longitude,
    'check_in_time' => date('c'), // ISO 8601 timestamp
    'status' => 'Present'
];

$response = supabaseRequest('attendance', 'POST', $payload);

if ($response['code'] >= 400) {
    echo json_encode(['success' => false, 'message' => 'Database error recording check-in. HTTP code: ' . $response['code']]);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Check-in recorded successfully!']);
exit;
?>