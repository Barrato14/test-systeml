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

if (empty($eventId) || empty($participantId)) {
    echo json_encode(['success' => false, 'message' => 'Missing required check-in data.']);
    exit;
}

// Ensure coordinates are properly formatted as floats or null
$latitude = ($latitude !== null) ? floatval($latitude) : null;
$longitude = ($longitude !== null) ? floatval($longitude) : null;

// Adjust these keys to match your exact Supabase 'attendance' table column names
$payload = [
    'event_id' => $eventId,
    'participant_id' => $participantId, // Change to 'user_id' if your table uses user_id
    'latitude' => $latitude,
    'longitude' => $longitude,
    'check_in_time' => date('c'),     // Change to 'created_at' or similar if your timestamp column has a different name
    'status' => 'Present'
];

$response = supabaseRequest('attendance', 'POST', $payload);

if ($response['code'] >= 400) {
    // Return the actual Supabase error message back to the frontend for easy debugging
    $errorDetails = is_array($response['data']) ? json_encode($response['data']) : 'Unknown database error';
    echo json_encode(['success' => false, 'message' => 'Supabase Error (HTTP ' . $response['code'] . '): ' . $errorDetails]);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Check-in recorded successfully!']);
exit;
?>
