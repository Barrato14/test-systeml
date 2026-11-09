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
<link rel="stylesheet" href="../css/style.css">
</head>
<body class="participant-page">
<?php include '../includes/participant-nav.php'; ?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#0d0916"><title>My Borrowings - EMIS</title>
<link rel="stylesheet" href="../css/style.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head><body class="participant-page borrowings-page">
<?php include '../includes/participant-nav.php'; ?>
<main class="borrowings-main">
<section class="portal-page-heading">
<div class="portal-page-heading-copy"><span class="portal-page-eyebrow"><i class="bi bi-box-seam"></i> EQUIPMENT & SERVICES</span><h1>My Borrowings</h1><p>Track your equipment requests, pickup details, and return status in one place.</p></div>
<a href="dashboard.php" class="portal-back-link"><i class="bi bi-arrow-left"></i><span>Dashboard</span></a>
</section>
<section class="borrowings-overview"><div class="borrowings-overview-icon"><i class="bi bi-clipboard2-check"></i></div><div><span class="borrowings-overview-label">YOUR REQUEST HISTORY</span><strong><?= count($requests) ?> <span><?= count($requests) === 1 ? 'request' : 'requests' ?></span></strong></div><span class="borrowings-overview-note"><i class="bi bi-arrow-repeat"></i> Status updates appear here</span></section>
<?php if ($errorMsg): ?><div class="borrowings-alert" role="alert"><i class="bi bi-exclamation-circle-fill"></i><?= h($errorMsg) ?></div><?php endif; ?>
<section class="borrowings-section">
<div class="borrowings-section-heading"><div><span class="borrowings-section-kicker">EQUIPMENT ACTIVITY</span><h2>Borrowing requests</h2></div><span class="borrowings-count"><?= count($requests) ?> total</span></div>
<?php if (!$requests): ?>
<div class="borrowings-empty"><div class="borrowings-empty-icon"><i class="bi bi-inbox"></i></div><h3>No borrowing requests yet</h3><p>When you request equipment, its details and approval status will appear here.</p><a href="equipment.php" class="borrowings-empty-link">Browse equipment <i class="bi bi-arrow-right"></i></a></div>
<?php else: ?>
<div class="borrowings-table-shell"><div class="borrowings-table-scroll"><table class="borrowings-table">
<thead><tr><th>Equipment</th><th>Quantity</th><th>Borrow / Return</th><th>Pickup / Return details</th><th>Purpose</th><th>Status</th><th>Requested</th></tr></thead><tbody>
<?php foreach ($requests as $request):
$item = $equipmentMap[(string)($request['equipment_id'] ?? '')] ?? [];
$status = $request['status'] ?? 'Pending';
$requestedAt = '—';
if (!empty($request['created_at'])) { try {$date = new DateTime($request['created_at']);$date->setTimezone(new DateTimeZone('Asia/Manila'));$requestedAt = $date->format('M d, Y g:i A');} catch (Exception $e) {} }
?>
<tr>
<td><div class="borrowing-equipment"><?php if (!empty($item['image_url'])): ?><img class="borrowing-thumb" src="<?= h($item['image_url']) ?>" alt="<?= h($item['name'] ?? 'Equipment') ?>" loading="lazy"><?php else: ?><div class="borrowing-thumb borrowing-thumb-fallback"><i class="bi bi-box-seam"></i></div><?php endif; ?><div><strong><?= h($item['name'] ?? 'Equipment no longer listed') ?></strong><span><?= h($item['category'] ?? 'Equipment') ?></span></div></div></td>
<td><span class="borrowing-quantity"><?= (int)($request['quantity'] ?? 0) ?></span></td>
<td><div class="borrowing-dates"><strong><?= h($request['borrow_date'] ?? '—') ?></strong><span>to <?= h($request['expected_return_date'] ?? '—') ?></span></div></td>
<td><div class="borrowing-location"><div><strong><i class="bi bi-box-arrow-in-down"></i> Pickup</strong><span><?= h($request['pickup_location'] ?? 'Will be provided after approval') ?></span></div><div><strong><i class="bi bi-box-arrow-up"></i> Return</strong><span><?= h($request['return_location'] ?? 'Will be provided after approval') ?></span></div>
<?php if (!empty($request['pickup_instructions'])): ?><small><i class="bi bi-info-circle"></i> <?= nl2br(h($request['pickup_instructions'])) ?></small><?php endif; ?>
<?php if ($status === 'Returned' && !empty($request['returned_at'])): try {$returnedDate = new DateTime($request['returned_at']);$returnedDate->setTimezone(new DateTimeZone('Asia/Manila'));$returnedLabel = $returnedDate->format('M d, Y g:i A');} catch (Exception $e) {$returnedLabel = 'Recorded';} ?><small class="borrowing-returned"><i class="bi bi-check-circle-fill"></i> Return confirmed: <?= h($returnedLabel) ?></small><?php elseif ($status === 'Approved'): ?><small class="borrowing-awaiting"><i class="bi bi-hourglass-split"></i> Awaiting return confirmation</small><?php endif; ?></div></td>
<td><div class="borrowing-purpose"><?= h($request['purpose'] ?? '—') ?></div></td>
<td><span class="borrowing-status borrowing-status-<?= strtolower(preg_replace('/[^a-z]/i', '', $status)) ?>"><?= h($status) ?></span></td><td><span class="borrowing-requested"><?= h($requestedAt) ?></span></td>
</tr><?php endforeach; ?></tbody></table></div><div class="borrowings-scroll-hint"><i class="bi bi-arrows"></i> Swipe or scroll horizontally to view all details</div></div>
<?php endif; ?></section>
<p class="borrowings-help"><i class="bi bi-info-circle"></i><span>Once approved, check the pickup and return details above. Staff will confirm the return after physically receiving the item, and the confirmation will appear here.</span></p>
</main></body></html>