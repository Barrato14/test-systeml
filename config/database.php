<?php
// Supabase REST API Central Helper loaded securely from .env

$envFilePath = __DIR__ . '/../.env';
$env = file_exists($envFilePath) ? parse_ini_file($envFilePath) : [];

$supabaseUrl = $env['SUPABASE_URL'] ?? 'https://tnvrgvcijqccqucwxqyr.supabase.co';
$apiKey = $env['SUPABASE_ANON_KEY'] ?? '';

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
?>