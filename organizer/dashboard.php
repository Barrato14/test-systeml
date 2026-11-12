<?php
date_default_timezone_set('Asia/Manila');

require_once '../config/database.php';
require_once '../includes/auth.php';

requireRole(['Event Organizer']);

$eventsResponse = supabaseRequest(
    'events?select=id,title,start_datetime,end_datetime&order=start_datetime.asc',
    'GET'
);

$events = (
    ($eventsResponse['code'] ?? 500) < 400 &&
    is_array($eventsResponse['data'] ?? null)
) ? $eventsResponse['data'] : [];

$totalEvents = count($events);
$upcomingEvents = 0;
$ongoingEvents = 0;
$completedEvents = 0;

$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));

foreach ($events as $event) {
    if (empty($event['start_datetime']) || empty($event['end_datetime'])) {
        continue;
    }

    try {
        $start = new DateTimeImmutable($event['start_datetime']);
        $end = new DateTimeImmutable($event['end_datetime']);

        if ($now < $start) {
            $upcomingEvents++;
        } elseif ($now <= $end) {
            $ongoingEvents++;
        } else {
            $completedEvents++;
        }
    } catch (Exception $e) {
        continue;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Event Organizer Dashboard - EMIS</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../css/portal-theme.css">
</head>
<body class="bg-light">
<div class="container py-5">

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h2 class="fw-bold mb-1">Event Organizer Dashboard</h2>
            <p class="text-muted mb-0">
                Welcome, <?= htmlspecialchars($_SESSION['user_name'] ?? 'Event Organizer') ?>
            </p>
        </div>
        <a href="../logout.php" class="btn btn-outline-danger">Sign Out</a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">Total Events</p>
                    <h2><?= $totalEvents ?></h2>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">Upcoming Events</p>
                    <h2><?= $upcomingEvents ?></h2>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">Ongoing Events</p>
                    <h2><?= $ongoingEvents ?></h2>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">Completed Events</p>
                    <h2><?= $completedEvents ?></h2>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <h5 class="fw-bold">Event Management</h5>
            <p class="text-muted">
                Create, update, delete, and send notifications for events.
                You can manage all events in the system.
            </p>
            <a href="../admin/events.php" class="btn btn-primary">
                Manage All Events
            </a>
        </div>
    </div>

</div>
</body>
</html>
