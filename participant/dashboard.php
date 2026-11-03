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

// Fetch the participant's registered events using Supabase embedded resources
$endpoint = "event_registrations?participant_id=eq.$participant_id&select=*,events(*)";
$response = supabaseRequest($endpoint, 'GET');

// Safely extract the data array
$registrations = [];
if (is_array($response)) {
    if (isset($response['data']) && is_array($response['data'])) {
        $registrations = $response['data'];
    } elseif (isset($response[0])) {
        $registrations = $response;
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

<!-- Simple Navigation -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4 shadow-sm">
    <div class="container">
        <a class="navbar-brand" href="#">EMIS Participant Portal</a>
        <div class="d-flex align-items-center">
            <span class="navbar-text text-white me-3">Welcome, <?= htmlspecialchars($firstName) ?></span>
            <a href="../logout.php" class="btn btn-outline-light btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="container">
    <div class="row mb-4">
        <div class="col">
            <h2 class="h4">My Registered Events</h2>
            <p class="text-muted">Perform your secure face-scan and GPS check-in for approved events here.</p>
        </div>
        <div class="col-auto">
            <a href="my-events.php" class="btn btn-primary">Browse New Events</a>
        </div>
    </div>

    <div class="row">
        <?php if (empty($registrations)): ?>
            <div class="col-12">
                <div class="alert alert-info text-center py-5 shadow-sm">
                    <h5>You haven't registered for any events yet.</h5>
                    <p class="mb-0">Click 'Browse New Events' to find something to attend!</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($registrations as $reg): 
                $event = $reg['events'] ?? null;
                if (!$event) continue; 
                
                $status = $reg['status'] ?? 'Pending';
                $eventId = $event['id'];
                
                $dateObj = new DateTime($event['start_datetime']);
                $formattedDate = $dateObj->format('F j, Y, g:i a');
            ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card h-100 shadow-sm border-0">
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title text-primary"><?= htmlspecialchars($event['title']) ?></h5>
                            <h6 class="card-subtitle mb-2 text-muted"><?= htmlspecialchars($event['venue'] ?? 'General Venue') ?></h6>
                            
                            <p class="card-text small mb-3">
                                <strong>Date:</strong> <?= $formattedDate ?><br>
                                <strong>Description:</strong> <?= htmlspecialchars(substr($event['description'] ?? '', 0, 80)) ?>...
                            </p>
                            
                            <div class="mt-auto">
                                <hr>
                                <div class="d-flex justify-content-between align-items-center">
                                    
                                    <!-- Status Badge -->
                                    <?php if ($status === 'Approved'): ?>
                                        <span class="badge bg-success">Approved</span>
                                    <?php elseif ($status === 'Pending'): ?>
                                        <span class="badge bg-warning text-dark">Pending</span>
                                    <?php elseif ($status === 'Rejected'): ?>
                                        <span class="badge bg-danger">Rejected</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?= htmlspecialchars($status) ?></span>
                                    <?php endif; ?>

                                    <!-- Secure Check-In Button -->
                                    <?php if ($status === 'Approved'): ?>
                                        <a href="check-in.php?event_id=<?= $eventId ?>" class="btn btn-sm btn-primary fw-bold">
                                            <i class="bi bi-camera me-1"></i> Check In (Face/GPS)
                                        </a>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-outline-secondary" disabled>Check-In Locked</button>
                                    <?php endif; ?>

                                </div>
                            </div>
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