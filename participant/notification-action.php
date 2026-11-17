<?php
session_start();
require_once '../config/database.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role'] ?? '') !== 'participant') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please sign in again.']);
    exit;
}

$participantId = (string)$_SESSION['user_id'];
$action = trim((string)($_POST['action'] ?? 'mark_read'));
$notificationId = trim((string)($_POST['notification_id'] ?? ''));
$participantFilter = 'participant_id=eq.' . rawurlencode($participantId);

switch ($action) {
    case 'mark_read':
        if ($notificationId === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid notification.']);
            exit;
        }
        $endpoint = 'notifications?id=eq.' . rawurlencode($notificationId) . '&' . $participantFilter;
        $method = 'PATCH';
        $payload = ['is_read' => true];
        break;

    case 'delete':
        if ($notificationId === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid notification.']);
            exit;
        }
        $endpoint = 'notifications?id=eq.' . rawurlencode($notificationId) . '&' . $participantFilter;
        $method = 'DELETE';
        $payload = null;
        break;

    case 'mark_all_read':
        $endpoint = 'notifications?' . $participantFilter . '&is_read=eq.false';
        $method = 'PATCH';
        $payload = ['is_read' => true];
        break;

    case 'clear_all':
        $endpoint = 'notifications?' . $participantFilter;
        $method = 'DELETE';
        $payload = null;
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown notification action.']);
        exit;
}

$response = supabaseRequest($endpoint, $method, $payload);
if (!is_array($response) || ($response['code'] ?? 500) >= 400) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Unable to update notifications right now.']);
    exit;
}

echo json_encode(['success' => true]);
