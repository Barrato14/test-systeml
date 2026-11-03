<?php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

// Ensure the user is logged in and is a Participant
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role']) !== 'participant') {
    header('Location: ../login.php');
    exit();
}

$participant_id = $_SESSION['user_id'];
$firstName = $_SESSION['user_name'] ?? 'Student';

$successMsg = '';
$errorMsg = '';

// Handle Registration Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_event_id'])) {
    $eventIdToRegister = $_POST['register_event_id'];
    
    // Check if a registration already exists to prevent duplicate error loops
    $checkExisting = supabaseRequest('event_registrations?participant_id=eq.' . $participant_id . '&event_id=eq.' . $eventIdToRegister, 'GET');
    
    if (!empty($checkExisting['data'])) {
        // If it exists but isn't approved, force update it to Approved so it shows up immediately
        $updateRes = supabaseRequest('event_registrations?participant_id=eq.' . $participant_id . '&event_id=eq.' . $eventIdToRegister, 'PATCH', ['status' => 'Approved']);
        $successMsg = "Registration updated and approved for testing!";
    } else {
        $payload = [
            'participant_id' => $participant_id,
            'event_id' => $eventIdToRegister,
            'status' => 'Approved' // Force Approved for seamless testing
        ];

        $regResponse = supabaseRequest('event_registrations', 'POST', $payload);
        if ($regResponse['code'] >= 400) {
            $errorMsg = "Failed to register. Please check your database table columns.";
        } else {
            $successMsg = "Successfully registered and approved for the event!";
        }
    }
}

// 1. Fetch ALL registrations for this participant (ignoring inner join restrictions for a moment so we catch it)
$regResponse = supabaseRequest('event_registrations?participant_id=eq.' . $participant_id, 'GET');
$registrations = ($regResponse['code'] < 400 && is_array($regResponse['data'])) ? $regResponse['data'] : [];

// 2. Fetch ALL events so we can map them cleanly
$allEventsResponse = supabaseRequest('events?order=start_datetime.asc', 'GET');
$allEvents = ($allEventsResponse['code'] < 400 && is_array($allEventsResponse['data'])) ? $allEventsResponse['data'] : [];

// Index events by ID for quick lookup
$eventsMap = [];
foreach ($allEvents as $evt) {
    $eventsMap[$evt['id']] = $evt;
}

// Extract registered event IDs
$registeredEventIds = [];
foreach ($registrations as $reg) {
    if (isset($reg['event_id'])) {
        $registeredEventIds[] = $reg['event_id'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Participant Dashboard - EMIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>
<body class="bg-light">

<!-- Navigation -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4 shadow-sm">
    <div class="container">
        <a class="navbar-brand" href="#">EMIS Participant Portal</a>
        <div class="d-flex align-items-center">
            <span class="navbar-text text-white me-3">Welcome, <?= htmlspecialchars($firstName) ?></span>
            <a href="my-events.php" class="btn btn-outline-light btn-sm me-2">My Registrations</a>
            <a href="../logout.php" class="btn btn-outline-danger btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="container">
    <?php if ($successMsg): ?><div class="alert alert-success"><?= $successMsg ?></div><?php endif; ?>
    <?php if ($errorMsg): ?><div class="alert alert-danger"><?= $errorMsg ?></div><?php endif; ?>

    <div class="row mb-4">
        <div class="col">
            <h2 class="h4">My Approved Events & Check-In</h2>
            <p class="text-muted">Perform your secure face-scan and GPS check-in for approved events here.</p>
        </div>
    </div>

    <!-- Section 1: Registered / Approved Events -->
    <div class="row mb-5">
        <?php if (empty($registeredEventIds)): ?>
            <div class="col-12">
                <div class="alert alert-warning text-center py-4 shadow-sm">
                    <h5>You aren't registered for any events yet.</h5>
                    <p class="mb-0">Choose an available event from the list below to register!</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($registrations as $reg): 
                $eventId = $reg['event_id'] ?? '';
                $event = $eventsMap[$eventId] ?? null;
                if (!$event) continue; 
                
                $status = $reg['status'] ?? 'Approved';
                $dateObj = new DateTime($event['start_datetime']);
                $formattedDate = $dateObj->format('F j, Y, g:i a');
            ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card h-100 shadow-sm border-0">
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title text-primary"><?= htmlspecialchars($event['title']) ?></h5>
                            <h6 class="card-subtitle mb-2 text-muted"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($event['venue'] ?? 'General Venue') ?></h6>
                            
                            <p class="card-text small mb-3">
                                <strong>Date:</strong> <?= $formattedDate ?><br>
                                <?= htmlspecialchars(substr($event['description'] ?? '', 0, 80)) ?>...
                            </p>
                            
                            <div class="mt-auto">
                                <hr>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="badge bg-success"><?= htmlspecialchars($status) ?></span>

                                    <!-- Direct Check-In Button -->
                                    <a href="check-in.php?event_id=<?= $eventId ?>" class="btn btn-sm btn-primary fw-bold">
                                        <i class="bi bi-camera me-1"></i> Check In (Face/GPS)
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Section 2: Available Events to Join -->
    <div class="row mb-3">
        <div class="col">
            <h3 class="h5 text-secondary">Available Events to Join</h3>
            <hr>
        </div>
    </div>

    <div class="row">
        <?php 
        $availableCount = 0;
        foreach ($allEvents as $evt) {
            if (in_array($evt['id'], $registeredEventIds)) continue;
            $availableCount++;
        }
        ?>

        <?php if ($availableCount === 0): ?>
            <div class="col-12 text-muted pb-4">You have registered for all available events. Look above for your Check-In buttons!</div>
        <?php else: ?>
            <?php foreach ($allEvents as $evt): 
                if (in_array($evt['id'], $registeredEventIds)) continue;
            ?>
                <div class="col-md-4 mb-4">
                    <div class="card h-100 shadow-sm border-light">
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title text-dark"><?= htmlspecialchars($evt['title']) ?></h5>
                            <h6 class="card-subtitle mb-2 text-muted"><?= htmlspecialchars($evt['venue']) ?></h6>
                            <p class="card-text small mb-3">
                                <strong>Start:</strong> <?= date('M d, Y h:i A', strtotime($evt['start_datetime'])) ?><br>
                                <?= htmlspecialchars($evt['description'] ?? '') ?>
                            </p>
                            <form method="POST" class="mt-auto">
                                <input type="hidden" name="register_event_id" value="<?= htmlspecialchars($evt['id']) ?>">
                                <button type="submit" class="btn btn-outline-primary btn-sm w-100">Register For Event</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
