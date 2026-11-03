<?php
session_start();
date_default_timezone_set('Asia/Manila');
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
    echo json_encode(['success' => false, 'message' => 'Missing required check-in data or photo.']);
    exit;
}

// 1. Verify Event Start Time Restriction
$eventResponse = supabaseRequest('events?id=eq.' . $eventId . '&select=*', 'GET');
if ($eventResponse['code'] === 200 && !empty($eventResponse['data'])) {
    $event = $eventResponse['data'][0];
    // Check common start time column names (start_time, date, or event_date)
    $eventStartTime = $event['start_time'] ?? $event['date'] ?? $event['event_date'] ?? null;
    
    if ($eventStartTime) {
        $currentTime = time();
        $startTimeEpoch = strtotime($eventStartTime);
        
        if ($currentTime < $startTimeEpoch) {
            $formattedStart = date('M j, Y, g:i A', $startTimeEpoch);
            echo json_encode([
                'success' => false, 
                'message' => "Check-in is locked. This event starts on {$formattedStart}."
            ]);
            exit;
        }
    }
}

// Ensure coordinates are properly formatted as floats or null
$latitude = ($latitude !== null) ? floatval($latitude) : null;
$longitude = ($longitude !== null) ? floatval($longitude) : null;

// 2. Insert attendance record directly using the Base64 photo string
$payload = [
    'event_id' => $eventId,
    'participant_id' => $participantId,
    'latitude' => $latitude,
    'longitude' => $longitude,
    'photo_url' => $photoDataUrl,
    'check_in_time' => date('c'),
    'status' => 'Present'
];

$response = supabaseRequest('attendance', 'POST', $payload);

if ($response['code'] >= 400) {
    $errorDetails = is_array($response['data']) ? json_encode($response['data']) : 'Unknown database error';
    echo json_encode(['success' => false, 'message' => 'Supabase Error (HTTP ' . $response['code'] . '): ' . $errorDetails]);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Check-in recorded successfully!']);
exit;
?>