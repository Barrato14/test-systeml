<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once '../config/database.php';
require_once '../includes/auth.php';

// Ensure the user is logged in and is a Participant.
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role'] ?? '') !== 'participant') {
    header('Location: ../login.php');
    exit();
}

$participant_id = $_SESSION['user_id'];
$firstName = $_SESSION['user_name'] ?? 'Student';

$manilaTimezone = new DateTimeZone('Asia/Manila');

/**
 * Returns true when an event has ended or has an invalid/missing end time.
 * The comparison is performed using Philippine time.
 */
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

// Guard: Check if user (especially Google / OAuth users) has completed course and section.
$profileRes = supabaseRequest(
    'profiles?id=eq.' . rawurlencode((string)$participant_id) . '&select=course,section',
    'GET'
);

if (($profileRes['code'] ?? 500) === 200 && !empty($profileRes['data'])) {
    $profile = $profileRes['data'][0];

    if (empty($profile['course']) || empty($profile['section'])) {
        header('Location: complete-profile.php');
        exit();
    }
}

$successMsg = '';
$errorMsg = '';

// Handle registration. The event schedule is checked on the server
// before either creating a registration or updating an existing one.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_event_id'])) {
    $eventIdToRegister = trim((string)$_POST['register_event_id']);

    if ($eventIdToRegister === '') {
        $errorMsg = 'Invalid event selected.';
    } else {
        $eventCheck = supabaseRequest(
            'events?id=eq.' . rawurlencode($eventIdToRegister) . '&select=id,title,start_datetime,end_datetime',
            'GET'
        );

        if (($eventCheck['code'] ?? 500) >= 400 || empty($eventCheck['data'])) {
            $errorMsg = 'Event not found. Registration is unavailable.';
        } else {
            $targetEvent = $eventCheck['data'][0];

            if (eventHasEnded($targetEvent, $manilaTimezone)) {
                $errorMsg = 'Registration is closed. This event has already ended.';
            } elseif (empty($targetEvent['start_datetime'])) {
                $errorMsg = 'This event has an incomplete schedule. Registration is unavailable.';
            } else {
                try {
                    $eventStart = new DateTimeImmutable($targetEvent['start_datetime']);
                    $eventStart = $eventStart->setTimezone($manilaTimezone);
                    $now = new DateTimeImmutable('now', $manilaTimezone);

                    if ($now < $eventStart) {
                        // Upcoming event: registration is allowed.
                    } else {
                        // Ongoing event: registration remains allowed, as requested.
                    }

                    $checkExisting = supabaseRequest(
                        'event_registrations?participant_id=eq.' . rawurlencode((string)$participant_id) .
                        '&event_id=eq.' . rawurlencode($eventIdToRegister),
                        'GET'
                    );

                    if (($checkExisting['code'] ?? 500) >= 400) {
                        $errorMsg = 'Unable to verify your existing registration. Please try again.';
                    } elseif (!empty($checkExisting['data'])) {
                        $updateRes = supabaseRequest(
                            'event_registrations?participant_id=eq.' . rawurlencode((string)$participant_id) .
                            '&event_id=eq.' . rawurlencode($eventIdToRegister),
                            'PATCH',
                            ['status' => 'Approved']
                        );

                        if (($updateRes['code'] ?? 500) >= 400) {
                            $errorMsg = 'Failed to update your registration. Please try again.';
                        } else {
                            $successMsg = 'Registration updated and approved for testing!';
                        }
                    } else {
                        $payload = [
                            'participant_id' => $participant_id,
                            'event_id' => $eventIdToRegister,
                            'status' => 'Approved'
                        ];

                        $regResponse = supabaseRequest('event_registrations', 'POST', $payload);

                        if (($regResponse['code'] ?? 500) >= 400) {
                            $errorMsg = 'Failed to register. Please check your database table columns.';
                        } else {
                            $successMsg = 'Successfully registered and approved for the event!';
                        }
                    }
                } catch (Exception $e) {
                    $errorMsg = 'The event schedule is invalid. Registration is unavailable.';
                }
            }
        }
    }
}

// Fetch all registrations for this participant.
$regResponse = supabaseRequest(
    'event_registrations?participant_id=eq.' . rawurlencode((string)$participant_id),
    'GET'
);
$registrations = (($regResponse['code'] ?? 500) < 400 && is_array($regResponse['data'] ?? null))
    ? $regResponse['data'] : [];

// Fetch existing attendance logs.
$attendanceRes = supabaseRequest(
    'attendance?participant_id=eq.' . rawurlencode((string)$participant_id) .
    '&select=event_id,check_in_time,time_out',
    'GET'
);

$participantAttendance = [];
if (($attendanceRes['code'] ?? 500) === 200 && is_array($attendanceRes['data'] ?? null)) {
    foreach ($attendanceRes['data'] as $att) {
        $participantAttendance[$att['event_id']] = $att;
    }
}

// Fetch all events.
$allEventsResponse = supabaseRequest('events?order=start_datetime.asc', 'GET');
$allEvents = (($allEventsResponse['code'] ?? 500) < 400 && is_array($allEventsResponse['data'] ?? null))
    ? $allEventsResponse['data'] : [];

// Index events by ID.
$eventsMap = [];
foreach ($allEvents as $evt) {
    if (isset($evt['id'])) {
        $eventsMap[$evt['id']] = $evt;
    }
}

// Extract registered event IDs.
$registeredEventIds = [];
foreach ($registrations as $reg) {
    if (isset($reg['event_id'])) {
        $registeredEventIds[] = (string)$reg['event_id'];
    }
}

// Prepare upcoming and ongoing events for the read-only participant calendar.
$calendarEvents = [];
$now = new DateTimeImmutable('now', $manilaTimezone);

foreach ($allEvents as $evt) {
    if (empty($evt['start_datetime']) || empty($evt['end_datetime'])) {
        continue;
    }

    try {
        $eventStart = (new DateTimeImmutable($evt['start_datetime']))->setTimezone($manilaTimezone);
        $eventEnd = (new DateTimeImmutable($evt['end_datetime']))->setTimezone($manilaTimezone);

        // Events disappear from the calendar at the exact end time.
        if ($eventEnd <= $now) {
            continue;
        }

        $eventStatus = ($now < $eventStart) ? 'Upcoming' : 'Ongoing';
        $eventColor = ($eventStatus === 'Ongoing') ? '#0f9d75' : '#4f46e5';

        $calendarEvents[] = [
            'id' => (string)($evt['id'] ?? ''),
            'title' => $evt['title'] ?? 'Untitled Event',
            'start' => $evt['start_datetime'],
            'end' => $evt['end_datetime'],
            'backgroundColor' => $eventColor,
            'borderColor' => $eventColor,
            'extendedProps' => [
                'description' => $evt['description'] ?? '',
                'venue' => $evt['venue'] ?? 'Not specified',
                'image_url' => $evt['image_url'] ?? '',
                'latitude' => $evt['latitude'] ?? null,
                'longitude' => $evt['longitude'] ?? null,
                'status' => $eventStatus
            ]
        ];
    } catch (Exception $e) {
        continue;
    }
}

$calendarJson = json_encode(
    $calendarEvents,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Participant Dashboard - EMIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.19/index.global.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.19/index.global.min.js"></script>
    <style>
        .event-card-img {
            height: 180px;
            object-fit: cover;
            border-top-left-radius: calc(.25rem - 1px);
            border-top-right-radius: calc(.25rem - 1px);
        }
        .map-badge { transition: all 0.2s ease; }
        .map-badge:hover {
            background-color: #e9ecef !important;
            transform: scale(1.05);
        }
        .calendar-launch-card {
            border: 1px solid #e7eaf0;
            border-radius: 14px;
            background: #fff;
            transition: transform .18s ease, box-shadow .18s ease;
        }
        .calendar-launch-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(15, 23, 42, .08);
        }
        .calendar-launch-button {
            border-radius: 10px;
            font-weight: 600;
            padding: 10px 16px;
        }
        #participantCalendar {
            background: #fff;
            padding: 14px;
            border-radius: 12px;
        }
        #participantCalendar .fc-toolbar-title {
            font-size: 1.25rem;
            font-weight: 700;
        }
        #participantCalendar .fc-button-primary {
            background: #fff;
            color: #475569;
            border: 1px solid #dce3ed;
            box-shadow: none;
        }
        #participantCalendar .fc-button-primary:hover,
        #participantCalendar .fc-button-primary:focus {
            background: #eef2ff;
            color: #4338ca;
            border-color: #c7d2fe;
        }
        #participantCalendar .fc-day-today { background: #eef2ff !important; }
        #participantCalendar .fc-event {
            cursor: pointer;
            border: 0;
            border-radius: 5px;
            padding: 2px 4px;
        }
        .calendar-legend {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            color: #64748b;
            font-size: .85rem;
            margin-bottom: 14px;
        }
        .calendar-legend span {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .calendar-legend i {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            display: inline-block;
        }
        @media (max-width: 576px) {
            #participantCalendar { padding: 4px; }
            #participantCalendar .fc-toolbar { flex-direction: column; gap: 8px; }
            #participantCalendar .fc-toolbar-title { font-size: 1.05rem; }
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
    <?php if ($successMsg): ?>
        <div class="alert alert-success"><?= htmlspecialchars($successMsg) ?></div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col">
            <h2 class="h4">My Approved Events & Check-In</h2>
            <p class="text-muted">Perform your secure face-scan and GPS check-in/time-out for approved events here.</p>
        </div>
    </div>

    <!-- Clickable calendar launcher: participants can view events, but cannot manage them. -->
    <div class="calendar-launch-card p-3 p-md-4 mb-4">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-3 bg-primary-subtle text-primary d-flex align-items-center justify-content-center"
                     style="width:52px;height:52px;flex-shrink:0;">
                    <i class="bi bi-calendar3 fs-3"></i>
                </div>
                <div>
                    <h5 class="mb-1 fw-bold">Event Calendar</h5>
                    <p class="text-muted mb-0 small">Browse upcoming and ongoing events by date.</p>
                </div>
            </div>
            <button type="button" class="btn btn-primary calendar-launch-button"
                    data-bs-toggle="modal" data-bs-target="#participantCalendarModal">
                <i class="bi bi-calendar-week me-2"></i>View Calendar
            </button>
        </div>
    </div>

    <!-- Read-only calendar modal -->
    <div class="modal fade" id="participantCalendarModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-dark text-white">
                    <div>
                        <h5 class="modal-title text-white mb-1">Upcoming Event Calendar</h5>
                        <small class="text-white-50">Select an event to view its details.</small>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-3 p-md-4">
                    <div class="calendar-legend">
                        <span><i style="background:#4f46e5"></i> Upcoming</span>
                        <span><i style="background:#0f9d75"></i> Ongoing</span>
                    </div>
                    <div id="participantCalendar"></div>
                    <div id="noCalendarEvents" class="alert alert-light border text-center mt-3 mb-0" style="display:none;">
                        No upcoming or ongoing events are scheduled at the moment.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Event details modal (view-only) -->
    <div class="modal fade" id="participantEventDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-primary text-white">
                    <div>
                        <span class="badge bg-light text-primary" id="participantEventStatus">Upcoming</span>
                        <h5 class="modal-title text-white mt-2 mb-0" id="participantEventTitle">Event Details</h5>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <img id="participantEventImage" src="" alt="Event cover"
                         class="img-fluid rounded mb-3 w-100"
                         style="display:none;max-height:260px;object-fit:cover;">
                    <p id="participantEventDescription" class="text-muted"></p>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <div class="border rounded p-3 h-100">
                                <small class="text-muted d-block fw-bold">START</small>
                                <strong id="participantEventStart"></strong>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="border rounded p-3 h-100">
                                <small class="text-muted d-block fw-bold">END</small>
                                <strong id="participantEventEnd"></strong>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="border rounded p-3">
                                <small class="text-muted d-block fw-bold">VENUE</small>
                                <strong id="participantEventVenue"></strong>
                            </div>
                        </div>
                    </div>
                    <iframe id="participantEventMap" title="Event location map"
                            class="w-100 rounded" style="height:220px;border:0;display:none;"
                            loading="lazy"></iframe>
                    <a id="participantEventMapLink" href="#" target="_blank" rel="noopener"
                       class="btn btn-outline-primary btn-sm mt-2" style="display:none;">
                        <i class="bi bi-geo-alt me-1"></i>Open in Google Maps
                    </a>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 1: Registered / Approved Events (ended events are hidden) -->
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
                $eventId = (string)($reg['event_id'] ?? '');
                $event = $eventsMap[$eventId] ?? null;
                if (!$event) continue;

                // Completely remove ended events from the participant dashboard.
                if (eventHasEnded($event, $manilaTimezone)) {
                    continue;
                }

                $status = $reg['status'] ?? 'Approved';
                $dateObj = new DateTime($event['start_datetime']);
                $dateObj->setTimezone($manilaTimezone);
                $formattedDate = $dateObj->format('F j, Y, g:i a');

                $eventImg = !empty($event['image_url'])
                    ? htmlspecialchars($event['image_url'])
                    : 'https://images.unsplash.com/photo-1540575467063-178a50c2df87?w=800&q=80';

                $lat = $event['latitude'] ?? null;
                $lng = $event['longitude'] ?? null;

                $attRecord = $participantAttendance[$eventId] ?? null;
                $hasCheckedIn = !empty($attRecord['check_in_time']);
                $hasTimedOut = !empty($attRecord['time_out']);
                $eventEnded = eventHasEnded($event, $manilaTimezone);
            ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card h-100 shadow-sm border-0">
                        <img src="<?= $eventImg ?>" class="card-img-top event-card-img" alt="Event Image">

                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title text-primary fw-bold"><?= htmlspecialchars($event['title'] ?? 'Untitled Event') ?></h5>

                            <div class="d-flex align-items-center mb-2">
                                <i class="bi bi-geo-alt-fill text-danger me-2"></i>
                                <span class="text-muted fw-semibold me-2"><?= htmlspecialchars($event['venue'] ?? 'General Venue') ?></span>
                                <?php if ($lat && $lng): ?>
                                    <a href="https://www.google.com/maps?q=<?= rawurlencode($lat . ',' . $lng) ?>"
                                       target="_blank" rel="noopener"
                                       class="badge bg-light text-primary border text-decoration-none map-badge">
                                        <i class="bi bi-map me-1"></i>Map
                                    </a>
                                <?php endif; ?>
                            </div>

                            <p class="card-text small mb-3">
                                <strong><i class="bi bi-calendar-event me-1"></i> Date:</strong> <?= htmlspecialchars($formattedDate) ?><br>
                                <span class="text-muted"><?= htmlspecialchars(substr($event['description'] ?? '', 0, 90)) ?>...</span>
                            </p>

                            <div class="mt-auto">
                                <hr>
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <?php if ($eventEnded): ?>
                                        <span class="badge bg-secondary">Event Ended</span>
                                    <?php else: ?>
                                        <span class="badge bg-success"><?= htmlspecialchars($status) ?></span>
                                    <?php endif; ?>

                                    <?php if ($eventEnded): ?>
                                        <span class="badge bg-secondary">
                                            <i class="bi bi-calendar-x me-1"></i>Attendance Closed
                                        </span>
                                    <?php elseif (!$hasCheckedIn): ?>
                                        <a href="check-in.php?event_id=<?= rawurlencode($eventId) ?>"
                                           class="btn btn-sm btn-primary fw-bold">
                                            <i class="bi bi-camera me-1"></i> Check In (Face/GPS)
                                        </a>
                                    <?php elseif (!$hasTimedOut): ?>
                                        <a href="time-out.php?event_id=<?= rawurlencode($eventId) ?>"
                                           class="btn btn-sm btn-warning fw-bold text-dark">
                                            <i class="bi bi-box-arrow-left me-1"></i> Time Out (Face/GPS)
                                        </a>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">
                                            <i class="bi bi-check-circle me-1"></i>Completed
                                        </span>
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

    <?php
    // Only show events that have not ended and that this participant has not registered for.
    $availableEvents = [];

    foreach ($allEvents as $evt) {
        $evtId = (string)($evt['id'] ?? '');

        if ($evtId === '' || in_array($evtId, $registeredEventIds, true)) {
            continue;
        }

        if (eventHasEnded($evt, $manilaTimezone)) {
            continue;
        }

        $availableEvents[] = $evt;
    }
    ?>

    <div class="row">
        <?php if (empty($availableEvents)): ?>
            <div class="col-12 text-muted pb-4">
                There are currently no available events to register for.
            </div>
        <?php else: ?>
            <?php foreach ($availableEvents as $evt):
                $evtImg = !empty($evt['image_url'])
                    ? htmlspecialchars($evt['image_url'])
                    : 'https://images.unsplash.com/photo-1540575467063-178a50c2df87?w=800&q=80';

                $eLat = $evt['latitude'] ?? null;
                $eLng = $evt['longitude'] ?? null;

                $startDate = '';
                if (!empty($evt['start_datetime'])) {
                    try {
                        $startObj = new DateTime($evt['start_datetime']);
                        $startObj->setTimezone($manilaTimezone);
                        $startDate = $startObj->format('M d, Y h:i A');
                    } catch (Exception $e) {
                        $startDate = 'Schedule not available';
                    }
                }
            ?>
                <div class="col-md-4 mb-4">
                    <div class="card h-100 shadow-sm border-light">
                        <img src="<?= $evtImg ?>" class="card-img-top event-card-img" alt="Event Image" style="filter: brightness(0.9);">

                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title text-dark fw-bold"><?= htmlspecialchars($evt['title'] ?? 'Untitled Event') ?></h5>

                            <div class="d-flex align-items-center mb-2">
                                <i class="bi bi-geo-alt text-danger me-2"></i>
                                <span class="text-muted fw-semibold me-2"><?= htmlspecialchars($evt['venue'] ?? 'Not specified') ?></span>
                                <?php if ($eLat !== null && $eLat !== '' && $eLng !== null && $eLng !== ''): ?>
                                    <a href="https://www.google.com/maps?q=<?= rawurlencode($eLat . ',' . $eLng) ?>"
                                       target="_blank" rel="noopener"
                                       class="badge bg-light text-primary border text-decoration-none map-badge">
                                        <i class="bi bi-map me-1"></i>Map
                                    </a>
                                <?php endif; ?>
                            </div>

                            <p class="card-text small mb-3">
                                <strong><i class="bi bi-clock me-1"></i> Start:</strong> <?= htmlspecialchars($startDate) ?><br>
                                <span class="text-muted"><?= htmlspecialchars(substr($evt['description'] ?? '', 0, 80)) ?>...</span>
                            </p>

                            <form method="POST" class="mt-auto">
                                <input type="hidden" name="register_event_id" value="<?= htmlspecialchars((string)$evt['id']) ?>">
                                <button type="submit" class="btn btn-outline-primary btn-sm w-100 fw-bold">
                                    Register For Event
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const participantCalendarEvents = <?= $calendarJson ?: '[]' ?>;

document.addEventListener('DOMContentLoaded', function () {
    const calendarModalElement = document.getElementById('participantCalendarModal');
    const detailsModalElement = document.getElementById('participantEventDetailsModal');
    const calendarModal = bootstrap.Modal.getOrCreateInstance(calendarModalElement);
    const detailsModal = bootstrap.Modal.getOrCreateInstance(detailsModalElement);

    let calendar = null;
    let reopenCalendarAfterDetails = false;

    function formatPHT(date) {
        if (!date) return 'Not specified';

        return new Intl.DateTimeFormat('en-PH', {
            timeZone: 'Asia/Manila',
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit'
        }).format(date);
    }

    function showParticipantEvent(event) {
        const props = event.extendedProps;

        document.getElementById('participantEventTitle').textContent = event.title;
        document.getElementById('participantEventStatus').textContent = props.status || 'Upcoming';
        document.getElementById('participantEventDescription').textContent =
            props.description || 'No description provided.';
        document.getElementById('participantEventStart').textContent = formatPHT(event.start);
        document.getElementById('participantEventEnd').textContent = formatPHT(event.end);
        document.getElementById('participantEventVenue').textContent = props.venue || 'Not specified';

        const image = document.getElementById('participantEventImage');

        if (props.image_url) {
            image.src = props.image_url;
            image.style.display = 'block';
            image.onerror = function () { image.style.display = 'none'; };
        } else {
            image.removeAttribute('src');
            image.style.display = 'none';
        }

        const map = document.getElementById('participantEventMap');
        const mapLink = document.getElementById('participantEventMapLink');

        if (props.latitude !== null && props.latitude !== '' &&
            props.longitude !== null && props.longitude !== '') {
            const coordinates = props.latitude + ',' + props.longitude;

            map.src = 'https://maps.google.com/maps?q=' +
                encodeURIComponent(coordinates) + '&z=15&output=embed';
            map.style.display = 'block';

            mapLink.href = 'https://www.google.com/maps?q=' +
                encodeURIComponent(coordinates);
            mapLink.style.display = 'inline-block';
        } else {
            map.removeAttribute('src');
            map.style.display = 'none';
            mapLink.style.display = 'none';
        }

        reopenCalendarAfterDetails = true;
        calendarModal.hide();
    }

    calendarModalElement.addEventListener('shown.bs.modal', function () {
        if (!calendar) {
            calendar = new FullCalendar.Calendar(
                document.getElementById('participantCalendar'),
                {
                    initialView: 'dayGridMonth',
                    timeZone: 'Asia/Manila',
                    height: 'auto',
                    fixedWeekCount: false,
                    dayMaxEvents: true,
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: ''
                    },
                    events: participantCalendarEvents,
                    eventClick: function (info) {
                        info.jsEvent.preventDefault();
                        showParticipantEvent(info.event);
                    }
                }
            );
        }

        calendar.render();
        calendar.updateSize();

        document.getElementById('noCalendarEvents').style.display =
            participantCalendarEvents.length === 0 ? 'block' : 'none';
    });

    detailsModalElement.addEventListener('hidden.bs.modal', function () {
        if (reopenCalendarAfterDetails) {
            reopenCalendarAfterDetails = false;
            calendarModal.show();
        }
    });
});
</script>
</body>
</html>
