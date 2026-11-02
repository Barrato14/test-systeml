<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

// Ensure user has correct role (assuming requireRole exists in auth.php, or check manually)
if (function_exists('requireRole')) {
    requireRole(['Participant', 'Administrator']);
}

$error = '';
$success = '';
$participantId = $_SESSION['user_id'] ?? null;

// Handle Registration POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_event_id'])) {
    $eventId = $_POST['register_event_id'];
    
    // 1. Fetch event capacity
    $eventRes = supabaseRequest('events?id=eq.' . $eventId, 'GET');
    $event = (!empty($eventRes['data'])) ? $eventRes['data'][0] : null;

    if (!$event) {
        $error = "Event not found.";
    } else {
        // 2. Check current approved registrations count
        $regRes = supabaseRequest('event_registrations?event_id=eq.' . $eventId . '&status=eq.Approved', 'GET');
        $currentCount = is_array($regRes['data']) ? count($regRes['data']) : 0;

        // 3. Check if user is already registered
        $userRegRes = supabaseRequest('event_registrations?event_id=eq.' . $eventId . '&participant_id=eq.' . $participantId, 'GET');
        $isRegistered = (!empty($userRegRes['data'])) ? count($userRegRes['data']) : 0;

        if ($isRegistered > 0) {
            $error = "You are already registered for this event.";
        } elseif ($currentCount >= $event['capacity']) {
            $error = "Event has reached maximum capacity.";
        } else {
            // 4. Insert registration
            $insertRes = supabaseRequest('event_registrations', 'POST', [
                'event_id' => $eventId,
                'participant_id' => $participantId,
                'status' => 'Approved'
            ]);

            if ($insertRes['code'] === 201 || $insertRes['code'] === 200) {
                // Optional audit log via REST if table exists
                /*
                supabaseRequest('audit_logs', 'POST', [
                    'user_id' => $participantId,
                    'action' => 'Event Registration',
                    'details' => "Registered for event ID: $eventId"
                ]);
                */
                $success = "Successfully registered for the event!";
            } else {
                $error = "Failed to register. Please try again.";
            }
        }
    }
}

// Fetch available upcoming events with embedded registrations to count current registrations
$eventsRes = supabaseRequest('events?status=eq.Upcoming&order=start_datetime.asc&select=*,event_registrations(status)', 'GET');
$availableEvents = [];

if (!empty($eventsRes['data']) && is_array($eventsRes['data'])) {
    foreach ($eventsRes['data'] as $ev) {
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
        $availableEvents[] = $ev;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Participant Dashboard - EMIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Welcome, <?= htmlspecialchars($_SESSION['user_name'] ?? 'Participant') ?></h2>
        <div>
            <a href="my-events.php" class="btn btn-secondary me-2">My Events</a>
            <a href="../logout.php" class="btn btn-outline-danger">Logout</a>
        </div>
    </div>

    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <h4 class="mb-3">Available Upcoming Events</h4>
    <div class="row">
        <?php foreach ($availableEvents as $event): ?>
            <?php 
                $isFull = $event['current_registrations'] >= $event['capacity'];
            ?>
            <div class="col-md-4 mb-4">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h5 class="card-title"><?= htmlspecialchars($event['title']) ?></h5>
                        <p class="text-muted mb-1"><small><?= htmlspecialchars($event['venue']) ?></small></p>
                        <p class="text-muted"><small><?= date('M d, Y h:i A', strtotime($event['start_datetime'])) ?></small></p>
                        <p class="card-text"><?= htmlspecialchars(substr($event['description'] ?? '', 0, 100)) ?>...</p>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <span class="badge <?= $isFull ? 'bg-danger' : 'bg-success' ?>">
                                <?= $event['current_registrations'] ?> / <?= $event['capacity'] ?> Registered
                            </span>
                            <form method="POST">
                                <input type="hidden" name="register_event_id" value="<?= $event['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-primary" <?= $isFull ? 'disabled' : '' ?>>
                                    <?= $isFull ? 'Full' : 'Register' ?>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($availableEvents)): ?>
            <div class="col-12"><p class="text-muted">No upcoming events available at the moment.</p></div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>