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
    echo json_encode(['success' => false, 'message' => 'Missing required check-in data or photo.']);
    exit;
}

// 1. Process the Base64 image data
list($type, $photoDataUrl) = explode(';', $photoDataUrl);
list(, $photoDataUrl)      = explode(',', $photoDataUrl);
$decodedPhoto = base64_decode($photoDataUrl);

if ($decodedPhoto === false) {
    echo json_encode(['success' => false, 'message' => 'Failed to decode check-in photo.']);
    exit;
}

// 2. Upload photo to Supabase Storage bucket (Bucket name assumed to be 'attendance-photos')
global $supabaseUrl, $apiKey;
$fileName = 'attendance_' . $participantId . '_' . $eventId . '_' . time() . '.jpg';
$storageUrl = rtrim($supabaseUrl, '/') . '/storage/v1/object/attendance-photos/' . $fileName;

$headers = [
    "apikey: $apiKey",
    "Authorization: Bearer $apiKey",
    "Content-Type: image/jpeg",
    "x-upsert: true"
];

$ch = curl_init($storageUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_POSTFIELDS, $decodedPhoto);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$storageResponse = curl_exec($ch);
$storageHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($storageHttpCode >= 400) {
    echo json_encode(['success' => false, 'message' => 'Failed to upload photo to storage. HTTP code: ' . $storageHttpCode]);
    exit;
}

// Construct the public URL for the uploaded photo
$publicPhotoUrl = rtrim($supabaseUrl, '/') . '/storage/v1/object/public/attendance-photos/' . $fileName;

// Ensure coordinates are properly formatted as floats or null
$latitude = ($latitude !== null) ? floatval($latitude) : null;
$longitude = ($longitude !== null) ? floatval($longitude) : null;

// 3. Insert attendance record including the photo URL
$payload = [
    'event_id' => $eventId,
    'participant_id' => $participantId,
    'latitude' => $latitude,
    'longitude' => $longitude,
    'photo_url' => $publicPhotoUrl,
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
