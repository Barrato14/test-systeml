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

// Fetch this participant's notifications, newest first.
$notificationsResponse = supabaseRequest(
    'notifications?participant_id=eq.' . rawurlencode((string)$participant_id) .
    '&select=*&order=created_at.desc',
    'GET'
);

$notifications = (($notificationsResponse['code'] ?? 500) < 400 && is_array($notificationsResponse['data'] ?? null))
    ? $notificationsResponse['data'] : [];

$unreadNotificationCount = 0;
foreach ($notifications as $notification) {
    if (($notification['is_read'] ?? false) !== true && ($notification['is_read'] ?? '') !== 'true') {
        $unreadNotificationCount++;
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

// Prepare participant dashboard statistics and the next registered event.
$dashboardNow = new DateTimeImmutable('now', $manilaTimezone);
$dashboardUpcomingCount = 0;
$dashboardAttendedCount = 0;
$dashboardAttendanceMinutes = 0;
$dashboardNextEvent = null;
$dashboardNextEventStart = null;

foreach ($registrations as $registration) {
    $registeredEventId = (string)($registration['event_id'] ?? '');
    $registeredEvent = $eventsMap[$registeredEventId] ?? null;

    if (!$registeredEvent || eventHasEnded($registeredEvent, $manilaTimezone)) {
        continue;
    }

    if (!empty($registeredEvent['start_datetime'])) {
        try {
            $registeredStart = (new DateTimeImmutable($registeredEvent['start_datetime']))->setTimezone($manilaTimezone);
            if ($registeredStart > $dashboardNow) {
                $dashboardUpcomingCount++;
            }

            if ($dashboardNextEventStart === null || $registeredStart < $dashboardNextEventStart) {
                $dashboardNextEvent = $registeredEvent;
                $dashboardNextEventStart = $registeredStart;
            }
        } catch (Exception $e) {
        }
    }
}

foreach ($participantAttendance as $attendanceRecord) {
    if (empty($attendanceRecord['check_in_time'])) {
        continue;
    }

    $dashboardAttendedCount++;

    if (!empty($attendanceRecord['time_out'])) {
        try {
            $checkIn = new DateTimeImmutable($attendanceRecord['check_in_time']);
            $timeOut = new DateTimeImmutable($attendanceRecord['time_out']);
            if ($timeOut > $checkIn) {
                $dashboardAttendanceMinutes += (int)(($timeOut->getTimestamp() - $checkIn->getTimestamp()) / 60);
            }
        } catch (Exception $e) {
        }
    }
}

$dashboardRegistrationCount = count($registrations);
$dashboardAttendanceRate = $dashboardRegistrationCount > 0
    ? min(100, (int)round(($dashboardAttendedCount / $dashboardRegistrationCount) * 100))
    : 0;
$dashboardAttendanceHours = round($dashboardAttendanceMinutes / 60, 1);

// Combine announcements and attendance records into a small recent-activity feed.
$dashboardActivities = [];

foreach (array_slice($notifications, 0, 8) as $notification) {
    $dashboardActivities[] = [
        'title' => $notification['title'] ?? 'New notification',
        'message' => $notification['message'] ?? '',
        'date' => $notification['created_at'] ?? '',
        'icon' => 'bi-megaphone',
        'kind' => 'announcement'
    ];
}

foreach ($participantAttendance as $attendanceEventId => $attendanceRecord) {
    if (empty($attendanceRecord['check_in_time'])) {
        continue;
    }

    $activityEvent = $eventsMap[$attendanceEventId] ?? [];
    $dashboardActivities[] = [
        'title' => 'Attendance recorded',
        'message' => $activityEvent['title'] ?? 'Event attendance',
        'date' => $attendanceRecord['time_out'] ?: $attendanceRecord['check_in_time'],
        'icon' => 'bi-check2-circle',
        'kind' => 'attendance'
    ];
}

usort($dashboardActivities, function ($a, $b) {
    return strtotime((string)($b['date'] ?? '')) <=> strtotime((string)($a['date'] ?? ''));
});
$dashboardActivities = array_slice($dashboardActivities, 0, 5);

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
<link rel="stylesheet" href="../css/style.css">
<link rel="stylesheet" href="../css/weather-widget.css">
<link rel="stylesheet" href="../css/participant-dashboard.css?v=20261113-1">
</head>
<body class="bg-light participant-page">

<?php $showParticipantNotifications = true; include '../includes/participant-nav.php'; ?>

<div class="container">
    <?php if ($successMsg): ?>
        <div class="alert alert-success"><?= htmlspecialchars($successMsg) ?></div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <section class="participant-dashboard-hero mb-4">
        <div>
            <span class="participant-dashboard-eyebrow">PARTICIPANT OVERVIEW</span>
            <h1>Welcome back, <?= htmlspecialchars((string)$firstName) ?>!</h1>
            <p>Your events, attendance progress, and latest updates in one place.</p>
        </div>
        <a href="browse-events.php" class="participant-dashboard-primary-link"><i class="bi bi-compass"></i> Explore Events</a>
    </section>

    <?php include __DIR__ . '/../includes/weather-widget.php'; ?>

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

    <section class="participant-dashboard-stats mb-4">
        <article class="participant-dashboard-stat">
            <span class="participant-dashboard-stat-icon"><i class="bi bi-calendar2-check"></i></span>
            <div><strong><?= number_format($dashboardRegistrationCount) ?></strong><span>Registered events</span></div>
        </article>
        <article class="participant-dashboard-stat">
            <span class="participant-dashboard-stat-icon"><i class="bi bi-calendar-event"></i></span>
            <div><strong><?= number_format($dashboardUpcomingCount) ?></strong><span>Upcoming events</span></div>
        </article>
        <article class="participant-dashboard-stat">
            <span class="participant-dashboard-stat-icon"><i class="bi bi-clock-history"></i></span>
            <div><strong><?= number_format($dashboardAttendanceHours, 1) ?> <small>hrs</small></strong><span>Recorded attendance</span></div>
        </article>
        <article class="participant-dashboard-stat">
            <span class="participant-dashboard-stat-icon"><i class="bi bi-graph-up-arrow"></i></span>
            <div><strong><?= $dashboardAttendanceRate ?>%</strong><span>Attendance rate</span></div>
        </article>
    </section>

    <section class="participant-dashboard-main-grid mb-4">
        <article class="participant-dashboard-panel participant-dashboard-next-event">
            <div class="participant-dashboard-panel-heading">
                <div><span class="participant-dashboard-eyebrow">UP NEXT</span><h2>Your next event</h2></div>
                <i class="bi bi-calendar2-week"></i>
            </div>
            <?php if ($dashboardNextEvent && $dashboardNextEventStart): ?>
                <h3><?= htmlspecialchars($dashboardNextEvent['title'] ?? 'Untitled Event') ?></h3>
                <p class="participant-dashboard-event-description"><?= htmlspecialchars($dashboardNextEvent['description'] ?? 'You are registered for this event.') ?></p>
                <div class="participant-dashboard-event-details">
                    <span><i class="bi bi-calendar3"></i><?= htmlspecialchars($dashboardNextEventStart->format('F j, Y')) ?></span>
                    <span><i class="bi bi-clock"></i><?= htmlspecialchars($dashboardNextEventStart->format('g:i A')) ?> PHT</span>
                    <span><i class="bi bi-geo-alt"></i><?= htmlspecialchars($dashboardNextEvent['venue'] ?? 'Venue to be announced') ?></span>
                </div>
                <a href="my-events.php" class="participant-dashboard-text-link">View my events <i class="bi bi-arrow-right"></i></a>
            <?php else: ?>
                <div class="participant-dashboard-empty">
                    <i class="bi bi-calendar-plus"></i>
                    <strong>No upcoming registered events</strong>
                    <span>Explore available events and register for one.</span>
                    <a href="browse-events.php" class="participant-dashboard-text-link">Browse events <i class="bi bi-arrow-right"></i></a>
                </div>
            <?php endif; ?>
        </article>

        <article class="participant-dashboard-panel participant-dashboard-progress">
            <div class="participant-dashboard-panel-heading">
                <div><span class="participant-dashboard-eyebrow">YOUR PARTICIPATION</span><h2>Attendance progress</h2></div>
                <i class="bi bi-bar-chart-line"></i>
            </div>
            <div class="participant-dashboard-progress-value"><?= $dashboardAttendanceRate ?>%</div>
            <p>of your registered events have a recorded check-in.</p>
            <div class="participant-dashboard-progress-track"><span style="width: <?= $dashboardAttendanceRate ?>%;"></span></div>
            <div class="participant-dashboard-progress-meta">
                <span><?= number_format($dashboardAttendedCount) ?> attended</span>
                <span><?= number_format($dashboardRegistrationCount) ?> registered</span>
            </div>
            <a href="my-events.php" class="participant-dashboard-text-link">See attendance details <i class="bi bi-arrow-right"></i></a>
        </article>
    </section>

    <section class="participant-dashboard-lower-grid mb-4">
        <article class="participant-dashboard-panel">
            <div class="participant-dashboard-panel-heading">
                <div><span class="participant-dashboard-eyebrow">LATEST UPDATES</span><h2>Announcements</h2></div>
                <button type="button" class="participant-dashboard-icon-button" data-open-notifications aria-label="Open all notifications"><i class="bi bi-bell"></i></button>
            </div>
            <?php if (empty($notifications)): ?>
                <div class="participant-dashboard-empty compact"><i class="bi bi-megaphone"></i><span>No announcements yet. New event announcements will appear here.</span></div>
            <?php else: ?>
                <div class="participant-dashboard-announcements">
                    <?php foreach (array_slice($notifications, 0, 4) as $announcement): ?>
                        <div class="participant-dashboard-announcement">
                            <span class="participant-dashboard-announcement-icon"><i class="bi bi-megaphone"></i></span>
                            <div><strong><?= htmlspecialchars($announcement['title'] ?? 'Announcement') ?></strong><p><?= htmlspecialchars($announcement['message'] ?? '') ?></p>
                            <?php if (!empty($announcement['created_at'])): ?><time><?= htmlspecialchars((new DateTime($announcement['created_at']))->setTimezone($manilaTimezone)->format('M j, Y · g:i A')) ?></time><?php endif; ?></div>
                            <?php if (($announcement['is_read'] ?? false) !== true && ($announcement['is_read'] ?? '') !== 'true'): ?><span class="participant-dashboard-new-label">NEW</span><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="participant-dashboard-text-button" data-open-notifications>View all notifications <i class="bi bi-arrow-right"></i></button>
            <?php endif; ?>
        </article>

        <article class="participant-dashboard-panel">
            <div class="participant-dashboard-panel-heading">
                <div><span class="participant-dashboard-eyebrow">YOUR LATEST ACTIONS</span><h2>Recent activity</h2></div>
                <i class="bi bi-activity"></i>
            </div>
            <?php if (empty($dashboardActivities)): ?>
                <div class="participant-dashboard-empty compact"><i class="bi bi-clock-history"></i><span>Your event registrations and attendance updates will appear here.</span></div>
            <?php else: ?>
                <div class="participant-dashboard-activity-list">
                    <?php foreach ($dashboardActivities as $activity): ?>
                        <div class="participant-dashboard-activity">
                            <span class="participant-dashboard-activity-icon"><i class="bi <?= htmlspecialchars($activity['icon']) ?>"></i></span>
                            <div><strong><?= htmlspecialchars($activity['title']) ?></strong><p><?= htmlspecialchars($activity['message']) ?></p>
                            <?php if (!empty($activity['date'])): ?><time><?= htmlspecialchars((new DateTime($activity['date']))->setTimezone($manilaTimezone)->format('M j, Y · g:i A')) ?></time><?php endif; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
    </section>

    <section class="participant-dashboard-quick-links mb-5">
        <div><span class="participant-dashboard-eyebrow">SHORTCUTS</span><h2>Quick actions</h2></div>
        <div class="participant-dashboard-action-grid">
            <a href="browse-events.php"><i class="bi bi-compass"></i><span><strong>Browse events</strong><small>Discover campus activities</small></span><i class="bi bi-arrow-up-right"></i></a>
            <a href="my-events.php"><i class="bi bi-ticket-perforated"></i><span><strong>My events</strong><small>View registrations and attendance</small></span><i class="bi bi-arrow-up-right"></i></a>
            <a href="face-registration.php"><i class="bi bi-person-bounding-box"></i><span><strong>Face registration</strong><small>Manage your face profile</small></span><i class="bi bi-arrow-up-right"></i></a>
            <button type="button" data-open-notifications><i class="bi bi-bell"></i><span><strong>Notifications</strong><small><?= number_format($unreadNotificationCount) ?> unread updates</small></span><i class="bi bi-arrow-up-right"></i></button>
        </div>
    </section>
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
<script src="../js/weather-widget.js" defer></script>
</body>
</html>
