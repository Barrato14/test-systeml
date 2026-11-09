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
$successMsg = '';
$errorMsg = '';

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function equipmentPageRequest($endpoint, $method = 'GET', $payload = null) {
    $response = supabaseRequest($endpoint, $method, $payload);

    if (!is_array($response) || ($response['code'] ?? 500) >= 400) {
        $detail = is_array($response['data'] ?? null)
            ? ($response['data']['message'] ?? $response['data']['details'] ?? '')
            : '';
        throw new Exception('Database request failed.' . ($detail ? ' ' . $detail : ''));
    }

    return $response;
}

function getEquipmentAvailability($equipmentId, $totalQuantity) {
    $response = equipmentPageRequest(
        'equipment_borrow_requests?equipment_id=eq.' . rawurlencode($equipmentId) .
        '&status=eq.Approved&select=quantity',
        'GET'
    );

    $borrowed = 0;
    foreach (($response['data'] ?? []) as $request) {
        $borrowed += (int)($request['quantity'] ?? 0);
    }

    return max(0, (int)$totalQuantity - $borrowed);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_borrow_request') {
    try {
        $equipmentId = trim((string)($_POST['equipment_id'] ?? ''));
        $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
        $purpose = trim((string)($_POST['purpose'] ?? ''));
        $borrowDate = trim((string)($_POST['borrow_date'] ?? ''));
        $returnDate = trim((string)($_POST['expected_return_date'] ?? ''));

        if ($equipmentId === '' || $quantity === false || $quantity < 1) {
            throw new Exception('Choose equipment and enter a valid quantity.');
        }
        if ($purpose === '') {
            throw new Exception('Please provide the purpose of borrowing.');
        }
        if ($borrowDate === '' || $returnDate === '') {
            throw new Exception('Please select both borrowing and expected return dates.');
        }

        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
        $borrow = DateTimeImmutable::createFromFormat('!Y-m-d', $borrowDate);
        $return = DateTimeImmutable::createFromFormat('!Y-m-d', $returnDate);

        if (!$borrow || !$return || $borrow->format('Y-m-d') !== $borrowDate || $return->format('Y-m-d') !== $returnDate) {
            throw new Exception('Please enter valid dates.');
        }
        if ($borrow < $today) {
            throw new Exception('The borrowing date cannot be in the past.');
        }
        if ($return < $borrow) {
            throw new Exception('The expected return date cannot be earlier than the borrowing date.');
        }

        $itemResponse = equipmentPageRequest(
            'equipment_items?id=eq.' . rawurlencode($equipmentId) . '&select=id,name,quantity,condition_status',
            'GET'
        );

        if (empty($itemResponse['data'])) {
            throw new Exception('The selected equipment could not be found.');
        }

        $item = $itemResponse['data'][0];

        if (($item['condition_status'] ?? 'Available') !== 'Available') {
            throw new Exception('This equipment is currently unavailable for borrowing.');
        }

        $available = getEquipmentAvailability($equipmentId, (int)($item['quantity'] ?? 0));

        if ($quantity > $available || $available < 1) {
            throw new Exception('Not enough units are currently available. Available quantity: ' . $available . '.');
        }

        $pendingResponse = equipmentPageRequest(
            'equipment_borrow_requests?participant_id=eq.' . rawurlencode($participantId) .
            '&equipment_id=eq.' . rawurlencode($equipmentId) . '&status=eq.Pending&select=id',
            'GET'
        );

        if (!empty($pendingResponse['data'])) {
            throw new Exception('You already have a pending request for this equipment.');
        }

        $payload = [
            'equipment_id' => $equipmentId,
            'participant_id' => $participantId,
            'quantity' => (int)$quantity,
            'purpose' => $purpose,
            'borrow_date' => $borrowDate,
            'expected_return_date' => $returnDate,
            'status' => 'Pending'
        ];

        equipmentPageRequest('equipment_borrow_requests', 'POST', $payload);
        header('Location: equipment.php?submitted=1');
        exit;

    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
    }
}

if (isset($_GET['submitted'])) {
    $successMsg = 'Your borrowing request has been submitted. You can track it under My Borrowings.';
}

$equipment = [];
$availability = [];

try {
    $equipmentResponse = equipmentPageRequest('equipment_items?select=*&order=created_at.desc', 'GET');
    $equipment = is_array($equipmentResponse['data'] ?? null) ? $equipmentResponse['data'] : [];

    foreach ($equipment as $item) {
        $id = (string)($item['id'] ?? '');
        if ($id !== '') {
            $availability[$id] = getEquipmentAvailability($id, (int)($item['quantity'] ?? 0));
        }
    }

} catch (Exception $e) {
    $errorMsg = $errorMsg ?: 'Unable to load the equipment catalog right now. Please refresh the page.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Borrow Equipment - EMIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body { background:#f5f7fb; }
        .equipment-card { border:0; border-radius:16px; overflow:hidden; transition:transform .18s ease,box-shadow .18s ease; }
        .equipment-card:hover { transform:translateY(-3px); box-shadow:0 12px 28px rgba(15,23,42,.10); }
        .equipment-photo { height:210px; width:100%; object-fit:cover; background:#e9edf3; }
        .equipment-placeholder { height:210px; display:flex; align-items:center; justify-content:center; background:#edf1f6; color:#94a3b8; font-size:3rem; }
    </style>
<link rel="stylesheet" href="../css/style.css">\n</head>
<body class="participant-page">
<?php include '../includes/participant-nav.php'; ?>

<main class="container py-4 py-md-5">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
        <div>
            <div class="text-primary fw-semibold small text-uppercase mb-1">Equipment & Services</div>
            <h1 class="h3 fw-bold mb-1">Borrow Equipment</h1>
            <p class="text-muted mb-0">Browse school equipment and submit a borrowing request.</p>
        </div>
        <a href="my-borrowings.php" class="btn btn-outline-primary"><i class="bi bi-clipboard-check me-1"></i> View My Borrowings</a>
    </div>

    <?php if ($successMsg): ?><div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?= h($successMsg) ?></div><?php endif; ?>
    <?php if ($errorMsg): ?><div class="alert alert-danger"><?= h($errorMsg) ?></div><?php endif; ?>

    <div class="row g-4">
        <?php if (empty($equipment)): ?>
            <div class="col-12"><div class="card border-0 shadow-sm rounded-4"><div class="card-body text-center py-5">
                <i class="bi bi-box-seam display-4 text-muted"></i>
                <h5 class="mt-3">No equipment listed yet</h5>
                <p class="text-muted mb-0">Please check again later.</p>
            </div></div></div>
        <?php else: ?>
            <?php foreach ($equipment as $item):
                $id = (string)($item['id'] ?? '');
                $qty = (int)($availability[$id] ?? 0);
                $condition = $item['condition_status'] ?? 'Available';
                $canBorrow = $condition === 'Available' && $qty > 0;
            ?>
                <div class="col-sm-6 col-lg-4">
                    <div class="card equipment-card shadow-sm h-100">
                        <?php if (!empty($item['image_url'])): ?>
                            <img src="<?= h($item['image_url']) ?>" class="equipment-photo" alt="<?= h($item['name'] ?? 'Equipment') ?>">
                        <?php else: ?>
                            <div class="equipment-placeholder"><i class="bi bi-image"></i></div>
                        <?php endif; ?>
                        <div class="card-body d-flex flex-column p-4">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                <h5 class="fw-bold mb-0"><?= h($item['name'] ?? 'Unnamed equipment') ?></h5>
                                <?php if ($canBorrow): ?>
                                    <span class="badge text-bg-success">Available</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary"><?= h($condition !== 'Available' ? $condition : 'Out of stock') ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="small text-primary fw-semibold mb-2"><?= h($item['category'] ?? 'General equipment') ?></div>
                            <p class="text-muted small flex-grow-1"><?= nl2br(h($item['description'] ?? 'No description provided.')) ?></p>
                            <div class="d-flex justify-content-between align-items-center border-top pt-3 mb-3">
                                <span class="small text-muted">Available quantity</span>
                                <strong><?= $qty ?> / <?= (int)($item['quantity'] ?? 0) ?></strong>
                            </div>
                            <button type="button" class="btn btn-primary w-100" <?= $canBorrow ? '' : 'disabled' ?>
                                data-bs-toggle="modal" data-bs-target="#borrowModal"
                                data-equipment-id="<?= h($id) ?>"
                                data-equipment-name="<?= h($item['name'] ?? '') ?>"
                                data-available="<?= $qty ?>">
                                <i class="bi bi-box-arrow-in-down me-1"></i> Request to Borrow
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>

<div class="modal fade" id="borrowModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4 shadow">
            <form method="POST">
                <div class="modal-header bg-dark text-white">
                    <div><h5 class="modal-title mb-1">Borrowing Request</h5><small id="selectedEquipmentLabel" class="text-white-50"></small></div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="submit_borrow_request">
                    <input type="hidden" name="equipment_id" id="borrowEquipmentId">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Quantity</label>
                        <input type="number" class="form-control" name="quantity" id="borrowQuantity" min="1" value="1" required>
                        <div class="form-text" id="availableQuantityHelp"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Purpose</label>
                        <textarea class="form-control" name="purpose" rows="3" maxlength="1000" required placeholder="Explain why you need to borrow this equipment."></textarea>
                    </div>
                    <div class="row g-3">
                        <div class="col-6"><label class="form-label fw-semibold">Borrow date</label><input type="date" class="form-control" name="borrow_date" min="<?= h(date('Y-m-d')) ?>" required></div>
                        <div class="col-6"><label class="form-label fw-semibold">Expected return</label><input type="date" class="form-control" name="expected_return_date" min="<?= h(date('Y-m-d')) ?>" required></div>
                    </div>
                    <div class="alert alert-info small mt-3 mb-0">Your request will remain pending until an administrator or event organizer reviews it.</div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Submit Request</button></div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const borrowModal = document.getElementById('borrowModal');
borrowModal.addEventListener('show.bs.modal', function (event) {
    const button = event.relatedTarget;
    const available = parseInt(button.dataset.available || '0', 10);
    document.getElementById('borrowEquipmentId').value = button.dataset.equipmentId || '';
    document.getElementById('selectedEquipmentLabel').textContent = button.dataset.equipmentName || '';
    document.getElementById('borrowQuantity').max = available;
    document.getElementById('borrowQuantity').value = 1;
    document.getElementById('availableQuantityHelp').textContent = available + ' unit(s) currently available.';
});
</script>
</body>
</html>
