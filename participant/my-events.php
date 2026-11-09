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

// 1. Fetch user's event registrations
$regResponse = supabaseRequest('event_registrations?participant_id=eq.' . $participantId, 'GET');
$registrations = ($regResponse['code'] < 400 && is_array($regResponse['data'])) ? $regResponse['data'] : [];

// 2. Fetch all events to map details cleanly
$allEventsResponse = supabaseRequest('events?order=start_datetime.asc', 'GET');
$allEvents = ($allEventsResponse['code'] < 400 && is_array($allEventsResponse['data'])) ? $allEventsResponse['data'] : [];

$eventsMap = [];
foreach ($allEvents as $evt) {
    $eventsMap[$evt['id']] = $evt;
}

$myEvents = [];
foreach ($registrations as $reg) {
    $eventId = $reg['event_id'] ?? '';
    $eventData = $eventsMap[$eventId] ?? [];
    
    $myEvents[] = [
        'event_id' => $eventId,
        'title' => $eventData['title'] ?? 'Unknown Event',
        'start_datetime' => $eventData['start_datetime'] ?? '',
        'venue' => $eventData['venue'] ?? 'N/A',
        'registration_status' => $reg['status'] ?? 'Approved',
        'registered_at' => $reg['registered_at'] ?? date('Y-m-d')
    ];
}

// Sort by start date ascending
usort($myEvents, function($a, $b) {
    if (empty($a['start_datetime'])) return 1;
    if (empty($b['start_datetime'])) return -1;
    return strtotime($a['start_datetime']) - strtotime($b['start_datetime']);
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Events - EMIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
<link rel="stylesheet" href="../css/style.css">\n</head>
<body class="bg-light participant-page">
<?php include '../includes/participant-nav.php'; ?>

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
                            <td colspan="6" class="text-center py-4 text-muted">
                                You have not registered for any events yet. Go back to your <a href="dashboard.php">Dashboard</a> to join an event!
                            </td>
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
                                        $badgeClass = 'success';
                                        if (strtolower($status) === 'pending') $badgeClass = 'warning text-dark';
                                        elseif (strtolower($status) === 'rejected') $badgeClass = 'danger';
                                    ?>
                                    <span class="badge bg-<?= $badgeClass ?>"><?= htmlspecialchars(ucfirst($status)) ?></span>
                                </td>
                                <td><?= $event['registered_at'] ? date('M d, Y', strtotime($event['registered_at'])) : 'N/A' ?></td>
                                <td>
                                    <?php if (!empty($event['event_id'])): ?>
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