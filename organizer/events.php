<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

if (function_exists('requireRole')) {
    requireRole(['Administrator', 'Event Organizer']);
}

$error = '';
$success = '';
$organizerId = $_SESSION['user_id'] ?? null;

// Handle Event Creation POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_event'])) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $venue = trim($_POST['venue']);
    $start = $_POST['start_datetime'];
    $end = $_POST['end_datetime'];
    $capacity = (int)$_POST['capacity'];

    if (empty($title) || empty($start) || empty($end) || $capacity <= 0) {
        $error = "Please fill in all required fields with valid data.";
    } else {
        $payload = [
            'title' => $title,
            'description' => $description,
            'venue' => $venue,
            'start_datetime' => $start,
            'end_datetime' => $end,
            'capacity' => $capacity,
            'organizer_id' => $organizerId,
            'status' => 'Upcoming'
        ];

        $insertRes = supabaseRequest('events', 'POST', $payload);

        if ($insertRes['code'] === 201 || $insertRes['code'] === 200) {
            // Optional audit log via REST if table exists
            /*
            supabaseRequest('audit_logs', 'POST', [
                'user_id' => $organizerId,
                'action' => 'Event Creation',
                'details' => "Created event: $title"
            ]);
            */
            $success = "Event created successfully.";
        } else {
            $error = "Failed to create event. Please try again.";
        }
    }
}

// Fetch Organizer's Events with embedded approved registrations count
$response = supabaseRequest('events?organizer_id=eq.' . $organizerId . '&order=start_datetime.desc&select=*,event_registrations(status)', 'GET');

$events = [];
if (!empty($response['data']) && is_array($response['data'])) {
    foreach ($response['data'] as $ev) {
        // Count approved registrations for this event
        $approvedCount = 0;
        if (!empty($ev['event_registrations'])) {
            foreach ($ev['event_registrations'] as $reg) {
                if (isset($reg['status']) && $reg['status'] === 'Approved') {
                    $approvedCount++;
                }
            }
        }
        $ev['current_registrations'] = $approvedCount;
        $events[] = $ev;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Events - EMIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Manage Events</h2>
        <a href="../logout.php" class="btn btn-outline-danger">Logout</a>
    </div>

    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <div class="card mb-4 shadow-sm">
        <div class="card-header bg-primary text-white">Create New Event</div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="create_event" value="1">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label>Title *</label>
                        <input type="text" name="title" class="form-control" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label>Venue</label>
                        <input type="text" name="venue" class="form-control">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label>Start Date/Time *</label>
                        <input type="datetime-local" name="start_datetime" class="form-control" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label>End Date/Time *</label>
                        <input type="datetime-local" name="end_datetime" class="form-control" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label>Capacity *</label>
                        <input type="number" name="capacity" class="form-control" min="1" required>
                    </div>
                    <div class="col-12 mb-3">
                        <label>Description</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Create Event</button>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-dark text-white">Your Events</div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Title</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Capacity</th>
                        <th>Registrations</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $event): ?>
                    <tr>
                        <td><?= htmlspecialchars($event['title']) ?></td>
                        <td><?= $event['start_datetime'] ? date('M d, Y H:i', strtotime($event['start_datetime'])) : 'N/A' ?></td>
                        <td><span class="badge bg-info"><?= htmlspecialchars($event['status']) ?></span></td>
                        <td><?= htmlspecialchars($event['capacity']) ?></td>
                        <td>
                            <?= $event['current_registrations'] ?> / <?= htmlspecialchars($event['capacity']) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($events)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-3 text-muted">You haven't created any events yet.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>