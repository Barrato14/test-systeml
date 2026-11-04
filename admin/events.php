<?php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

// Ensure user is an Administrator
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role']) !== 'administrator') {
    header("Location: ../login.php");
    exit;
}

$successMsg = '';
$errorMsg = '';

// --- HANDLE DELETE EVENT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $deleteId = $_POST['event_id'] ?? '';
    
    if (!empty($deleteId)) {
        $response = supabaseRequest('events?id=eq.' . $deleteId, 'DELETE');

        if ($response['code'] >= 400) {
            $errorMsg = "Failed to delete event. It may be linked to existing attendance records.";
        } else {
            $successMsg = "Event deleted successfully!";
        }
    }
}

// --- HANDLE CREATE EVENT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $payload = [
        'title' => trim($_POST['title']),
        'description' => trim($_POST['description']),
        'venue' => trim($_POST['venue']),
        'start_datetime' => $_POST['start_datetime'],
        'end_datetime' => $_POST['end_datetime'],
        'capacity' => (int)$_POST['capacity'],
        'status' => 'Upcoming'
    ];

    $response = supabaseRequest('events', 'POST', $payload);

    if ($response['code'] >= 400) {
        $errorMsg = "Failed to create event. HTTP Code: {$response['code']}.";
    } else {
        $successMsg = "Event created successfully!";
    }
}

// --- FETCH ALL EVENTS ---
$getResponse = supabaseRequest('events?order=start_datetime.asc', 'GET');
$events = ($getResponse['code'] < 400 && is_array($getResponse['data'])) ? $getResponse['data'] : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Management - EMIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        .sidebar { min-height: 100vh; background-color: #343a40; color: white; }
        .sidebar a { color: #cfd8dc; text-decoration: none; padding: 12px 20px; display: block; }
        .sidebar a:hover, .sidebar a.active { background-color: #495057; color: white; border-left: 4px solid #0d6efd; }
        .content-area { padding: 30px; background-color: #f8f9fa; min-height: 100vh; }
    </style>
</head>
<body>

<div class="d-flex">
    <!-- Sidebar -->
    <div class="sidebar flex-shrink-0" style="width: 250px;">
        <div class="p-4 text-center border-bottom border-secondary">
            <h4 class="mb-0">EMIS Admin</h4>
        </div>
        <nav class="mt-3">
            <a href="dashboard.php" class="active"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a>
            <a href="events.php"><i class="bi bi-calendar-event me-2"></i> Event Management</a>
            <a href="equipment.php"><i class="bi bi-tools me-2"></i> Equipment & Services</a>
            <a href="users.php"><i class="bi bi-people me-2"></i> Users & Roles</a>
            <a href="reports.php"><i class="bi bi-bar-chart me-2"></i> Reports & Audits</a>
            <a href="attendance-logs.php"><i class="bi bi-person-bounding-box me-2"></i> Attendance Logs</a>
            <a href="../logout.php" class="text-danger mt-5"><i class="bi bi-box-arrow-right me-2"></i> Logout</a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="content-area flex-grow-1">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">Event Management</h2>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createEventModal">
                <i class="bi bi-plus-circle me-2"></i> Create Event
            </button>
        </div>

        <?php if ($successMsg): ?><div class="alert alert-success"><?= $successMsg ?></div><?php endif; ?>
        <?php if ($errorMsg): ?><div class="alert alert-danger"><?= $errorMsg ?></div><?php endif; ?>

        <!-- Events Table -->
        <div class="card shadow-sm">
            <div class="card-body p-0">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Title</th>
                            <th>Venue</th>
                            <th>Date & Time</th>
                            <th>Capacity</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($events)): ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted">No events found. Create one above!</td></tr>
                        <?php else: ?>
                            <?php foreach ($events as $event): ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($event['title'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($event['venue'] ?? '') ?></td>
                                    <td>
                                        <small class="d-block text-muted">Start: <?= isset($event['start_datetime']) ? date('M d, Y h:i A', strtotime($event['start_datetime'])) : '' ?></small>
                                        <small class="d-block text-muted">End: <?= isset($event['end_datetime']) ? date('M d, Y h:i A', strtotime($event['end_datetime'])) : '' ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($event['capacity'] ?? 0) ?></td>
                                    <td>
                                        <span class="badge bg-<?= ($event['status'] ?? '') === 'Upcoming' ? 'success' : 'secondary' ?>">
                                            <?= htmlspecialchars($event['status'] ?? 'Upcoming') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <!-- Copyable Event ID input box -->
                                            <div class="input-group input-group-sm" style="width: 220px;">
                                                <input type="text" class="form-control form-control-sm font-monospace text-muted" value="<?= htmlspecialchars($event['id']) ?>" id="event-id-<?= $event['id'] ?>" readonly>
                                                <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('event-id-<?= $event['id'] ?>').value); alert('Event ID copied to clipboard!');" title="Copy ID">
                                                    <i class="bi bi-clipboard"></i>
                                                </button>
                                            </div>
                                            
                                            <!-- Delete Button Form -->
                                            <form method="POST" class="mb-0" onsubmit="return confirm('Are you sure you want to delete this event?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="event_id" value="<?= htmlspecialchars($event['id']) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Event">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Create Event Modal -->
<div class="modal fade" id="createEventModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Create New Event</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Event Title</label>
                        <input type="text" name="title" class="form-control" required placeholder="e.g., Annual Tech Symposium">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Description</label>
                        <textarea name="description" class="form-control" rows="3" required></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Venue</label>
                            <input type="text" name="venue" class="form-control" required placeholder="e.g., Main Auditorium">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Participant Capacity</label>
                            <input type="number" name="capacity" class="form-control" required min="1" value="100">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Start Date & Time</label>
                            <input type="datetime-local" name="start_datetime" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">End Date & Time</label>
                            <input type="datetime-local" name="end_datetime" class="form-control" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Event</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
