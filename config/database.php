<?php
// Supabase REST API Central Helper supporting environment variables and hardcoded fallback

$envFilePath = __DIR__ . '/../.env';
$env = file_exists($envFilePath) ? parse_ini_file($envFilePath) : [];

// Pull from environment or fallback directly to your project credentials
$supabaseUrl = getenv('SUPABASE_URL') ?: ($env['SUPABASE_URL'] ?? 'https://tnvrgvcijqccqucwxqyr.supabase.co');
$apiKey = getenv('SUPABASE_KEY') ?: ($env['SUPABASE_KEY'] ?: (getenv('SUPABASE_ANON_KEY') ?: ($env['SUPABASE_ANON_KEY'] ?? 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InRudnJndmNpanFjY3F1Y3d4cXlyIiwicm9sZSI6ImFub24iLCJpYXQiOjE3MjkzNTE4OTMsImV4cCI6MjA0NDkyNzg5M30.YOUR_ACTUAL_KEY_HERE'))); 
// (Note: Make sure your actual long anon key string is pasted above if environment variables aren't reading properly)

if (empty($supabaseUrl) || empty($apiKey)) {
    die("Server configuration error: Missing Supabase credentials.");
}

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
