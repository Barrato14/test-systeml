<?php
session_start();
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/attendance-storage.php';

function attendanceTestUploadRespond($success, $message, $status = 200, $extra = [])
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

$currentRole = strtolower(trim((string)($_SESSION['user_role'] ?? '')));
if (
    !isset($_SESSION['user_id']) ||
    !in_array($currentRole, ['administrator', 'staff'], true)
) {
    attendanceTestUploadRespond(false, 'Unauthorized access.', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    attendanceTestUploadRespond(false, 'Method not allowed.', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$csrf = (string)($input['csrf_token'] ?? '');
$sessionCsrf = (string)($_SESSION['attendance_logs_csrf'] ?? '');

if ($sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    attendanceTestUploadRespond(false, 'Your session security token expired. Reload the page and try again.', 403);
}

$action = (string)($input['action'] ?? '');
$userId = (string)$_SESSION['user_id'];

try {
    if ($action === 'prepare') {
        $path = 'storage-tests/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $userId)
            . '/test-' . bin2hex(random_bytes(16)) . '.jpg';

        $upload = attendanceStorageCreateSignedUpload($path);

        if (!isset($_SESSION['attendance_storage_test_uploads']) || !is_array($_SESSION['attendance_storage_test_uploads'])) {
            $_SESSION['attendance_storage_test_uploads'] = [];
        }

        $_SESSION['attendance_storage_test_uploads'][$path] = time() + 600;

        attendanceTestUploadRespond(true, 'Secure test upload prepared.', 200, [
            'path' => $path,
            'signed_url' => $upload['signed_url']
        ]);
    }

    if ($action === 'preview') {
        $path = (string)($input['path'] ?? '');
        $prefix = 'storage-tests/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $userId) . '/';

        if (
            strpos($path, $prefix) !== 0 ||
            strpos($path, '..') !== false ||
            strpos($path, '\\\\') !== false ||
            empty($_SESSION['attendance_storage_test_uploads'][$path]) ||
            (int)$_SESSION['attendance_storage_test_uploads'][$path] < time()
        ) {
            attendanceTestUploadRespond(false, 'Test upload not found or expired.', 404);
        }

        $signedUrls = attendanceStorageCreateSignedUrls([$path], 900);
        if (empty($signedUrls[$path])) {
            attendanceTestUploadRespond(false, 'The photo was uploaded, but a preview link could not be created.', 503);
        }

        unset($_SESSION['attendance_storage_test_uploads'][$path]);

        attendanceTestUploadRespond(true, 'Test photo uploaded.', 200, [
            'signed_url' => $signedUrls[$path]
        ]);
    }

    attendanceTestUploadRespond(false, 'Invalid test upload action.', 400);
} catch (Throwable $e) {
    error_log('Attendance storage test upload failed: ' . $e->getMessage());
    attendanceTestUploadRespond(false, 'Supabase Storage test upload failed. Please check the server configuration and try again.', 503);
}
?>
