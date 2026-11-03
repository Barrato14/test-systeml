<?php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

// Ensure user is a Participant
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role']) !== 'participant') {
    header("Location: ../login.php");
    exit;
}

$participantId = $_SESSION['user_id'];

// Fetch user's registered events with embedded event details including the event ID
$response = supabaseRequest('event_registrations?participant_id=eq.' . $participantId . '&select=status,registered_at,events(id,title,start_datetime,venue)', 'GET');

$myEvents = [];
if (!empty($response['data']) && is_array($response['data'])) {
    foreach ($response['data'] as $row) {
        $eventData = $row['events'] ?? [];
        $myEvents[] = [
            'event_id' => $eventData['id'] ?? '',
            'title' => $eventData['title'] ?? 'N/A',
            'start_datetime' => $eventData['start_datetime'] ?? '',
            'venue' => $eventData['venue'] ?? 'N/A',
            'registration_status' => $row['status'] ?? '',
            'registered_at' => $row['registered_at'] ?? ''
        ];
    }

    // Sort by start_datetime ascending
    usort($myEvents, function($a, $b) {
        if (empty($a['start_datetime'])) return 1;
        if (empty($b['start_datetime'])) return -1;
        return strtotime($a['start_datetime']) - strtotime($b['start_datetime']);
    });
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Events - EMIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>
<body class="bg-light">
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>My Registered Events</h2>
        <a href="dashboard.php" class="btn btn-outline-primary">Back to Dashboard</a>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Event Title</th>
                        <th>Date & Time</th>
                        <th>Venue</th>
                        <th>Status</th>
                        <th>Registered On</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($myEvents)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">You have not registered for any events yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($myEvents as $event): ?>
                            <tr>
                                <td class="fw-bold"><?= htmlspecialchars($event['title']) ?></td>
                                <td><?= $event['start_datetime'] ? date('M d, Y h:i A', strtotime($event['start_datetime'])) : 'N/A' ?></td>
                                <td><?= htmlspecialchars($event['venue']) ?></td>
                                <td>
                                    <?php 
                                        $status = $event['registration_status'];
                                        $badgeClass = 'secondary';
                                        if ($status === 'Approved') $badgeClass = 'success';
                                        elseif ($status === 'Pending') $badgeClass = 'warning text-dark';
                                        elseif ($status === 'Rejected') $badgeClass = 'danger';
                                    ?>
                                    <span class="badge bg-<?= $badgeClass ?>"><?= htmlspecialchars($status) ?></span>
                                </td>
                                <td><?= $event['registered_at'] ? date('M d, Y', strtotime($event['registered_at'])) : 'N/A' ?></td>
                                <td>
                                    <?php if ($status === 'Approved' && !empty($event['event_id'])): ?>
                                        <a href="check-in.php?event_id=<?= $event['event_id'] ?>" class="btn btn-sm btn-primary">
                                            <i class="bi bi-camera me-1"></i> Check In
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted small">Unavailable</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>