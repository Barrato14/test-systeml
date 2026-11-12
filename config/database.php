<?php
// Supabase REST API Central Helper supporting both Render environment variables and local .env

$envFilePath = __DIR__ . '/../.env';
$env = file_exists($envFilePath) ? parse_ini_file($envFilePath) : [];

// Check Render server environment variables first (supporting SUPABASE_KEY), fallback to .env, then fallback to your URL
$supabaseUrl = getenv('SUPABASE_URL') ?: ($env['SUPABASE_URL'] ?? 'https://tnvrgvcijqccqucwxqyr.supabase.co');
$apiKey = getenv('SUPABASE_KEY') ?: (getenv('SUPABASE_ANON_KEY') ?: ($env['SUPABASE_KEY'] ?? ($env['SUPABASE_ANON_KEY'] ?? '')));

/**
 * Send a request to Supabase REST API
 * 
 * @param string $endpoint The table or endpoint (e.g., 'profiles')
 * @param string $method GET, POST, PATCH, DELETE
 * @param array|null $data Payload data for POST/PATCH
 * @return array Returns ['code' => HTTP_CODE, 'data' => DECODED_JSON]
 */
function supabaseRequest($endpoint, $method = 'GET', $data = null) {
    global $supabaseUrl, $apiKey;

    $url = rtrim($supabaseUrl, '/') . '/rest/v1/' . ltrim($endpoint, '/');

    $headers = [
        "apikey: $apiKey",
        "Authorization: Bearer $apiKey",
        "Content-Type: application/json",
        "Prefer: return=representation"
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'data' => json_decode($response, true)
    ];
}


/**
 * Server-only Supabase request using the service-role key.
 * Never expose this key to browser JavaScript.
 */
function supabaseServiceRequest($endpoint, $method = 'GET', $data = null, $prefer = 'return=representation') {
    global $supabaseUrl;

    $envFilePath = __DIR__ . '/../.env';
    $env = file_exists($envFilePath) ? parse_ini_file($envFilePath) : [];
    $serviceKey = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: ($env['SUPABASE_SERVICE_ROLE_KEY'] ?? '');

    if ($serviceKey === '' || empty($supabaseUrl)) {
        return ['code' => 500, 'data' => ['message' => 'Supabase server credentials are not configured.']];
    }

    $url = rtrim($supabaseUrl, '/') . '/rest/v1/' . ltrim($endpoint, '/');
    $headers = [
        'apikey: ' . $serviceKey,
        'Authorization: Bearer ' . $serviceKey,
        'Content-Type: application/json',
        'Prefer: ' . $prefer
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20
    ]);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['code' => 500, 'data' => ['message' => $curlError ?: 'Supabase request failed.']];
    }

    return ['code' => $httpCode, 'data' => json_decode($response, true)];
}

?>
