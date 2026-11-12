<?php

function callEmisFaceService($endpoint, $payload) {
    $token = getenv('FACE_SERVICE_TOKEN') ?: '';
    $baseUrl = getenv('FACE_SERVICE_URL') ?: 'http://127.0.0.1:8000';

    if ($token === '') {
        return [
            'code' => 500,
            'data' => ['success' => false, 'message' => 'Face recognition service is not configured.']
        ];
    }

    $ch = curl_init(rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Face-Service-Token: ' . $token
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 90
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [
            'code' => 503,
            'data' => ['success' => false, 'message' => 'Face recognition service is unavailable. ' . $curlError]
        ];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        $decoded = ['success' => false, 'message' => 'Face recognition service returned an invalid response.'];
    }

    return ['code' => $httpCode, 'data' => $decoded];
}
