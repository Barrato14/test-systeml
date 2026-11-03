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

if (empty($eventId)) {
    echo json_encode(['success' => false, 'message' => 'Missing event ID.']);
    exit;
}

// 1. Find the existing active check-in record for this event
$checkExisting = supabaseRequest("attendance?event_id=eq.{$eventId}&participant_id=eq.{$participantId}&select=id,time_out", 'GET');

if ($checkExisting['code'] !== 200 || empty($checkExisting['data'])) {
    echo json_encode(['success' => false, 'message' => 'No active check-in record found for this event. Please check in first!']);
    exit;
}

$attendanceRecord = $checkExisting['data'][0];

// 2. Check if already timed out
if (!empty($attendanceRecord['time_out'])) {
    echo json_encode(['success' => false, 'message' => 'You have already timed out for this event.']);
    exit;
}

$recordId = $attendanceRecord['id'];

// 3. Update the attendance record with the time_out timestamp
$payload = [
    'time_out' => date('c')
];

$response = supabaseRequest("attendance?id=eq.{$recordId}", 'PATCH', $payload);

if ($response['code'] >= 400) {
    echo json_encode(['success' => false, 'message' => 'Failed to record time out.']);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Time out recorded successfully!']);
exit;
?>