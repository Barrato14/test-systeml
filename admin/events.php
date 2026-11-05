<?php
session_start();
date_default_timezone_set('Asia/Manila');
require_once '../config/database.php';
require_once '../includes/auth.php';

// Allowed administrative roles: Administrator, Event Organizer, Staff
$allowedRoles = ['administrator', 'event organizer', 'staff'];
$currentRole = strtolower($_SESSION['user_role'] ?? '');

if (!isset($_SESSION['user_id']) || !in_array($currentRole, $allowedRoles)) {
    header("Location: ../login.php");
    exit;
}

$successMsg = '';
$errorMsg = '';

// --- HANDLE DELETE EVENT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    // Only Administrators and Event Organizers can delete
    if (in_array($currentRole, ['administrator', 'event organizer'])) {
        $deleteId = $_POST['event_id'] ?? '';
        if (!empty($deleteId)) {
            $response = supabaseRequest('events?id=eq.' . $deleteId, 'DELETE');
            if ($response['code'] >= 400) {
                $errorMsg = "Failed to delete event. It may be linked to existing attendance records.";
            } else {
                $successMsg = "Event deleted successfully!";
            }
        }
    } else {
        $errorMsg = "Permission denied. Staff cannot delete events.";
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
        'status' => $_POST['status'] ?? 'Upcoming'
    ];

    $response = supabaseRequest('events', 'POST', $payload);

    if ($response['code'] >= 400) {
        $errorMsg = "Failed to create event. Database error.";
    } else {
        $successMsg = "Event created successfully!";
    }
}

// --- HANDLE UPDATE EVENT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $updateId = $_POST['event_id'] ?? '';
    
    if (!empty($updateId)) {
        $payload = [
            'title' => trim($_POST['title']),
            'description' => trim($_POST['description']),
            'venue' => trim($_POST['venue']),
            'start_datetime' => $_POST['start_datetime'],
            'end_datetime' => $_POST['end_datetime'],
            'capacity' => (int)$_POST['capacity'],
            'status' => $_POST['status']
        ];

        $response = supabaseRequest("events?id=eq.{$updateId}", 'PATCH', $payload);

        if ($response['code'] >= 400) {
            $errorMsg = "Failed to update event. Database error.";
        } else {
            $successMsg = "Event updated successfully!";
        }
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
    <!-- Sidebar Navigation -->
    <div class="sidebar flex-shrink-0" style="width: 250px;">
        <div class="p-4 text-center border-bottom border-secondary">
            <h4 class="mb-0">EMIS Admin</h4>
            <small class="text-muted"><?= htmlspecialchars(ucwords($_SESSION['user_role'])) ?> Portal</small>
        </div>
        <nav class="mt-3">
            <a href="dashboard.php"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a>
            <a href="events.php" class="active"><i class="bi bi-calendar-event me-2"></i> Event Management</a>
            <a href="equipment.php"><i class="bi bi-tools me-2"></i> Equipment & Services</a>
            <?php if ($currentRole === 'administrator'): ?>
                <a href="users.php"><i class="bi bi-people me-2"></i> Users & Roles</a>
            <?php endif; ?>
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

        <?php if ($successMsg): ?><div class="alert alert-success alert-dismissible fade show"><?= $successMsg ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php if ($errorMsg): ?><div class="alert alert-danger alert-dismissible fade show"><?= $errorMsg ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

        <!-- Events Table -->
        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
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
                                <?php foreach ($events as $event): 
                                    // Parse for HTML attributes
                                    $eTitle = htmlspecialchars($event['title'] ?? '');
                                    $eDesc = htmlspecialchars($event['description'] ?? '');
                                    $eVenue = htmlspecialchars($event['venue'] ?? '');
                                    $eStart = date('Y-m-d\TH:i', strtotime($event['start_datetime']));
                                    $eEnd = date('Y-m-d\TH:i', strtotime($event['end_datetime']));
                                    $eCap = htmlspecialchars($event['capacity'] ?? 100);
                                    $eStatus = htmlspecialchars($event['status'] ?? 'Upcoming');
                                ?>
                                    <tr>
                                        <td class="fw-bold text-primary"><?= $eTitle ?></td>
                                        <td><?= $eVenue ?></td>
                                        <td>
                                            <small class="d-block text-muted">Start: <?= date('M d, Y h:i A', strtotime($event['start_datetime'])) ?></small>
                                            <small class="d-block text-muted">End: <?= date('M d, Y h:i A', strtotime($event['end_datetime'])) ?></small>
                                        </td>
                                        <td><?= $eCap ?></td>
                                        <td>
                                            <span class="badge bg-<?= $eStatus === 'Upcoming' ? 'success' : ($eStatus === 'Ongoing' ? 'warning text-dark' : 'secondary') ?>">
                                                <?= $eStatus ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <!-- Copy ID -->
                                                <button class="btn btn-sm btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText('<?= $event['id'] ?>'); alert('Event ID copied!');" title="Copy Event ID">
                                                    <i class="bi bi-clipboard"></i>
                                                </button>
                                                
                                                <!-- Edit Button (Opens Modal) -->
                                                <button class="btn btn-sm btn-outline-primary" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editEventModal"
                                                    data-id="<?= $event['id'] ?>"
                                                    data-title="<?= $eTitle ?>"
                                                    data-desc="<?= $eDesc ?>"
                                                    data-venue="<?= $eVenue ?>"
                                                    data-start="<?= $eStart ?>"
                                                    data-end="<?= $eEnd ?>"
                                                    data-cap="<?= $eCap ?>"
                                                    data-status="<?= $eStatus ?>"
                                                    title="Edit Event">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                
                                                <!-- Delete Button -->
                                                <?php if (in_array($currentRole, ['administrator', 'event organizer'])): ?>
                                                    <form method="POST" class="mb-0" onsubmit="return confirm('Delete this event?');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="event_id" value="<?= htmlspecialchars($event['id']) ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Event">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
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
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Status</label>
                            <select name="status" class="form-select">
                                <option value="Upcoming">Upcoming</option>
                                <option value="Ongoing">Ongoing</option>
                                <option value="Completed">Completed</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Start Date & Time</label>
                            <input type="datetime-local" name="start_datetime" class="form-control" required>
                        </div>
                        <div class="col-md-4 mb-3">
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

<!-- Edit Event Modal -->
<div class="modal fade" id="editEventModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Edit Event</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="event_id" id="edit-id">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Event Title</label>
                        <input type="text" name="title" id="edit-title" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Description</label>
                        <textarea name="description" id="edit-desc" class="form-control" rows="3" required></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Venue</label>
                            <input type="text" name="venue" id="edit-venue" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Participant Capacity</label>
                            <input type="number" name="capacity" id="edit-cap" class="form-control" required min="1">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Status</label>
                            <select name="status" id="edit-status" class="form-select">
                                <option value="Upcoming">Upcoming</option>
                                <option value="Ongoing">Ongoing</option>
                                <option value="Completed">Completed</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Start Date & Time</label>
                            <input type="datetime-local" name="start_datetime" id="edit-start" class="form-control" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">End Date & Time</label>
                            <input type="datetime-local" name="end_datetime" id="edit-end" class="form-control" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Event</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Script to populate the Edit Modal dynamically when the edit button is clicked
const editModal = document.getElementById('editEventModal');
if(editModal) {
    editModal.addEventListener('show.bs.modal', event => {
        const button = event.relatedTarget;
        
        document.getElementById('edit-id').value = button.getAttribute('data-id');
        document.getElementById('edit-title').value = button.getAttribute('data-title');
        document.getElementById('edit-desc').value = button.getAttribute('data-desc');
        document.getElementById('edit-venue').value = button.getAttribute('data-venue');
        document.getElementById('edit-start').value = button.getAttribute('data-start');
        document.getElementById('edit-end').value = button.getAttribute('data-end');
        document.getElementById('edit-cap').value = button.getAttribute('data-cap');
        document.getElementById('edit-status').value = button.getAttribute('data-status');
    });
}
</script>
</body>
</html>
