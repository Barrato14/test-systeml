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

// Cache forecasts per nearby area to avoid repeatedly hitting the provider's rate limit.
$cacheKey = hash('sha256', round($latitude, 2) . ':' . round($longitude, 2));
$cacheFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'emis-weather-' . $cacheKey . '.json';
$failureFile = $cacheFile . '.retry';

$cachedPayload = null;
$cachedAt = 0;

if (is_file($cacheFile)) {
    $cacheContents = @file_get_contents($cacheFile);
    $cacheRecord = $cacheContents ? json_decode($cacheContents, true) : null;
    if (is_array($cacheRecord) && isset($cacheRecord['data'], $cacheRecord['saved_at']) &&
        is_array($cacheRecord['data'])) {
        $cachedPayload = $cacheRecord['data'];
        $cachedAt = (int) $cacheRecord['saved_at'];
        if (time() - $cachedAt < 600) {
            header('X-EMIS-Weather-Cache: HIT');
            header('Cache-Control: public, max-age=300');
            echo json_encode($cachedPayload);
            exit;
        }
    }
}

// Avoid repeatedly calling a provider that has just rate-limited this location.
if (is_file($failureFile)) {
    $retryAfter = (int) @file_get_contents($failureFile);
    if ($retryAfter > time()) {
        if ($cachedPayload !== null && time() - $cachedAt < 86400) {
            header('X-EMIS-Weather-Cache: STALE');
            header('Cache-Control: public, max-age=120');
            echo json_encode($cachedPayload);
            exit;
        }
        http_response_code(429);
        header('Retry-After: ' . ($retryAfter - time()));
        echo json_encode(['error' => 'Weather provider rate limit reached. Please try again later.']);
        exit;
    }
    @unlink($failureFile);
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

    // Back off after an upstream rate limit instead of creating more requests.
    if ($status === 429) {
        @file_put_contents($failureFile, (string) (time() + 600), LOCK_EX);
    }

    // If the provider rate-limits or temporarily fails, serve the last known forecast.
    if ($cachedPayload !== null && time() - $cachedAt < 86400) {
        header('X-EMIS-Weather-Cache: STALE');
        header('Cache-Control: public, max-age=120');
        echo json_encode($cachedPayload);
        exit;
    }

    if ($status === 429) {
        http_response_code(429);
        header('Retry-After: 600');
        echo json_encode(['error' => 'Weather provider rate limit reached. Please try again in about 10 minutes.']);
        exit;
    }

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

@file_put_contents($cacheFile, json_encode([
    'saved_at' => time(),
    'data' => $data
]), LOCK_EX);
header('X-EMIS-Weather-Cache: MISS');
header('Cache-Control: public, max-age=300');
echo json_encode($data);
