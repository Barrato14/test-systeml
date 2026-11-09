<?php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role']) !== 'participant') {
    header("Location: ../login.php");
    exit;
}

$participantId = $_SESSION['user_id'];
$successMsg = '';
$errorMsg = '';

$manilaTimezone = new DateTimeZone('Asia/Manila');

function eventHasEnded($event, $timezone) {
    if (empty($event['end_datetime'])) {
        return true;
    }

    try {
        $eventEnd = new DateTimeImmutable($event['end_datetime']);
        $eventEnd = $eventEnd->setTimezone($timezone);
        $now = new DateTimeImmutable('now', $timezone);

        return $eventEnd <= $now;
    } catch (Exception $e) {
        return true;
    }
}

// Handle Registration Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['event_id'])) {
    $eventId = trim((string)$_POST['event_id']);

    if ($eventId === '') {
        $errorMsg = "Invalid event selected.";
    } else {
        $eventCheck = supabaseRequest(
            'events?id=eq.' . rawurlencode($eventId) . '&select=id,title,start_datetime,end_datetime',
            'GET'
        );

        if (($eventCheck['code'] ?? 500) >= 400 || empty($eventCheck['data'])) {
            $errorMsg = "Event not found. Registration is unavailable.";
        } elseif (eventHasEnded($eventCheck['data'][0], $manilaTimezone)) {
            $errorMsg = "Registration is closed. This event has already ended.";
        } else {
            $payload = [
                'participant_id' => $participantId,
                'event_id' => $eventId,
                'status' => 'Approved'
            ];

            $response = supabaseRequest('event_registrations', 'POST', $payload);
            if (($response['code'] ?? 500) >= 400) {
                $errorMsg = "You are already registered for this event or an error occurred.";
            } else {
                $successMsg = "Successfully registered for the event!";
            }
        }
    }
}

// Fetch events and hide ended or invalidly scheduled events, matching the participant dashboard.
$getResponse = supabaseRequest('events?order=start_datetime.asc', 'GET');
$allEvents = (($getResponse['code'] ?? 500) < 400 && is_array($getResponse['data'] ?? null))
    ? $getResponse['data'] : [];

$events = [];
foreach ($allEvents as $event) {
    if (!eventHasEnded($event, $manilaTimezone)) {
        $events[] = $event;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Events - EMIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>
<body class="bg-light participant-page">
<?php include '../includes/participant-nav.php'; ?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Browse Available Events</h2>
        <a href="dashboard.php" class="btn btn-outline-primary">Back to Dashboard</a>
    </div>

    <?php if ($successMsg): ?><div class="alert alert-success"><?= $successMsg ?></div><?php endif; ?>
    <?php if ($errorMsg): ?><div class="alert alert-danger"><?= $errorMsg ?></div><?php endif; ?>

    <div class="row">
        <?php if (empty($events)): ?>
            <div class="col-12 text-center py-5 text-muted">No events available right now. Ask your admin to create one!</div>
        <?php else: ?>
            <?php foreach ($events as $event): ?>
                <div class="col-md-4 mb-4">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title text-primary"><?= htmlspecialchars($event['title']) ?></h5>
                            <h6 class="card-subtitle mb-2 text-muted"><?= htmlspecialchars($event['venue']) ?></h6>
                            <p class="card-text small mb-3">
                                <strong>Start:</strong> <?= date('M d, Y h:i A', strtotime($event['start_datetime'])) ?><br>
                                <?= htmlspecialchars($event['description'] ?? '') ?>
                            </p>
                            <form method="POST" class="mt-auto">
                                <input type="hidden" name="event_id" value="<?= htmlspecialchars($event['id']) ?>">
                                <button type="submit" class="btn btn-primary w-100">Register for Event</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>