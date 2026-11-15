<?php
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Please sign in to view the weather.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$latitude = filter_input(INPUT_GET, 'latitude', FILTER_VALIDATE_FLOAT);
$longitude = filter_input(INPUT_GET, 'longitude', FILTER_VALIDATE_FLOAT);

if ($latitude === false || $latitude === null ||
    $longitude === false || $longitude === null ||
    $latitude < -90 || $latitude > 90 ||
    $longitude < -180 || $longitude > 180) {
    http_response_code(400);
    echo json_encode(['error' => 'A valid location is required.']);
    exit;
}

$params = http_build_query([
    'latitude' => $latitude,
    'longitude' => $longitude,
    'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,is_day,precipitation,weather_code,wind_speed_10m',
    'hourly' => 'temperature_2m,precipitation_probability,weather_code',
    'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_probability_max,sunrise,sunset',
    'timezone' => 'Asia/Manila',
    'forecast_days' => 1
]);

$url = 'https://api.open-meteo.com/v1/forecast?' . $params;
$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPGET => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 18,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: EMIS-Weather/1.0']
]);

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
$curlErrno = curl_errno($ch);
curl_close($ch);

if ($response === false || $status < 200 || $status >= 300) {
    error_log('EMIS weather proxy failed. cURL ' . $curlErrno . '; HTTP ' . $status . '; ' . ($error ?: 'No cURL error'));
    http_response_code(502);
    echo json_encode([
        'error' => 'The weather provider could not be reached from the server.',
        'diagnostic' => [
            'curl_errno' => $curlErrno,
            'curl_error' => $error ?: null,
            'http_status' => $status ?: null
        ]
    ]);
    exit;
}

$data = json_decode($response, true);

if (!is_array($data) || empty($data['current']) || empty($data['daily']) || empty($data['hourly'])) {
    http_response_code(502);
    echo json_encode(['error' => 'The weather provider returned incomplete data.']);
    exit;
}

header('Cache-Control: public, max-age=300');
echo json_encode($data);
