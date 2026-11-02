<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

if (function_exists('requireRole')) {
    requireRole(['Participant', 'Administrator']);
}

$participantId = $_SESSION['user_id'] ?? null;

// Fetch user's registered events with embedded event details using Supabase REST API
$response = supabaseRequest('event_registrations?participant_id=eq.' . $participantId . '&select=status,registered_at,events(title,start_datetime,venue)', 'GET');

$myEvents = [];
if (!empty($response['data']) && is_array($response['data'])) {
    foreach ($response['data'] as $row) {
        $eventData = $row['events'] ?? [];
        $myEvents[] = [
            'title' => $eventData['title'] ?? 'N/A',
            'start_datetime' => $eventData['start_datetime'] ?? '',
            'venue' => $eventData['venue'] ?? 'N/A',
            'registration_status' => $row['status'] ?? '',
            'registered_at' => $row['registered_at'] ?? ''
        ];
    }

    // Sort by start_datetime ascending
    usort($myEvents, function($a, $b) {
        return strtotime($a['start_datetime']) - strtotime($b['start_datetime']);
    });
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Events - EMIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>My Registered Events</h2>
        <a href="dashboard.php" class="btn btn-outline-primary">Back to Dashboard</a>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Event Title</th>
                        <th>Date & Time</th>
                        <th>Venue</th>
                        <th>Status</th>
                        <th>Registered On</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($myEvents as $event): ?>
                    <tr>
                        <td><?= htmlspecialchars($event['title']) ?></td>
                        <td><?= $event['start_datetime'] ? date('M d, Y h:i A', strtotime($event['start_datetime'])) : 'N/A' ?></td>
                        <td><?= htmlspecialchars($event['venue']) ?></td>
                        <td><span class="badge bg-success"><?= htmlspecialchars($event['registration_status']) ?></span></td>
                        <td><?= $event['registered_at'] ? date('M d, Y', strtotime($event['registered_at'])) : 'N/A' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($myEvents)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-3 text-muted">You have not registered for any events yet.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>