<?php
session_start();
// Include whatever file you use to initialize your .env locally (if applicable)
// require_once '../../config/database.php'; 

header('Content-Type: application/json');

// 1. Fetch Credentials from Render / .env
$supabaseUrl = getenv('SUPABASE_URL') ?: $_ENV['SUPABASE_URL'] ?? '';
$supabaseKey = getenv('SUPABASE_KEY') ?: $_ENV['SUPABASE_KEY'] ?? '';

if (empty($supabaseUrl) || empty($supabaseKey)) {
    echo json_encode(['success' => false, 'error' => 'Server configuration error: Missing Supabase credentials.']);
    exit;
}

// 2. Basic Security Checks
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role']) !== 'participant') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit;
}

// 3. Get JSON payload from Javascript fetch
$input = json_decode(file_get_contents('php://input'), true);

$eventId = $input['event_id'] ?? null;
$participantId = $input['participant_id'] ?? null;
$photoBase64 = $input['photo'] ?? null;
$latitude = $input['latitude'] ?? null;
$longitude = $input['longitude'] ?? null;

if (!$eventId || !$participantId || !$photoBase64 || !$latitude || !$longitude) {
    echo json_encode(['success' => false, 'error' => 'Missing required check-in data.']);
    exit;
}

if ($_SESSION['user_id'] !== $participantId) {
    echo json_encode(['success' => false, 'error' => 'ID mismatch.']);
    exit;
}

// 4. Process Image and Upload to Supabase Storage
$imageParts = explode(";base64,", $photoBase64);
$imageTypeAux = explode("image/", $imageParts[0]);
$imageType = $imageTypeAux[1];
$imageBase64 = base64_decode($imageParts[1]);

$fileName = $eventId . '/' . $participantId . '_' . time() . '.' . $imageType;
$storageUrl = rtrim($supabaseUrl, '/') . '/storage/v1/object/attendance-photos/' . $fileName;

$ch = curl_init($storageUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_POSTFIELDS, $imageBase64);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $supabaseKey,
    'apikey: ' . $supabaseKey,
    'Content-Type: image/' . $imageType
]);

$uploadResponse = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode >= 400) {
    echo json_encode(['success' => false, 'error' => 'Failed to upload photo evidence.']);
    exit;
}

$publicPhotoUrl = rtrim($supabaseUrl, '/') . '/storage/v1/object/public/attendance-photos/' . $fileName;

// 5. Save Record to PostgreSQL `attendance` Table
$dbPayload = [
    'event_id' => $eventId,
    'participant_id' => $participantId,
    'photo_url' => $publicPhotoUrl,
    'latitude' => $latitude,
    'longitude' => $longitude,
    'status' => 'Present'
];

$chDb = curl_init(rtrim($supabaseUrl, '/') . '/rest/v1/attendance');
curl_setopt($chDb, CURLOPT_RETURNTRANSFER, true);
curl_setopt($chDb, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($chDb, CURLOPT_POSTFIELDS, json_encode($dbPayload));
curl_setopt($chDb, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $supabaseKey,
    'apikey: ' . $supabaseKey,
    'Content-Type: application/json',
    'Prefer: return=representation'
]);

$dbResponse = curl_exec($chDb);
$dbHttpCode = curl_getinfo($chDb, CURLINFO_HTTP_CODE);
curl_close($chDb);

if ($dbHttpCode >= 400) {
    $errorMsg = 'Database error.';
    if ($dbHttpCode == 409) {
        $errorMsg = 'You have already checked in for this event.';
    }
    echo json_encode(['success' => false, 'error' => $errorMsg]);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Check-in recorded securely.']);