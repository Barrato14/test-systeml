<?php
session_start();
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');
require_once '../config/database.php';
require_once '../includes/auth.php';

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role']) !== 'participant') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$eventId = $input['event_id'] ?? '';
$participantId = $_SESSION['user_id'];
$latitude = $input['latitude'] ?? null;
$longitude = $input['longitude'] ?? null;
$photoDataUrl = $input['photo_data'] ?? '';

if (empty($eventId) || empty($photoDataUrl)) {
    echo json_encode(['success' => false, 'message' => 'Missing required data or photo.']);
    exit;
}

// Find existing check-in record ID
$checkExisting = supabaseRequest("attendance?event_id=eq.{$eventId}&participant_id=eq.{$participantId}&select=id,time_out", 'GET');
if ($checkExisting['code'] !== 200 || empty($checkExisting['data'])) {
    echo json_encode(['success' => false, 'message' => 'No active check-in found.']);
    exit;
}

$recordId = $checkExisting['data'][0]['id'];

// Update with time out details
$payload = [
    'time_out' => date('c'),
    'timeout_photo_url' => $photoDataUrl,
    'timeout_latitude' => $latitude !== null ? floatval($latitude) : null,
    'timeout_longitude' => $longitude !== null ? floatval($longitude) : null
];

$response = supabaseRequest("attendance?id=eq.{$recordId}", 'PATCH', $payload);

if ($response['code'] >= 400) {
    echo json_encode(['success' => false, 'message' => 'Failed to record time out in database.']);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Time out recorded successfully!']);
exit;
?>