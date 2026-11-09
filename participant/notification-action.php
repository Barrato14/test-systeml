<?php
session_start();
require_once '../config/database.php';
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role'] ?? '') !== 'participant') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please sign in again.']);
    exit;
}
$notificationId = trim((string)($_POST['notification_id'] ?? ''));
if ($notificationId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid notification.']);
    exit;
}
$response = supabaseRequest(
    'notifications?id=eq.' . rawurlencode($notificationId) . '&participant_id=eq.' . rawurlencode((string)$_SESSION['user_id']),
    'PATCH',
    ['is_read' => true]
);
if (!is_array($response) || ($response['code'] ?? 500) >= 400) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Unable to update this notification.']);
    exit;
}
echo json_encode(['success' => true]);
