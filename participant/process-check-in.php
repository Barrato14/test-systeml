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
$participantId = $input['participant_id'] ?? '';
$latitude = $input['latitude'] ?? null;
$longitude = $input['longitude'] ?? null;
$photoDataUrl = $input['photo_data'] ?? '';

if (empty($eventId) || empty($participantId) || empty($photoDataUrl)) {
    echo json_encode(['success' => false, 'message' => 'Missing required check-in data or photo.']);
    exit;
}

// 1. Check for duplicate check-ins
$existingCheckIn = supabaseRequest("attendance?event_id=eq.{$eventId}&participant_id=eq.{$participantId}&select=id", 'GET');
if ($existingCheckIn['code'] === 200 && !empty($existingCheckIn['data'])) {
    echo json_encode(['success' => false, 'message' => 'You have already checked in for this event! Duplicate check-ins are not allowed.']);
    exit;
}

// 2. Strictly Enforce Event Start-Time Restriction using start_datetime
$eventResponse = supabaseRequest('events?id=eq.' . $eventId . '&select=*', 'GET');
if ($eventResponse['code'] === 200 && !empty($eventResponse['data'])) {
    $event = $eventResponse['data'][0];
    
    $eventStartTime = $event['start_datetime'] ?? null;
    
    if ($eventStartTime) {
        // Convert both current time and event start time to standard epoch seconds for accurate comparison
        $currentTime = time(); 
        $startTimeEpoch = strtotime($eventStartTime);
        
        if ($currentTime < $startTimeEpoch) {
            // Format start time nicely in Philippine Time for the error alert
            $dt = new DateTime($eventStartTime);
            $dt->setTimezone(new DateTimeZone('Asia/Manila'));
            $formattedStart = $dt->format('F j, Y, g:i A');
            
            echo json_encode([
                'success' => false, 
                'message' => "Check-in locked! This event does not start until {$formattedStart} (PST)."
            ]);
            exit;
        }
    }
}

$latitude = ($latitude !== null) ? floatval($latitude) : null;
$longitude = ($longitude !== null) ? floatval($longitude) : null;

// 3. Insert attendance record
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