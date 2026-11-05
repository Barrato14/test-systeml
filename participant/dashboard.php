<?php
session_start();
date_default_timezone_set('Asia/Manila');
require_once '../config/database.php';
require_once '../includes/auth.php';

// Ensure the user is logged in and is a Participant
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role']) !== 'participant') {
    header('Location: ../login.php');
    exit();
}

$participant_id = $_SESSION['user_id'];
$firstName = $_SESSION['user_name'] ?? 'Student';

// Guard: Check if user (especially Google / OAuth users) has completed course and section
$profileRes = supabaseRequest('profiles?id=eq.' . $participant_id . '&select=course,section', 'GET');
if ($profileRes['code'] === 200 && !empty($profileRes['data'])) {
    $profile = $profileRes['data'][0];
    if (empty($profile['course']) || empty($profile['section'])) {
        header("Location: complete-profile.php");
        exit();
    }
}

$successMsg = '';
$errorMsg = '';

// Handle Registration Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_event_id'])) {
    $eventIdToRegister = $_POST['register_event_id'];
    
    // Check if a registration already exists to prevent duplicate error loops
    $checkExisting = supabaseRequest('event_registrations?participant_id=eq.' . $participant_id . '&event_id=eq.' . $eventIdToRegister, 'GET');
    
    if (!empty($checkExisting['data'])) {
        $updateRes = supabaseRequest('event_registrations?participant_id=eq.' . $participant_id . '&event_id=eq.' . $eventIdToRegister, 'PATCH', ['status' => 'Approved']);
        $successMsg = "Registration updated and approved for testing!";
    } else {
        $payload = [
            'participant_id' => $participant_id,
            'event_id' => $eventIdToRegister,
            'status' => 'Approved' 
        ];

        $regResponse = supabaseRequest('event_registrations', 'POST', $payload);
        if ($regResponse['code'] >= 400) {
            $errorMsg = "Failed to register. Please check your database table columns.";
        } else {
            $successMsg = "Successfully registered and approved for the event!";
        }
    }
}

// 1. Fetch ALL registrations for this participant
$regResponse = supabaseRequest('event_registrations?participant_id=eq.' . $participant_id, 'GET');
$registrations = ($regResponse['code'] < 400 && is_array($regResponse['data'])) ? $regResponse['data'] : [];

// Fetch existing attendance logs
$attendanceRes = supabaseRequest('attendance?participant_id=eq.' . $participant_id . '&select=event_id,check_in_time,time_out', 'GET');
$participantAttendance = [];
if ($attendanceRes['code'] === 200 && is_array($attendanceRes['data'])) {
    foreach ($attendanceRes['data'] as $att) {
        $participantAttendance[$att['event_id']] = $att;
    }
}

// 2. Fetch ALL events
$allEventsResponse = supabaseRequest('events?order=start_datetime.asc', 'GET');
$allEvents = ($allEventsResponse['code'] < 400 && is_array($allEventsResponse['data'])) ? $allEventsResponse['data'] : [];

// Index events by ID
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
    <style>
        .event-card-img {
            height: 180px;
            object-fit: cover;
            border-top-left-radius: calc(.25rem - 1px);
            border-top-right-radius: calc(.25rem - 1px);
        }
        .map-badge {
            transition: all 0.2s ease;
        }
        .map-badge:hover {
            background-color: #e9ecef !important;
            transform: scale(1.05);
        }
    </style>
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
            <p class="text-muted">Perform your secure face-scan and GPS check-in/time-out for approved events here.</p>
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

                // Dynamic UI Variables
                $eventImg = !empty($event['image_url']) ? htmlspecialchars($event['image_url']) : 'https://images.unsplash.com/photo-1540575467063-178a50c2df87?w=800&q=80';
                $lat = $event['latitude'] ?? null;
                $lng = $event['longitude'] ?? null;

                // Determine attendance state
                $attRecord = $participantAttendance[$eventId] ?? null;
                $hasCheckedIn = !empty($attRecord['check_in_time']);
                $hasTimedOut = !empty($attRecord['time_out']);
            ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card h-100 shadow-sm border-0">
                        <!-- Event Cover Image -->
                        <img src="<?= $eventImg ?>" class="card-img-top event-card-img" alt="Event Image">
                        
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title text-primary fw-bold"><?= htmlspecialchars($event['title']) ?></h5>
                            
                            <!-- Venue & Map Pin -->
                            <div class="d-flex align-items-center mb-2">
                                <i class="bi bi-geo-alt-fill text-danger me-2"></i>
                                <span class="text-muted fw-semibold me-2"><?= htmlspecialchars($event['venue'] ?? 'General Venue') ?></span>
                                <?php if ($lat && $lng): ?>
                                    <a href="https://www.google.com/maps?q=<?= $lat ?>,<?= $lng ?>" target="_blank" class="badge bg-light text-primary border text-decoration-none map-badge">
                                        <i class="bi bi-map me-1"></i>Map
                                    </a>
                                <?php endif; ?>
                            </div>
                            
                            <p class="card-text small mb-3">
                                <strong><i class="bi bi-calendar-event me-1"></i> Date:</strong> <?= $formattedDate ?><br>
                                <span class="text-muted"><?= htmlspecialchars(substr($event['description'] ?? '', 0, 90)) ?>...</span>
                            </p>
                            
                            <div class="mt-auto">
                                <hr>
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <span class="badge bg-success"><?= htmlspecialchars($status) ?></span>

                                    <!-- Dynamic Attendance Action Button -->
                                    <?php if (!$hasCheckedIn): ?>
                                        <a href="check-in.php?event_id=<?= $eventId ?>" class="btn btn-sm btn-primary fw-bold">
                                            <i class="bi bi-camera me-1"></i> Check In (Face/GPS)
                                        </a>
                                    <?php elseif ($hasCheckedIn && !$hasTimedOut): ?>
                                        <a href="time-out.php?event_id=<?= $eventId ?>" class="btn btn-sm btn-warning fw-bold text-dark">
                                            <i class="bi bi-box-arrow-left me-1"></i> Time Out (Face/GPS)
                                        </a>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><i class="bi bi-check-circle me-1"></i> Completed</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Section 2: Available Events to Join -->
    <div class="row mb-3 mt-4">
        <div class="col">
            <h3 class="h5 text-secondary fw-bold">Available Events to Join</h3>
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
                $evtImg = !empty($evt['image_url']) ? htmlspecialchars($evt['image_url']) : 'https://images.unsplash.com/photo-1540575467063-178a50c2df87?w=800&q=80';
                $eLat = $evt['latitude'] ?? null;
                $eLng = $evt['longitude'] ?? null;
            ?>
                <div class="col-md-4 mb-4">
                    <div class="card h-100 shadow-sm border-light">
                        <img src="<?= $evtImg ?>" class="card-img-top event-card-img" alt="Event Image" style="filter: brightness(0.9);">
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title text-dark fw-bold"><?= htmlspecialchars($evt['title']) ?></h5>
                            
                            <div class="d-flex align-items-center mb-2">
                                <i class="bi bi-geo-alt text-danger me-2"></i>
                                <span class="text-muted fw-semibold me-2"><?= htmlspecialchars($evt['venue']) ?></span>
                                <?php if ($eLat && $eLng): ?>
                                    <a href="https://www.google.com/maps?q=<?= $eLat ?>,<?= $eLng ?>" target="_blank" class="badge bg-light text-primary border text-decoration-none map-badge">
                                        <i class="bi bi-map me-1"></i>Map
                                    </a>
                                <?php endif; ?>
                            </div>

                            <p class="card-text small mb-3">
                                <strong><i class="bi bi-clock me-1"></i> Start:</strong> <?= date('M d, Y h:i A', strtotime($evt['start_datetime'])) ?><br>
                                <span class="text-muted"><?= htmlspecialchars(substr($evt['description'] ?? '', 0, 80)) ?>...</span>
                            </p>
                            <form method="POST" class="mt-auto">
                                <input type="hidden" name="register_event_id" value="<?= htmlspecialchars($evt['id']) ?>">
                                <button type="submit" class="btn btn-outline-primary btn-sm w-100 fw-bold">Register For Event</button>
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