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
    <meta name="theme-color" content="#0d0916">
    <title>Browse Events - EMIS</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>
<body class="participant-page browse-events-page">
<?php include '../includes/participant-nav.php'; ?>

<main class="browse-events-main">
    <section class="portal-page-heading">
<div class="portal-page-heading-copy"><span class="portal-page-eyebrow"><i class="bi bi-calendar2-week"></i> PARTICIPANT PORTAL</span><h1>Discover campus events</h1><p>Find something happening on campus, explore the details, and save your spot.</p></div>

</section>
<section class="browse-events-summary" aria-label="Available events summary">
        <div class="browse-summary-icon"><i class="bi bi-calendar-check"></i></div>
        <div>
            <span class="browse-summary-label">OPEN FOR PARTICIPATION</span>
            <strong><?= count($events) ?> <span><?= count($events) === 1 ? 'event' : 'events' ?> available</span></strong>
        </div>
        <span class="browse-summary-note"><i class="bi bi-clock"></i> Upcoming and ongoing</span>
    </section>

    <?php if ($successMsg): ?>
        <div class="browse-feedback browse-feedback-success" role="status"><i class="bi bi-check-circle-fill"></i><?= htmlspecialchars($successMsg) ?></div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
        <div class="browse-feedback browse-feedback-error" role="alert"><i class="bi bi-exclamation-circle-fill"></i><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <section class="browse-events-section">
        <div class="browse-section-heading">
            <div>
                <span class="browse-section-kicker">WHAT'S HAPPENING</span>
                <h2>Available events</h2>
            </div>
            <span class="browse-event-count"><?= count($events) ?> listed</span>
        </div>

        <?php if (empty($events)): ?>
            <div class="browse-empty-state">
                <div class="browse-empty-icon"><i class="bi bi-calendar2-x"></i></div>
                <h3>No available events right now</h3>
                <p>There are no upcoming or ongoing events to join at the moment. Check back later for new campus activities.</p>
                
            </div>
        <?php else: ?>
            <div class="browse-event-grid">
                <?php foreach ($events as $event):
                    $eventTitle = $event['title'] ?? 'Untitled Event';
                    $eventVenue = $event['venue'] ?? 'Venue to be announced';
                    $eventDescription = trim((string)($event['description'] ?? ''));
                    $eventImage = trim((string)($event['image_url'] ?? ''));
                    $eventDateLabel = 'Schedule unavailable';
                    $eventTimeLabel = '';

                    if (!empty($event['start_datetime'])) {
                        try {
                            $eventStart = (new DateTimeImmutable($event['start_datetime']))->setTimezone($manilaTimezone);
                            $eventDateLabel = $eventStart->format('M d, Y');
                            $eventTimeLabel = $eventStart->format('g:i A');
                        } catch (Exception $e) {
                            $eventDateLabel = 'Schedule unavailable';
                        }
                    }
                ?>
                    <article class="browse-event-card">
                        <div class="browse-event-visual">
                            <?php if ($eventImage !== ''): ?>
                                <img src="<?= htmlspecialchars($eventImage, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($eventTitle, ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
                            <?php else: ?>
                                <div class="browse-event-image-fallback"><i class="bi bi-calendar2-event"></i><span>EMIS CAMPUS EVENT</span></div>
                            <?php endif; ?>
                            <span class="browse-event-status"><span></span> Available</span>
                        </div>
                        <div class="browse-event-content">
                            <div class="browse-event-date"><i class="bi bi-calendar3"></i><?= htmlspecialchars($eventDateLabel) ?><?php if ($eventTimeLabel !== ''): ?><span class="browse-date-divider">·</span><?= htmlspecialchars($eventTimeLabel) ?><?php endif; ?></div>
                            <h3><?= htmlspecialchars($eventTitle) ?></h3>
                            <p class="browse-event-description"><?= htmlspecialchars($eventDescription !== '' ? $eventDescription : 'No event description has been provided yet.') ?></p>
                            <div class="browse-event-venue"><i class="bi bi-geo-alt"></i><span><?= htmlspecialchars($eventVenue) ?></span></div>
                            <form method="POST" class="browse-register-form">
                                <input type="hidden" name="event_id" value="<?= htmlspecialchars((string)($event['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="browse-register-button"><span>Register for event</span><i class="bi bi-arrow-up-right"></i></button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>