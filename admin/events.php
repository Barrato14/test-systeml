<?php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

// Ensure user is an Administrator
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role']) !== 'administrator') {
    header("Location: ../login.php");
    exit;
}

$supabaseUrl = getenv('SUPABASE_URL') ?: $_ENV['SUPABASE_URL'] ?? '';
$supabaseKey = getenv('SUPABASE_KEY') ?: $_ENV['SUPABASE_KEY'] ?? '';

$successMsg = '';
$errorMsg = '';

// Handle Create Event Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $payload = json_encode([
        'title' => trim($_POST['title']),
        'description' => trim($_POST['description']),
        'venue' => trim($_POST['venue']),
        'start_datetime' => $_POST['start_datetime'],
        'end_datetime' => $_POST['end_datetime'],
        'capacity' => (int)$_POST['capacity'],
        'status' => 'Upcoming'
    ]);

    $ch = curl_init(rtrim($supabaseUrl, '/') . '/rest/v1/events');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $supabaseKey,
        'apikey: ' . $supabaseKey,
        'Content-Type: application/json',
        'Prefer: return=representation'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        $errorMsg = "Failed to create event. Please check your database connection.";
    } else {
        $successMsg = "Event created successfully!";
    }
}

// Fetch All Events to display in the table
$chGet = curl_init(rtrim($supabaseUrl, '/') . '/rest/v1/events?order=start_datetime.asc');
curl_setopt($chGet, CURLOPT_RETURNTRANSFER, true);
curl_setopt($chGet, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $supabaseKey,
    'apikey: ' . $supabaseKey
]);
$eventsResponse = curl_exec($chGet);
curl_close($chGet);

$events = json_decode($eventsResponse, true);
if (!is_array($events) || isset($events['message'])) {
    $events = []; // Fallback if API fails or returns an error object
}
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
            <a href="dashboard.php"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a>
            <a href="events.php" class="active"><i class="bi bi-calendar-event me-2"></i> Event Management</a>
            <a href="equipment.php"><i class="bi bi-tools me-2"></i> Equipment & Services</a>
            <a href="users.php"><i class="bi bi-people me-2"></i> Users & Roles</a>
            <a href="reports.php"><i class="bi bi-bar-chart me-2"></i> Reports & Audits</a>
            <a href="../logout.php" class="text-danger mt-5"><i class="bi bi-box-arrow-right me-2"></i> Logout</a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="content-area flex-grow-1">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">Event Management</h2>
            <!-- Button to trigger Create Event Modal -->
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createEventModal">
                <i class="bi bi-plus-circle me-2"></i> Create Event
            </button>
        </div>

        <?php if ($successMsg): ?><div class="alert alert-success"><?= $successMsg ?></div><?php endif; ?>
        <?php if ($errorMsg): ?><div class="alert alert-danger"><?= $errorMsg ?></div><?php endif; ?>

        <!-- Events Table -->
        <div class="card shadow-sm">
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Title</th>
                            <th>Venue</th>
                            <th>Start Date & Time</th>
                            <th>Capacity</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($events)): ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted">No events found. Create one above!</td></tr>
                        <?php else: ?>
                            <?php foreach ($events as $event): ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($event['title']) ?></td>
                                    <td><?= htmlspecialchars($event['venue']) ?></td>
                                    <td><?= date('M d, Y h:i A', strtotime($event['start_datetime'])) ?></td>
                                    <td><?= htmlspecialchars($event['capacity']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $event['status'] === 'Upcoming' ? 'success' : 'secondary' ?>">
                                            <?= htmlspecialchars($event['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <!-- Note: This ID is what we will use to test the camera check-in! -->
                                        <button class="btn btn-sm btn-outline-primary" onclick="alert('Event ID for Camera Testing: \n<?= $event['id'] ?>')">
                                            <i class="bi bi-camera"></i> Get Check-in ID
                                        </button>
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