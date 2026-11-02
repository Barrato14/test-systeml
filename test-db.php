<?php
$supabaseUrl = "https://tnvrgvcijqccqucwxqyr.supabase.co/rest/v1/profiles?select=*";
$apiKey = "sb_publishable_LHfcDCsaDzguV3uy8b7TOg_zzkc5nYW";

$ch = curl_init($supabaseUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "apikey: $apiKey",
    "Authorization: Bearer $apiKey"
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    echo "SUCCESS: Connected to Supabase via REST API! Response: " . $response;
} else {
    echo "FAILED: HTTP Code $httpCode - Response: " . $response;
}
?>