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
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#0d0916"><title>My Borrowings - EMIS</title><link rel="stylesheet" href="../css/style.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"></head>
<body class="participant-page borrowings-page">
<?php include '../includes/participant-nav.php'; ?>
<main class="borrowings-main">
 <header class="borrowings-page-intro"><div><span class="borrowings-kicker">EQUIPMENT CENTER</span><h1>My Borrowings</h1><p>Everything you have requested, with its current status and return details.</p></div><div class="borrowings-total"><span>REQUESTS</span><strong><?= count($requests) ?></strong></div></header>
 <?php if ($errorMsg): ?><div class="borrowings-alert" role="alert"><i class="bi bi-exclamation-circle"></i><?= h($errorMsg) ?></div><?php endif; ?>
 <?php if (!$requests): ?>
  <section class="borrowings-empty"><div class="borrowings-empty-icon"><i class="bi bi-box-seam"></i></div><h2>No borrowings yet</h2><p>Your equipment requests will appear here after you submit one.</p><a href="equipment.php">Explore equipment <i class="bi bi-arrow-up-right"></i></a></section>
 <?php else: ?>
  <section class="borrowings-list">
  <?php foreach ($requests as $request):
   $item=$equipmentMap[(string)($request['equipment_id']??'')]??[];
   $status=$request['status']??'Pending';
   $statusSlug=strtolower(preg_replace('/[^a-z]/i','',$status));
   $requestedAt='—';
   if(!empty($request['created_at'])){try{$date=new DateTime($request['created_at']);$date->setTimezone(new DateTimeZone('Asia/Manila'));$requestedAt=$date->format('M d, Y · g:i A');}catch(Exception $e){}}
   $returnedLabel='';
   if($status==='Returned'&&!empty($request['returned_at'])){try{$returnedDate=new DateTime($request['returned_at']);$returnedDate->setTimezone(new DateTimeZone('Asia/Manila'));$returnedLabel=$returnedDate->format('M d, Y · g:i A');}catch(Exception $e){$returnedLabel='Recorded';}}
  ?>
   <article class="borrowing-card">
    <div class="borrowing-card-top">
     <div class="borrowing-item-identity">
      <?php if(!empty($item['image_url'])): ?><img class="borrowing-item-image" src="<?= h($item['image_url']) ?>" alt="<?= h($item['name']??'Equipment') ?>" loading="lazy"><?php else: ?><div class="borrowing-item-image borrowing-item-placeholder"><i class="bi bi-box-seam"></i></div><?php endif; ?>
      <div><span class="borrowing-item-category"><?= h($item['category']??'EQUIPMENT') ?></span><h2><?= h($item['name']??'Equipment no longer listed') ?></h2><span class="borrowing-request-date">Requested <?= h($requestedAt) ?></span></div>
     </div>
     <span class="borrowing-status borrowing-status-<?= h($statusSlug) ?>"><?= h($status) ?></span>
    </div>
    <div class="borrowing-card-details">
     <div class="borrowing-detail"><span><i class="bi bi-boxes"></i> QUANTITY</span><strong><?= (int)($request['quantity']??0) ?></strong></div>
     <div class="borrowing-detail"><span><i class="bi bi-calendar3"></i> BORROW DATE</span><strong><?= h($request['borrow_date']??'—') ?></strong></div>
     <div class="borrowing-detail"><span><i class="bi bi-calendar-check"></i> EXPECTED RETURN</span><strong><?= h($request['expected_return_date']??'—') ?></strong></div>
    </div>
    <div class="borrowing-card-locations">
     <div><span><i class="bi bi-box-arrow-in-down"></i> PICKUP LOCATION</span><p><?= h($request['pickup_location']??'Will be provided after approval') ?></p></div>
     <div><span><i class="bi bi-box-arrow-up"></i> RETURN LOCATION</span><p><?= h($request['return_location']??'Will be provided after approval') ?></p></div>
    </div>
    <?php if(!empty($request['purpose'])||!empty($request['pickup_instructions'])): ?><div class="borrowing-card-notes">
     <?php if(!empty($request['purpose'])): ?><div><span>PURPOSE</span><p><?= h($request['purpose']) ?></p></div><?php endif; ?>
     <?php if(!empty($request['pickup_instructions'])): ?><div><span>ADDITIONAL INSTRUCTIONS</span><p><?= nl2br(h($request['pickup_instructions'])) ?></p></div><?php endif; ?>
    </div><?php endif; ?>
    <?php if($returnedLabel): ?><div class="borrowing-return-confirmed"><i class="bi bi-check-circle-fill"></i> Return confirmed <?= h($returnedLabel) ?></div><?php elseif($status==='Approved'): ?><div class="borrowing-return-pending"><i class="bi bi-hourglass-split"></i> Awaiting return confirmation</div><?php endif; ?>
   </article>
  <?php endforeach; ?>
  </section>
 <?php endif; ?>
</main></body></html>