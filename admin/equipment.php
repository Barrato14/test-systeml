<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once '../config/database.php';
require_once '../includes/auth.php';

$allowedRoles = ['administrator', 'event organizer', 'staff'];
$currentRole = strtolower($_SESSION['user_role'] ?? '');

if (!isset($_SESSION['user_id']) || !in_array($currentRole, $allowedRoles, true)) {
    header("Location: ../login.php");
    exit;
}

$successMsg = '';
$errorMsg = '';

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function equipmentStorageUpload($file) {
    global $supabaseUrl;

    // Keep the service-role key on the server only. Never place it in HTML or JavaScript.
    $storageKey = getenv('SUPABASE_SERVICE_ROLE_KEY');
    if (!$storageKey) {
        $localEnvPath = __DIR__ . '/../.env';
        $localEnv = file_exists($localEnvPath) ? parse_ini_file($localEnvPath) : [];
        $storageKey = $localEnv['SUPABASE_SERVICE_ROLE_KEY'] ?? '';
    }
    if (!$storageKey) {
        throw new Exception('Image upload is not configured yet. Add SUPABASE_SERVICE_ROLE_KEY to your server environment.');
    }

    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Please choose a valid image to upload.');
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new Exception('The image must be 5 MB or smaller.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];

    if (!isset($extensions[$mime])) {
        throw new Exception('Only JPG, PNG, and WEBP images are allowed.');
    }

    $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    $objectPath = 'equipment/' . date('Y/m') . '/' . $fileName;
    $url = rtrim($supabaseUrl, '/') . '/storage/v1/object/equipment-images/' . $objectPath;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => file_get_contents($file['tmp_name']),
        CURLOPT_HTTPHEADER => [
            'apikey: ' . $storageKey,
            'Authorization: Bearer ' . $storageKey,
            'Content-Type: ' . $mime,
            'x-upsert: false'
        ],
        CURLOPT_TIMEOUT => 30
    ]);

    $responseBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false || $httpCode < 200 || $httpCode >= 300) {
        error_log('EMIS equipment image upload failed: HTTP ' . $httpCode . ' ' . $curlError . ' ' . (string)$responseBody);
        throw new Exception('Image upload failed. Check the equipment-images bucket and server storage key.');
    }

    return rtrim($supabaseUrl, '/') . '/storage/v1/object/public/equipment-images/' . $objectPath;
}

function equipmentRequest($endpoint, $method = 'GET', $payload = null) {
    $response = supabaseRequest($endpoint, $method, $payload);
    if (!is_array($response) || ($response['code'] ?? 500) >= 400) {
        $detail = is_array($response['data'] ?? null)
            ? ($response['data']['message'] ?? $response['data']['details'] ?? '')
            : '';
        throw new Exception('Supabase request failed.' . ($detail ? ' ' . $detail : ''));
    }
    return $response;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_equipment') {
            if ($currentRole === 'staff') {
                throw new Exception('Staff members cannot add or edit equipment.');
            }

            $id = trim($_POST['equipment_id'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
            $condition = $_POST['condition_status'] ?? 'Available';

            if ($name === '' || $quantity === false || $quantity < 1) {
                throw new Exception('Enter an equipment name and a quantity of at least 1.');
            }
            if (!in_array($condition, ['Available', 'Maintenance', 'Unavailable'], true)) {
                throw new Exception('Invalid equipment condition.');
            }

            $payload = [
                'name' => $name,
                'category' => $category,
                'description' => $description,
                'quantity' => $quantity,
                'condition_status' => $condition
            ];

            if (!empty($_FILES['equipment_image']['name'])) {
                $payload['image_url'] = equipmentStorageUpload($_FILES['equipment_image']);
            }

            if ($id !== '') {
                equipmentRequest('equipment_items?id=eq.' . rawurlencode($id), 'PATCH', $payload);
                $successMsg = 'Equipment updated successfully.';
            } else {
                equipmentRequest('equipment_items', 'POST', $payload);
                $successMsg = 'Equipment added successfully.';
            }
        } elseif ($action === 'delete_equipment') {
            if ($currentRole !== 'administrator') {
                throw new Exception('Only administrators can delete equipment.');
            }

            $id = trim($_POST['equipment_id'] ?? '');
            if ($id === '') {
                throw new Exception('Missing equipment ID.');
            }

            $active = equipmentRequest(
                'equipment_borrow_requests?equipment_id=eq.' . rawurlencode($id) . '&status=eq.Approved&select=id',
                'GET'
            );
            if (!empty($active['data'])) {
                throw new Exception('This equipment has an active approved borrowing. Mark it returned before deleting it.');
            }

            equipmentRequest('equipment_items?id=eq.' . rawurlencode($id), 'DELETE');
            $successMsg = 'Equipment deleted successfully.';
        } elseif ($action === 'update_condition') {
            if ($currentRole === 'staff') {
                throw new Exception('Staff members cannot change equipment condition.');
            }

            $id = trim($_POST['equipment_id'] ?? '');
            $condition = $_POST['condition_status'] ?? '';
            if ($id === '' || !in_array($condition, ['Available', 'Maintenance', 'Unavailable'], true)) {
                throw new Exception('Invalid equipment condition update.');
            }

            equipmentRequest('equipment_items?id=eq.' . rawurlencode($id), 'PATCH', [
                'condition_status' => $condition
            ]);
            $successMsg = 'Equipment condition updated.';
        } elseif ($action === 'review_request') {
            if (!in_array($currentRole, ['administrator', 'event organizer'], true)) {
                throw new Exception('Only administrators and event organizers can review borrowing requests.');
            }

            $requestId = trim($_POST['request_id'] ?? '');
            $decision = $_POST['decision'] ?? '';
            if ($requestId === '' || !in_array($decision, ['Approved', 'Rejected'], true)) {
                throw new Exception('Invalid borrowing request action.');
            }

            $requestCheck = equipmentRequest(
                'equipment_borrow_requests?id=eq.' . rawurlencode($requestId) . '&select=id,equipment_id,quantity,status',
                'GET'
            );
            if (empty($requestCheck['data'])) {
                throw new Exception('Borrowing request not found.');
            }

            $borrowRequest = $requestCheck['data'][0];
            if (($borrowRequest['status'] ?? '') !== 'Pending') {
                throw new Exception('This request has already been reviewed.');
            }

            if ($decision === 'Approved') {
                $itemResponse = equipmentRequest(
                    'equipment_items?id=eq.' . rawurlencode($borrowRequest['equipment_id']) . '&select=id,quantity,condition_status',
                    'GET'
                );
                if (empty($itemResponse['data'])) {
                    throw new Exception('The requested equipment no longer exists.');
                }

                $item = $itemResponse['data'][0];
                if (($item['condition_status'] ?? 'Available') !== 'Available') {
                    throw new Exception('This equipment is currently unavailable or under maintenance.');
                }

                $approvedResponse = equipmentRequest(
                    'equipment_borrow_requests?equipment_id=eq.' . rawurlencode($borrowRequest['equipment_id']) .
                    '&status=eq.Approved&select=quantity',
                    'GET'
                );
                $borrowedQty = 0;
                foreach (($approvedResponse['data'] ?? []) as $approved) {
                    $borrowedQty += (int)($approved['quantity'] ?? 0);
                }

                $availableQty = (int)$item['quantity'] - $borrowedQty;
                if ((int)$borrowRequest['quantity'] > $availableQty) {
                    throw new Exception('Not enough units are currently available to approve this request.');
                }
            }

            equipmentRequest('equipment_borrow_requests?id=eq.' . rawurlencode($requestId), 'PATCH', [
                'status' => $decision,
                'reviewed_by' => (string)$_SESSION['user_id'],
                'reviewed_at' => date('c')
            ]);
            $successMsg = 'Borrowing request ' . strtolower($decision) . '.';
        } elseif ($action === 'mark_returned') {
            if (!in_array($currentRole, ['administrator', 'event organizer', 'staff'], true)) {
                throw new Exception('Permission denied.');
            }

            $requestId = trim($_POST['request_id'] ?? '');
            if ($requestId === '') {
                throw new Exception('Missing borrowing request ID.');
            }

            $check = equipmentRequest(
                'equipment_borrow_requests?id=eq.' . rawurlencode($requestId) . '&select=id,status',
                'GET'
            );
            if (empty($check['data']) || ($check['data'][0]['status'] ?? '') !== 'Approved') {
                throw new Exception('Only approved borrowings can be marked as returned.');
            }

            equipmentRequest('equipment_borrow_requests?id=eq.' . rawurlencode($requestId), 'PATCH', [
                'status' => 'Returned',
                'returned_at' => date('c')
            ]);
            $successMsg = 'Equipment return recorded.';
        }
    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
    }
}

// Load inventory and borrowing records.
$equipment = [];
$requests = [];
$loadError = '';

try {
    $equipmentResponse = equipmentRequest('equipment_items?select=*&order=created_at.desc', 'GET');
    $equipment = is_array($equipmentResponse['data'] ?? null) ? $equipmentResponse['data'] : [];

    $requestResponse = equipmentRequest('equipment_borrow_requests?select=*&order=created_at.desc', 'GET');
    $requests = is_array($requestResponse['data'] ?? null) ? $requestResponse['data'] : [];
} catch (Exception $e) {
    $loadError = $e->getMessage();
}

$borrowedByEquipment = [];
$pendingCount = 0;
$borrowedUnits = 0;
foreach ($requests as $request) {
    $status = $request['status'] ?? '';
    if ($status === 'Pending') {
        $pendingCount++;
    } elseif ($status === 'Approved') {
        $equipmentId = (string)($request['equipment_id'] ?? '');
        $qty = (int)($request['quantity'] ?? 0);
        $borrowedByEquipment[$equipmentId] = ($borrowedByEquipment[$equipmentId] ?? 0) + $qty;
        $borrowedUnits += $qty;
    }
}

$totalUnits = 0;
$availableUnits = 0;
foreach ($equipment as $item) {
    $quantity = (int)($item['quantity'] ?? 0);
    $totalUnits += $quantity;
    if (($item['condition_status'] ?? 'Available') === 'Available') {
        $availableUnits += max(0, $quantity - ($borrowedByEquipment[(string)($item['id'] ?? '')] ?? 0));
    }
}

$activeTab = (($_GET['action'] ?? '') === 'approve') ? 'requests' : ($_GET['tab'] ?? 'inventory');
if (!in_array($activeTab, ['inventory', 'requests', 'history'], true)) {
    $activeTab = 'inventory';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Equipment &amp; Services - EMIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body { background:#f4f6fb; color:#273449; }
        .sidebar { min-height:100vh; background:#343a40; color:#fff; }
        .sidebar a { color:#cfd8dc; text-decoration:none; padding:12px 20px; display:block; }
        .sidebar a:hover,.sidebar a.active { background:#495057; color:#fff; border-left:4px solid #0d6efd; }
        .content-area { padding:30px; min-height:100vh; width:calc(100% - 250px); }
        .page-card { background:#fff; border:1px solid #e8edf4; border-radius:15px; box-shadow:0 4px 20px rgba(30,50,80,.05); }
        .stat-card { border:0; border-left:5px solid #0d6efd; border-radius:12px; }
        .equipment-image { width:76px; height:66px; object-fit:cover; border-radius:10px; background:#eef2f7; }
        .nav-pills .nav-link { color:#64748b; font-weight:600; }
        .nav-pills .nav-link.active { background:#343a40; color:#fff; }
        .table thead th { color:#64748b; font-size:.78rem; text-transform:uppercase; letter-spacing:.5px; white-space:nowrap; }
        .form-control,.form-select { border-radius:9px; }
        @media(max-width:900px) {
            .sidebar { width:76px!important; }
            .sidebar .nav-label,.sidebar small,.sidebar h4 { display:none; }
            .sidebar a { padding:14px 22px; }
            .content-area { padding:18px; width:calc(100% - 76px); }
        }
        @media(max-width:600px) {
            .sidebar { display:none; }
            .content-area { width:100%; padding:14px; }
        }
    </style>
</head>
<body>
<div class="d-flex">
    <aside class="sidebar flex-shrink-0" style="width:250px;">
        <div class="p-4 text-center border-bottom border-secondary">
            <h4 class="mb-0">EMIS Admin</h4><small class="text-muted">Command Center</small>
        </div>
        <nav class="mt-3">
            <a href="dashboard.php"><i class="bi bi-speedometer2 me-2"></i><span class="nav-label">Dashboard</span></a>
            <a href="events.php"><i class="bi bi-calendar-event me-2"></i><span class="nav-label">Event Management</span></a>
            <a href="equipment.php" class="active"><i class="bi bi-tools me-2"></i><span class="nav-label">Equipment &amp; Services</span></a>
            <a href="users.php"><i class="bi bi-people me-2"></i><span class="nav-label">Users &amp; Roles</span></a>
            <a href="reports.php"><i class="bi bi-bar-chart me-2"></i><span class="nav-label">Reports &amp; Audits</span></a>
            <a href="attendance-logs.php"><i class="bi bi-person-bounding-box me-2"></i><span class="nav-label">Attendance Logs</span></a>
            <a href="../logout.php" class="text-danger mt-5"><i class="bi bi-box-arrow-right me-2"></i><span class="nav-label">Logout</span></a>
        </nav>
    </aside>

    <main class="content-area flex-grow-1">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <h2 class="fw-bold mb-1">Equipment &amp; Services</h2>
                <div class="text-muted">Manage inventory, availability, and borrowing requests.</div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="text-muted small">Welcome, <strong><?= h($_SESSION['user_name'] ?? 'Admin') ?></strong></span>
                <a href="../logout.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-box-arrow-right"></i></a>
            </div>
        </div>

        <?php if ($successMsg): ?><div class="alert alert-success alert-dismissible fade show"><?= h($successMsg) ?><button class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php if ($errorMsg): ?><div class="alert alert-danger alert-dismissible fade show"><?= h($errorMsg) ?><button class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php if ($loadError): ?>
            <div class="alert alert-warning">
                <strong>Equipment tables are not ready yet.</strong><br>
                <?= h($loadError) ?> Run the SQL setup provided with this file in your Supabase SQL Editor, then reload this page.
            </div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-3"><div class="card stat-card shadow-sm h-100"><div class="card-body"><div class="text-muted small fw-semibold">Equipment Types</div><div class="fs-2 fw-bold"><?= count($equipment) ?></div></div></div></div>
            <div class="col-sm-6 col-xl-3"><div class="card stat-card shadow-sm h-100" style="border-left-color:#198754"><div class="card-body"><div class="text-muted small fw-semibold">Available Units</div><div class="fs-2 fw-bold"><?= $availableUnits ?></div></div></div></div>
            <div class="col-sm-6 col-xl-3"><div class="card stat-card shadow-sm h-100" style="border-left-color:#ffc107"><div class="card-body"><div class="text-muted small fw-semibold">Pending Requests</div><div class="fs-2 fw-bold"><?= $pendingCount ?></div></div></div></div>
            <div class="col-sm-6 col-xl-3"><div class="card stat-card shadow-sm h-100" style="border-left-color:#0dcaf0"><div class="card-body"><div class="text-muted small fw-semibold">Borrowed Units</div><div class="fs-2 fw-bold"><?= $borrowedUnits ?></div></div></div></div>
        </div>

        <div class="page-card p-3 p-lg-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                <ul class="nav nav-pills gap-1">
                    <li class="nav-item"><a class="nav-link <?= $activeTab === 'inventory' ? 'active' : '' ?>" href="equipment.php?tab=inventory"><i class="bi bi-box-seam me-1"></i> Inventory</a></li>
                    <li class="nav-item"><a class="nav-link <?= $activeTab === 'requests' ? 'active' : '' ?>" href="equipment.php?tab=requests"><i class="bi bi-inbox me-1"></i> Requests <?php if ($pendingCount): ?><span class="badge bg-warning text-dark"><?= $pendingCount ?></span><?php endif; ?></a></li>
                    <li class="nav-item"><a class="nav-link <?= $activeTab === 'history' ? 'active' : '' ?>" href="equipment.php?tab=history"><i class="bi bi-clock-history me-1"></i> History</a></li>
                </ul>
                <?php if ($activeTab === 'inventory' && $currentRole !== 'staff'): ?>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#equipmentModal" onclick="prepareAddEquipment()"><i class="bi bi-plus-lg me-1"></i> Add Equipment</button>
                <?php endif; ?>
            </div>

            <?php if ($activeTab === 'inventory'): ?>
                <div class="row g-2 mb-3">
                    <div class="col-md-8"><input type="search" id="equipmentSearch" class="form-control" placeholder="Search equipment by name, category, or description..."></div>
                    <div class="col-md-4"><select id="availabilityFilter" class="form-select"><option value="">All availability</option><option value="Available">Available</option><option value="Borrowed">Borrowed</option><option value="Maintenance">Under maintenance</option><option value="Unavailable">Unavailable</option></select></div>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>Equipment</th><th>Category</th><th>Quantity</th><th>Availability</th><th class="text-end">Actions</th></tr></thead>
                        <tbody id="equipmentTable">
                        <?php if (!$equipment): ?><tr><td colspan="5" class="text-center text-muted py-5">No equipment added yet. Select “Add Equipment” to create your first item.</td></tr><?php endif; ?>
                        <?php foreach ($equipment as $item):
                            $id = (string)($item['id'] ?? '');
                            $qty = (int)($item['quantity'] ?? 0);
                            $borrowed = $borrowedByEquipment[$id] ?? 0;
                            $available = max(0, $qty - $borrowed);
                            $condition = $item['condition_status'] ?? 'Available';
                            $displayStatus = $condition !== 'Available' ? $condition : ($available <= 0 ? 'Borrowed' : 'Available');
                            $badgeClass = $displayStatus === 'Available' ? 'success' : ($displayStatus === 'Borrowed' ? 'warning text-dark' : ($displayStatus === 'Maintenance' ? 'danger' : 'secondary'));
                            $searchText = strtolower(($item['name'] ?? '') . ' ' . ($item['category'] ?? '') . ' ' . ($item['description'] ?? ''));
                        ?>
                            <tr class="equipment-row" data-search="<?= h($searchText) ?>" data-status="<?= h($displayStatus) ?>">
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <?php if (!empty($item['image_url'])): ?><img src="<?= h($item['image_url']) ?>" class="equipment-image" alt="<?= h($item['name']) ?>"><?php else: ?><div class="equipment-image d-flex align-items-center justify-content-center"><i class="bi bi-image text-secondary fs-3"></i></div><?php endif; ?>
                                        <div><div class="fw-bold"><?= h($item['name'] ?? 'Unnamed equipment') ?></div><div class="small text-muted"><?= h($item['description'] ?? '') ?></div></div>
                                    </div>
                                </td>
                                <td><?= h($item['category'] ?? '—') ?></td>
                                <td><strong><?= $available ?></strong> <span class="text-muted">/ <?= $qty ?> available</span></td>
                                <td><span class="badge bg-<?= $badgeClass ?>"><?= h($displayStatus) ?></span></td>
                                <td class="text-end text-nowrap">
                                    <?php if ($currentRole !== 'staff'): ?>
                                        <button class="btn btn-sm btn-outline-primary edit-equipment" data-item="<?= h(json_encode($item, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)) ?>"><i class="bi bi-pencil"></i></button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this equipment item?')"><input type="hidden" name="action" value="delete_equipment"><input type="hidden" name="equipment_id" value="<?= h($id) ?>"><button class="btn btn-sm btn-outline-danger" <?= $currentRole !== 'administrator' ? 'disabled title="Administrator only"' : '' ?>><i class="bi bi-trash"></i></button></form>
                                    <?php endif; ?>
                                    <?php if ($currentRole !== 'staff'): ?>
                                        <button class="btn btn-sm btn-outline-secondary condition-button" data-id="<?= h($id) ?>" data-condition="<?= h($condition) ?>" title="Change condition"><i class="bi bi-sliders"></i></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($activeTab === 'requests'): ?>
                <h5 class="fw-bold mb-3">Borrowing Requests</h5>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>Equipment ID</th><th>Participant ID</th><th>Quantity</th><th>Borrow / Return</th><th>Purpose</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php
                        $pendingRows = array_filter($requests, fn($r) => ($r['status'] ?? '') === 'Pending');
                        ?>
                        <?php if (!$pendingRows): ?><tr><td colspan="7" class="text-center text-muted py-5">There are no pending borrowing requests.</td></tr><?php endif; ?>
                        <?php foreach ($pendingRows as $request): ?>
                            <tr>
                                <td class="small"><?= h($request['equipment_id'] ?? '') ?></td>
                                <td><?= h($request['participant_id'] ?? '') ?></td>
                                <td><?= (int)($request['quantity'] ?? 0) ?></td>
                                <td class="small"><?= h($request['borrow_date'] ?? '—') ?><br><span class="text-muted">to <?= h($request['expected_return_date'] ?? '—') ?></span></td>
                                <td><?= h($request['purpose'] ?? '—') ?></td>
                                <td><span class="badge bg-warning text-dark">Pending</span></td>
                                <td class="text-nowrap">
                                    <?php if (in_array($currentRole, ['administrator', 'event organizer'], true)): ?>
                                    <form method="POST" class="d-inline"><input type="hidden" name="action" value="review_request"><input type="hidden" name="request_id" value="<?= h($request['id'] ?? '') ?>"><input type="hidden" name="decision" value="Approved"><button class="btn btn-sm btn-success" title="Approve"><i class="bi bi-check-lg"></i></button></form>
                                    <form method="POST" class="d-inline"><input type="hidden" name="action" value="review_request"><input type="hidden" name="request_id" value="<?= h($request['id'] ?? '') ?>"><input type="hidden" name="decision" value="Rejected"><button class="btn btn-sm btn-outline-danger" title="Reject"><i class="bi bi-x-lg"></i></button></form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="alert alert-info small mb-0"><i class="bi bi-info-circle me-1"></i> Participant borrowing requests will appear here once the participant-side request form is connected to this module.</div>

            <?php else: ?>
                <h5 class="fw-bold mb-3">Borrowing History</h5>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>Equipment ID</th><th>Participant ID</th><th>Quantity</th><th>Borrow / Return</th><th>Purpose</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php
                        $historyRows = array_filter($requests, fn($r) => in_array(($r['status'] ?? ''), ['Approved','Rejected','Returned'], true));
                        ?>
                        <?php if (!$historyRows): ?><tr><td colspan="7" class="text-center text-muted py-5">No borrowing history yet.</td></tr><?php endif; ?>
                        <?php foreach ($historyRows as $request):
                            $status = $request['status'] ?? '';
                            $badge = $status === 'Returned' ? 'success' : ($status === 'Rejected' ? 'danger' : 'primary');
                        ?>
                            <tr>
                                <td class="small"><?= h($request['equipment_id'] ?? '') ?></td>
                                <td><?= h($request['participant_id'] ?? '') ?></td>
                                <td><?= (int)($request['quantity'] ?? 0) ?></td>
                                <td class="small"><?= h($request['borrow_date'] ?? '—') ?><br><span class="text-muted">to <?= h($request['expected_return_date'] ?? '—') ?></span></td>
                                <td><?= h($request['purpose'] ?? '—') ?></td>
                                <td><span class="badge bg-<?= $badge ?>"><?= h($status) ?></span></td>
                                <td><?php if ($status === 'Approved'): ?><form method="POST" onsubmit="return confirm('Confirm that this equipment has been returned?')"><input type="hidden" name="action" value="mark_returned"><input type="hidden" name="request_id" value="<?= h($request['id'] ?? '') ?>"><button class="btn btn-sm btn-outline-success">Mark returned</button></form><?php else: ?>—<?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <p class="text-muted small mt-3 mb-0">Availability is calculated from total quantity minus approved, not-yet-returned borrowing requests.</p>
    </main>
</div>

<!-- Add / Edit Equipment -->
<div class="modal fade" id="equipmentModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 rounded-4">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header bg-dark text-white"><h5 class="modal-title" id="equipmentModalTitle">Add Equipment</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="save_equipment">
                    <input type="hidden" name="equipment_id" id="equipment_id">
                    <div class="row g-3">
                        <div class="col-md-7"><label class="form-label fw-semibold">Equipment name</label><input type="text" name="name" id="equipment_name" class="form-control" maxlength="150" required></div>
                        <div class="col-md-5"><label class="form-label fw-semibold">Category</label><input type="text" name="category" id="equipment_category" class="form-control" maxlength="100" placeholder="e.g. Audio / Visual"></div>
                        <div class="col-12"><label class="form-label fw-semibold">Description</label><textarea name="description" id="equipment_description" class="form-control" rows="3"></textarea></div>
                        <div class="col-md-6"><label class="form-label fw-semibold">Total quantity</label><input type="number" name="quantity" id="equipment_quantity" class="form-control" min="1" required></div>
                        <div class="col-md-6"><label class="form-label fw-semibold">Condition</label><select name="condition_status" id="equipment_condition" class="form-select"><option value="Available">Available</option><option value="Maintenance">Under maintenance</option><option value="Unavailable">Unavailable</option></select></div>
                        <div class="col-12"><label class="form-label fw-semibold">Equipment image (JPG, PNG, WEBP; max 5 MB)</label><input type="file" name="equipment_image" class="form-control" accept="image/jpeg,image/png,image/webp"><div class="form-text">Leave this blank when editing to keep the current image.</div><img id="equipmentImagePreview" class="mt-3 rounded" style="display:none;max-width:180px;max-height:140px;object-fit:cover" alt="Image preview"></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary"><i class="bi bi-save me-1"></i> Save Equipment</button></div>
            </form>
        </div>
    </div>
</div>

<!-- Change condition -->
<div class="modal fade" id="conditionModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content">
        <form method="POST">
            <div class="modal-header"><h5 class="modal-title">Equipment Condition</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><input type="hidden" name="action" value="update_condition"><input type="hidden" name="equipment_id" id="condition_equipment_id"><label class="form-label">Set condition</label><select name="condition_status" id="condition_status" class="form-select"><option value="Available">Available</option><option value="Maintenance">Under maintenance</option><option value="Unavailable">Unavailable</option></select></div>
            <div class="modal-footer"><button class="btn btn-primary">Update</button></div>
        </form>
    </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function prepareAddEquipment() {
    document.getElementById('equipmentModalTitle').textContent = 'Add Equipment';
    document.getElementById('equipment_id').value = '';
    document.getElementById('equipment_name').value = '';
    document.getElementById('equipment_category').value = '';
    document.getElementById('equipment_description').value = '';
    document.getElementById('equipment_quantity').value = '1';
    document.getElementById('equipment_condition').value = 'Available';
    document.getElementById('equipmentImagePreview').style.display = 'none';
}
document.querySelectorAll('.edit-equipment').forEach(button => {
    button.addEventListener('click', () => {
        const item = JSON.parse(button.dataset.item);
        document.getElementById('equipmentModalTitle').textContent = 'Edit Equipment';
        document.getElementById('equipment_id').value = item.id || '';
        document.getElementById('equipment_name').value = item.name || '';
        document.getElementById('equipment_category').value = item.category || '';
        document.getElementById('equipment_description').value = item.description || '';
        document.getElementById('equipment_quantity').value = item.quantity || 1;
        document.getElementById('equipment_condition').value = item.condition_status || 'Available';
        const preview = document.getElementById('equipmentImagePreview');
        if (item.image_url) { preview.src = item.image_url; preview.style.display = 'block'; }
        else { preview.style.display = 'none'; }
        bootstrap.Modal.getOrCreateInstance(document.getElementById('equipmentModal')).show();
    });
});
document.querySelectorAll('.condition-button').forEach(button => {
    button.addEventListener('click', () => {
        document.getElementById('condition_equipment_id').value = button.dataset.id;
        document.getElementById('condition_status').value = button.dataset.condition;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('conditionModal')).show();
    });
});
document.getElementById('equipmentSearch')?.addEventListener('input', filterEquipment);
document.getElementById('availabilityFilter')?.addEventListener('change', filterEquipment);
function filterEquipment() {
    const query = (document.getElementById('equipmentSearch')?.value || '').toLowerCase();
    const status = document.getElementById('availabilityFilter')?.value || '';
    document.querySelectorAll('.equipment-row').forEach(row => {
        row.style.display = row.dataset.search.includes(query) && (!status || row.dataset.status === status) ? '' : 'none';
    });
}
document.querySelector('input[name="equipment_image"]')?.addEventListener('change', function () {
    const file = this.files?.[0];
    const preview = document.getElementById('equipmentImagePreview');
    if (!file) return;
    if (!['image/jpeg','image/png','image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) {
        alert('Choose a JPG, PNG, or WEBP image up to 5 MB.');
        this.value = '';
        return;
    }
    preview.src = URL.createObjectURL(file);
    preview.style.display = 'block';
});
</script>
</body>
</html>
