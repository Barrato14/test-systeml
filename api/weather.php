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

// MET Norway recommends no more than four coordinate decimals and local caching.
$latitude = round($latitude, 4);
$longitude = round($longitude, 4);
$cacheKey = hash('sha256', $latitude . ':' . $longitude);
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

$url = 'https://api.met.no/weatherapi/locationforecast/2.0/compact?' . http_build_query([
    'lat' => $latitude,
    'lon' => $longitude
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPGET => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 18,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'User-Agent: EMIS-Weather/1.0 (https://test-systeml.onrender.com)'
    ]
]);

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
$curlErrno = curl_errno($ch);
curl_close($ch);

if ($response === false || $status < 200 || $status >= 300) {
    error_log('EMIS MET Norway weather proxy failed. cURL ' . $curlErrno . '; HTTP ' . $status . '; ' . ($error ?: 'No cURL error'));

    if ($status === 429) {
        @file_put_contents($failureFile, (string) (time() + 600), LOCK_EX);
    }

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

$source = json_decode($response, true);
$timeseries = $source['properties']['timeseries'] ?? null;

if (!is_array($timeseries) || empty($timeseries)) {
    http_response_code(502);
    echo json_encode(['error' => 'MET Norway returned incomplete forecast data.']);
    exit;
}

function emisWeatherCode($symbol) {
    $symbol = strtolower((string) $symbol);
    $symbol = preg_replace('/_(day|night|polartwilight)$/', '', $symbol);
    $codes = [
        'clearsky' => 0, 'fair' => 1, 'partlycloudy' => 2, 'cloudy' => 3,
        'fog' => 45, 'lightrain' => 61, 'rain' => 63, 'heavyrain' => 65,
        'lightrainshowers' => 80, 'rainshowers' => 81, 'heavyrainshowers' => 82,
        'lightsleet' => 66, 'sleet' => 66, 'heavysleet' => 67,
        'lightsnow' => 71, 'snow' => 73, 'heavysnow' => 75,
        'lightsnowshowers' => 85, 'snowshowers' => 85, 'heavysnowshowers' => 86,
        'lightrainandthunder' => 95, 'rainandthunder' => 95,
        'heavyrainandthunder' => 99, 'lightrainshowersandthunder' => 95,
        'rainshowersandthunder' => 95, 'heavyrainshowersandthunder' => 99, 'lightsleetandthunder' => 95,
        'sleetandthunder' => 95, 'heavysleetandthunder' => 99,
        'lightsnowandthunder' => 95, 'snowandthunder' => 95,
        'heavysnowandthunder' => 99
    ];
    return $codes[$symbol] ?? 3;
}

try {
    $manila = new DateTimeZone('Asia/Manila');
    $now = new DateTimeImmutable('now', $manila);
    $today = $now->format('Y-m-d');
    $normalized = [];
    $todayTemperatures = [];
    $rainProbabilities = [];
    $currentIndex = 0;
    $smallestDifference = PHP_INT_MAX;

    foreach ($timeseries as $index => $entry) {
        if (empty($entry['time']) || empty($entry['data']['instant']['details'])) {
            continue;
        }

        $utcTime = new DateTimeImmutable($entry['time']);
        $localTime = $utcTime->setTimezone($manila);
        $details = $entry['data']['instant']['details'];
        if (!isset($details['air_temperature'])) {
            continue;
        }

        $symbol = $entry['data']['next_1_hours']['summary']['symbol_code']
            ?? $entry['data']['next_6_hours']['summary']['symbol_code']
            ?? $entry['data']['next_12_hours']['summary']['symbol_code']
            ?? '';
        $periodDetails = $entry['data']['next_1_hours']['details']
            ?? $entry['data']['next_6_hours']['details']
            ?? $entry['data']['next_12_hours']['details']
            ?? [];

        $normalized[] = [
            'time' => $localTime->format('Y-m-d\TH:i'),
            'temperature' => (float) $details['air_temperature'],
            'weather_code' => emisWeatherCode($symbol),
            'precipitation_probability' => isset($periodDetails['probability_of_precipitation'])
                ? (float) $periodDetails['probability_of_precipitation'] : null,
            'humidity' => isset($details['relative_humidity']) ? (float) $details['relative_humidity'] : null,
            'wind_speed' => isset($details['wind_speed']) ? (float) $details['wind_speed'] * 3.6 : null,
            'apparent_temperature' => (float) $details['air_temperature']
        ];

        if ($localTime->format('Y-m-d') === $today) {
            $todayTemperatures[] = (float) $details['air_temperature'];
            $max = $entry['data']['next_6_hours']['details']['air_temperature_max'] ?? null;
            $min = $entry['data']['next_6_hours']['details']['air_temperature_min'] ?? null;
            if ($max !== null) $todayTemperatures[] = (float) $max;
            if ($min !== null) $todayTemperatures[] = (float) $min;
            if (isset($periodDetails['probability_of_precipitation'])) {
                $rainProbabilities[] = (float) $periodDetails['probability_of_precipitation'];
            }
        }

        $difference = abs($utcTime->getTimestamp() - $now->getTimestamp());
        if ($difference < $smallestDifference) {
            $smallestDifference = $difference;
            $currentIndex = count($normalized) - 1;
        }
    }

    if (empty($normalized)) {
        throw new RuntimeException('No usable forecast entries were returned.');
    }

    $current = $normalized[$currentIndex];
    $hourly = ['time' => [], 'temperature_2m' => [], 'weather_code' => [], 'precipitation_probability' => []];
    foreach ($normalized as $item) {
        if (count($hourly['time']) >= 24) break;
        if ((new DateTimeImmutable($item['time'], $manila))->getTimestamp() < $now->getTimestamp() - 3600) continue;
        $hourly['time'][] = $item['time'];
        $hourly['temperature_2m'][] = $item['temperature'];
        $hourly['weather_code'][] = $item['weather_code'];
        $hourly['precipitation_probability'][] = $item['precipitation_probability'];
    }

    $dailyHigh = !empty($todayTemperatures) ? max($todayTemperatures) : null;
    $dailyLow = !empty($todayTemperatures) ? min($todayTemperatures) : null;
    $forecast = [
        'current' => [
            'time' => $current['time'],
            'temperature_2m' => $current['temperature'],
            'apparent_temperature' => $current['apparent_temperature'],
            'is_day' => ((int) $now->format('G') >= 6 && (int) $now->format('G') < 18) ? 1 : 0,
            'weather_code' => $current['weather_code'],
            'wind_speed_10m' => $current['wind_speed'] ?? 0,
            'relative_humidity_2m' => $current['humidity'] ?? 0
        ],
        'daily' => [
            'temperature_2m_max' => [$dailyHigh],
            'temperature_2m_min' => [$dailyLow],
            'precipitation_probability_max' => [!empty($rainProbabilities) ? max($rainProbabilities) : null]
        ],
        'hourly' => $hourly
    ];
} catch (Throwable $exception) {
    error_log('EMIS MET Norway response conversion failed: ' . $exception->getMessage());
    http_response_code(502);
    echo json_encode(['error' => 'MET Norway returned forecast data that could not be processed.']);
    exit;
}

@file_put_contents($cacheFile, json_encode([
    'saved_at' => time(),
    'data' => $forecast
]), LOCK_EX);
header('X-EMIS-Weather-Cache: MISS');
header('Cache-Control: public, max-age=300');
echo json_encode($forecast);
