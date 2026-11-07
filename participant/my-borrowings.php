<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once '../config/database.php';
require_once '../includes/auth.php';

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role'] ?? '') !== 'participant') {
    header('Location: ../login.php');
    exit;
}

$participantId = (string)$_SESSION['user_id'];
$requests = [];
$equipmentMap = [];
$errorMsg = '';

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function borrowingHistoryRequest($endpoint) {
    $response = supabaseRequest($endpoint, 'GET');

    if (!is_array($response) || ($response['code'] ?? 500) >= 400) {
        throw new Exception('Unable to retrieve borrowing records.');
    }

    return $response;
}

try {
    $requestResponse = borrowingHistoryRequest(
        'equipment_borrow_requests?participant_id=eq.' . rawurlencode($participantId) .
        '&select=*&order=created_at.desc'
    );

    $requests = is_array($requestResponse['data'] ?? null) ? $requestResponse['data'] : [];

    $equipmentResponse = borrowingHistoryRequest('equipment_items?select=id,name,image_url,category');

    foreach (($equipmentResponse['data'] ?? []) as $item) {
        $equipmentMap[(string)($item['id'] ?? '')] = $item;
    }

} catch (Exception $e) {
    $errorMsg = $e->getMessage();
}

function statusClass($status) {
    switch ($status) {
        case 'Approved': return 'success';
        case 'Rejected': return 'danger';
        case 'Returned': return 'secondary';
        default: return 'warning text-dark';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Borrowings - EMIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body { background:#f5f7fb; }
        .item-thumb { width:64px;height:54px;object-fit:cover;border-radius:9px;background:#edf1f6; }
        .status-pill { min-width:86px; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold" href="dashboard.php">EMIS Participant Portal</a>
        <div class="d-flex gap-2">
            <a href="equipment.php" class="btn btn-outline-info btn-sm">Browse Equipment</a>
            <a href="dashboard.php" class="btn btn-outline-light btn-sm">Dashboard</a>
            <a href="../logout.php" class="btn btn-outline-danger btn-sm">Logout</a>
        </div>
    </div>
</nav>

<main class="container py-4 py-md-5">
    <div class="mb-4">
        <div class="text-primary fw-semibold small text-uppercase mb-1">Equipment & Services</div>
        <h1 class="h3 fw-bold mb-1">My Borrowings</h1>
        <p class="text-muted mb-0">Track your equipment requests and their current status.</p>
    </div>

    <?php if ($errorMsg): ?>
        <div class="alert alert-danger"><?= h($errorMsg) ?></div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">Equipment</th>
                            <th>Quantity</th>
                            <th>Borrow / Return</th>
                            <th>Purpose</th>
                            <th>Status</th>
                            <th class="pe-4">Requested</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$requests): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">
                                <i class="bi bi-inbox display-6 d-block mb-2"></i>
                                You haven't submitted any borrowing requests yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($requests as $request):
                            $item = $equipmentMap[(string)($request['equipment_id'] ?? '')] ?? [];
                            $status = $request['status'] ?? 'Pending';
                            $requestedAt = '—';

                            if (!empty($request['created_at'])) {
                                try {
                                    $date = new DateTime($request['created_at']);
                                    $date->setTimezone(new DateTimeZone('Asia/Manila'));
                                    $requestedAt = $date->format('M d, Y g:i A');
                                } catch (Exception $e) {}
                            }
                        ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="d-flex align-items-center gap-3">
                                        <?php if (!empty($item['image_url'])): ?>
                                            <img class="item-thumb" src="<?= h($item['image_url']) ?>" alt="<?= h($item['name'] ?? 'Equipment') ?>">
                                        <?php else: ?>
                                            <div class="item-thumb d-flex align-items-center justify-content-center">
                                                <i class="bi bi-box-seam text-secondary"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="fw-semibold"><?= h($item['name'] ?? 'Equipment no longer listed') ?></div>
                                            <small class="text-muted"><?= h($item['category'] ?? '') ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td><?= (int)($request['quantity'] ?? 0) ?></td>
                                <td class="small">
                                    <?= h($request['borrow_date'] ?? '—') ?><br>
                                    <span class="text-muted">to <?= h($request['expected_return_date'] ?? '—') ?></span>
                                </td>
                                <td style="min-width:180px;max-width:260px;white-space:normal"><?= h($request['purpose'] ?? '—') ?></td>
                                <td>
                                    <span class="badge status-pill bg-<?= h(statusClass($status)) ?>"><?= h($status) ?></span>
                                </td>
                                <td class="pe-4 small text-muted"><?= h($requestedAt) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <p class="text-muted small mt-3">
        <i class="bi bi-info-circle me-1"></i>
        Approved requests are not final until the equipment is handed over. Please coordinate with the equipment administrator or staff.
    </p>
</main>
</body>
</html>
