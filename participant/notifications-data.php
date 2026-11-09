<?php
session_start();
require_once '../config/database.php';
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role'] ?? '') !== 'participant') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please sign in again.']);
    exit;
}
$response = supabaseRequest(
    'notifications?participant_id=eq.' . rawurlencode((string)$_SESSION['user_id']) . '&select=id,title,message,is_read,created_at&order=created_at.desc',
    'GET'
);
if (!is_array($response) || ($response['code'] ?? 500) >= 400) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Unable to load notifications right now.']);
    exit;
}
echo json_encode(['success' => true, 'notifications' => is_array($response['data'] ?? null) ? $response['data'] : []]);
