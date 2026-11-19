<?php

function attendanceStorageCredentials()
{
    global $supabaseUrl, $env;

    $serviceKey = getenv('SUPABASE_SERVICE_ROLE_KEY')
        ?: (($env['SUPABASE_SERVICE_ROLE_KEY'] ?? ''));

    if (empty($supabaseUrl) || empty($serviceKey)) {
        throw new RuntimeException('Attendance photo storage is not configured.');
    }

    return [rtrim($supabaseUrl, '/'), $serviceKey];
}

function attendanceStorageRequest($method, $path, $payload = null, $extraHeaders = [])
{
    [$baseUrl, $serviceKey] = attendanceStorageCredentials();

    $headers = array_merge([
        'apikey: ' . $serviceKey,
        'Authorization: Bearer ' . $serviceKey,
        'Accept: application/json'
    ], $extraHeaders);

    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init($baseUrl . '/storage/v1' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Supabase Storage request failed: ' . $curlError);
    }

    $decoded = json_decode($body, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $message = is_array($decoded)
            ? ($decoded['message'] ?? $decoded['error'] ?? 'Supabase Storage rejected the request.')
            : 'Supabase Storage rejected the request.';
        throw new RuntimeException($message . ' (HTTP ' . $httpCode . ')');
    }

    return ['code' => $httpCode, 'data' => $decoded, 'body' => $body];
}

function attendanceStorageEncodePath($path)
{
    return implode('/', array_map('rawurlencode', explode('/', trim((string)$path, '/'))));
}

function attendanceStorageCreateSignedUpload($path)
{
    $encodedPath = attendanceStorageEncodePath($path);
    $result = attendanceStorageRequest(
        'POST',
        '/object/upload/sign/attendance-evidence/' . $encodedPath,
        ['upsert' => false]
    );

    $relativeUrl = $result['data']['url'] ?? '';
    $token = $result['data']['token'] ?? '';

    if (!is_string($relativeUrl) || $relativeUrl === '' || !is_string($token) || $token === '') {
        throw new RuntimeException('Supabase did not return a valid signed upload URL.');
    }

    [$baseUrl] = attendanceStorageCredentials();
    $signedUrl = preg_match('/^https?:\/\//i', $relativeUrl)
        ? $relativeUrl
        : $baseUrl . '/storage/v1' . $relativeUrl;

    return ['signed_url' => $signedUrl, 'token' => $token];
}

function attendanceStorageObjectExists($path)
{
    try {
        $result = attendanceStorageRequest(
            'GET',
            '/object/info/attendance-evidence/' . attendanceStorageEncodePath($path)
        );
        return $result['code'] >= 200 && $result['code'] < 300;
    } catch (Throwable $e) {
        return false;
    }
}

function attendanceStorageDeleteObject($path)
{
    if (!is_string($path) || $path === '') return;

    attendanceStorageRequest(
        'DELETE',
        '/object/attendance-evidence/' . attendanceStorageEncodePath($path)
    );
}

function attendanceStorageCreateSignedUrls($paths, $expiresIn = 3600)
{
    $paths = array_values(array_unique(array_filter($paths, function ($path) {
        return is_string($path) && $path !== '';
    })));

    if (empty($paths)) return [];

    $result = attendanceStorageRequest(
        'POST',
        '/object/sign/attendance-evidence',
        ['expiresIn' => (int)$expiresIn, 'paths' => $paths]
    );

    [$baseUrl] = attendanceStorageCredentials();
    $signed = [];

    foreach (($result['data'] ?? []) as $item) {
        $path = $item['path'] ?? '';
        $relativeUrl = $item['signedURL'] ?? '';
        if ($path === '' || $relativeUrl === '') continue;

        $signed[$path] = preg_match('/^https?:\/\//i', $relativeUrl)
            ? $relativeUrl
            : $baseUrl . '/storage/v1' . $relativeUrl;
    }

    return $signed;
}

function attendanceStoragePathFromValue($value)
{
    $prefix = 'storage://attendance-evidence/';
    if (!is_string($value) || strpos($value, $prefix) !== 0) return null;

    $path = substr($value, strlen($prefix));
    if ($path === '' || strpos($path, '..') !== false || strpos($path, '\\') !== false) return null;

    return $path;
}

?>
