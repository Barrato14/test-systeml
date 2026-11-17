<?php
require_once '../includes/session.php';
date_default_timezone_set('Asia/Manila');

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/daily-attendance.php';

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

$dailyAttendanceRes = supabaseServiceRequest(
    'daily_attendance?participant_id=eq.' . rawurlencode((string)$participant_id) . '&select=event_id,attendance_date,check_in_time,time_out,status&order=check_in_time.desc',
    'GET'
);
$participantDailyAttendance = (($dailyAttendanceRes['code'] ?? 500) < 400 && is_array($dailyAttendanceRes['data'] ?? null))
    ? $dailyAttendanceRes['data'] : [];

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

// Ignore stale registration rows whose event has been deleted.
// Keep registrations for existing past events; they remain part of registration history.
$registrations = array_values(array_filter($registrations, function ($registration) use ($eventsMap) {
    $eventId = (string)($registration['event_id'] ?? '');
    return $eventId !== '' && isset($eventsMap[$eventId]);
}));

// Attendance for deleted events must not affect dashboard statistics or recent activity.
$participantAttendance = array_filter($participantAttendance, function ($attendance) use ($eventsMap) {
    $eventId = (string)($attendance['event_id'] ?? '');
    return $eventId !== '' && isset($eventsMap[$eventId]);
});

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

$attendedEventIds = [];
foreach ($participantAttendance as $eventId => $attendanceRecord) {
    if (empty($attendanceRecord['check_in_time'])) continue;
    $attendedEventIds[(string)$eventId] = true;
    if (!empty($attendanceRecord['time_out'])) {
        try {
            $checkIn = new DateTimeImmutable($attendanceRecord['check_in_time']);
            $timeOut = new DateTimeImmutable($attendanceRecord['time_out']);
            if ($timeOut > $checkIn) $dashboardAttendanceMinutes += (int)(($timeOut->getTimestamp() - $checkIn->getTimestamp()) / 60);
        } catch (Exception $e) {}
    }
}

$dashboardDailyAttendanceCount = 0;
$dashboardDailyEventIds = [];
foreach ($participantDailyAttendance as $dailyRecord) {
    $dailyEventId = (string)($dailyRecord['event_id'] ?? '');
    if (empty($dailyRecord['check_in_time']) || !isset($eventsMap[$dailyEventId])) continue;
    $dashboardDailyAttendanceCount++;
    $attendedEventIds[$dailyEventId] = true;
    $dashboardDailyEventIds[$dailyEventId] = true;
    if (!empty($dailyRecord['time_out'])) {
        try {
            $dailyIn = new DateTimeImmutable($dailyRecord['check_in_time']);
            $dailyOut = new DateTimeImmutable($dailyRecord['time_out']);
            if ($dailyOut > $dailyIn) $dashboardAttendanceMinutes += (int)(($dailyOut->getTimestamp() - $dailyIn->getTimestamp()) / 60);
        } catch (Exception $e) {}
    }
}
$dashboardAttendedCount = count($attendedEventIds);
$dashboardRegistrationCount = count($registrations);
$dashboardAttendanceRate = $dashboardRegistrationCount > 0
    ? min(100, (int)round(($dashboardAttendedCount / $dashboardRegistrationCount) * 100))
    : 0;
$dashboardAttendanceSessionCount = $dashboardAttendedCount + $dashboardDailyAttendanceCount - count($dashboardDailyEventIds);
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

foreach ($participantDailyAttendance as $dailyRecord) {
    if (empty($dailyRecord['check_in_time'])) continue;
    $dailyEvent = $eventsMap[(string)($dailyRecord['event_id'] ?? '')] ?? [];
    $dashboardActivities[] = [
        'title' => 'Daily attendance recorded',
        'message' => ($dailyEvent['title'] ?? 'Event attendance') . ' · ' . date('M j, Y', strtotime($dailyRecord['attendance_date'])),
        'date' => $dailyRecord['time_out'] ?: $dailyRecord['check_in_time'],
        'icon' => 'bi-calendar-check',
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
                'venue_boundary' => $evt['venue_boundary'] ?? null,
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
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.19/index.global.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@turf/turf@6/turf.min.js"></script>
    <style>
        /* Keep the participant event-details map popup consistent with Zentrix's dark admin theme. */
        #participantEventDetailsModal .modal-content {
            background: #171222;
            color: #e9ddf5;
            border: 1px solid #49345f !important;
            border-radius: 16px;
            overflow: hidden;
        }
        #participantEventDetailsModal .modal-header {
            background: #21172e !important;
            border-bottom: 1px solid #49345f;
        }
        #participantEventDetailsModal .modal-body,
        #participantEventDetailsModal .modal-footer {
            background: #171222;
            color: #e9ddf5;
            border-color: #382846;
        }
        #participantEventDetailsModal .modal-body p,
        #participantEventDetailsModal .modal-body small {
            color: #bbaecb !important;
        }
        #participantEventDetailsModal .modal-body .border {
            border-color: #49345f !important;
            background: #21172e;
        }
        #participantEventDetailsModal .modal-body strong {
            color: #f7f0ff;
        }
        #participantEventMap {
            background: #21172e;
            border: 1px solid #49345f;
        }
        .participant-map-legend { display:flex; flex-wrap:wrap; gap:12px; padding-top:10px; color:#cbbbdc; font-size:11px; }
        .participant-map-legend span { display:inline-flex; align-items:center; gap:6px; }
        .participant-map-legend i { display:inline-block; width:10px; height:10px; border-radius:3px; }
        .legend-venue { background:#4ade80; } .legend-buffer { background:#a78bfa; } .legend-pin { background:#ef4444; }
        #participantEventMapLink {
            color: #dfc5ff;
            border-color: #76519a;
        }
        #participantEventMapLink:hover {
            color: #fff;
            background: #30203f;
        }

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
<link rel="stylesheet" href="../css/style.css?v=20261117-1">
<link rel="stylesheet" href="../css/weather-widget.css?v=20261113-2">
<link rel="stylesheet" href="../css/participant-dashboard.css?v=20261113-1">
<link rel="stylesheet" href="../css/participant-content-refresh.css?v=20261117-3">
</head>
<body class="bg-light participant-page">

<?php $showParticipantNotifications = true; include '../includes/participant-nav.php'; ?>

<div class="participant-dashboard-shell">
    <header class="participant-dashboard-utilitybar">
        <div class="participant-dashboard-utility-context">
            <span>Participant workspace</span>
            <small><?= htmlspecialchars(date('l, F j, Y')) ?></small>
        </div>
        <div class="participant-dashboard-utility-actions">
            <button type="button" class="participant-dashboard-utility-notifications" data-open-notifications aria-label="Open notifications"><i class="bi bi-bell"></i><span>Notifications</span><?php if ($unreadNotificationCount > 0): ?><b><?= number_format($unreadNotificationCount) ?></b><?php endif; ?></button>
            <div class="participant-dashboard-utility-profile"><span class="participant-dashboard-avatar"><?= htmlspecialchars(strtoupper(substr((string)$firstName, 0, 1))) ?></span><span><strong><?= htmlspecialchars((string)$firstName) ?></strong><small>Participant</small></span></div>
        </div>
    </header>
    <?php if ($successMsg): ?>
        <div class="alert alert-success"><?= htmlspecialchars($successMsg) ?></div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <div class="participant-dashboard-topline">
        <div>
            <span class="participant-dashboard-kicker">YOUR CAMPUS, CONNECTED</span>
            <h1>Good day, <?= htmlspecialchars((string)$firstName) ?> <span aria-hidden="true">✦</span></h1>
            <p>Here's what's happening in your campus life.</p>
        </div>
        <a href="browse-events.php" class="participant-dashboard-primary-link"><i class="bi bi-calendar-event"></i> Explore events <i class="bi bi-arrow-up-right"></i></a>
    </div>

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
                    <div id="participantEventMap" class="w-100 rounded participant-venue-map" role="img" aria-label="Event venue boundary map" style="height:220px;display:none;"></div>
                    <div id="participantMapLegend" class="participant-map-legend" style="display:none;"><span><i class="legend-venue"></i> Marked venue</span><span><i class="legend-buffer"></i> Allowed area (50m outside)</span><span><i class="legend-pin"></i> Event pin</span></div>
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


    <section class="participant-dashboard-stats mb-4" aria-label="Participation summary">
        <article class="participant-dashboard-stat">
            <span class="participant-dashboard-stat-icon tone-lavender"><i class="bi bi-calendar2-check"></i></span>
            <div><span>Registered events</span><strong><?= number_format($dashboardRegistrationCount) ?></strong><small>Events you've joined</small></div>
        </article>
        <article class="participant-dashboard-stat">
            <span class="participant-dashboard-stat-icon tone-green"><i class="bi bi-check2-circle"></i></span>
            <div><span>Attendance rate</span><strong><?= $dashboardAttendanceRate ?>%</strong><small><?= number_format($dashboardAttendedCount) ?> attended</small></div>
        </article>
        <article class="participant-dashboard-stat">
            <span class="participant-dashboard-stat-icon tone-blue"><i class="bi bi-calendar-event"></i></span>
            <div><span>Upcoming events</span><strong><?= number_format($dashboardUpcomingCount) ?></strong><small>Coming up on your calendar</small></div>
        </article>
        <a href="attendance.php" class="participant-dashboard-stat participant-dashboard-stat-link">
            <span class="participant-dashboard-stat-icon tone-amber"><i class="bi bi-clock-history"></i></span>
            <div><span>Completed attendance</span><strong><?= number_format($dashboardAttendanceHours, 1) ?><small class="stat-unit"> hrs</small></strong><small><?= number_format($dashboardAttendanceSessionCount) ?> attendance days · View history <i class="bi bi-arrow-up-right"></i></small></div>
        </a>
    </section>

    <section class="participant-dashboard-main-grid mb-4">
        <article class="participant-dashboard-panel participant-dashboard-next-event">
            <div class="participant-dashboard-panel-heading">
                <div><span class="participant-dashboard-kicker">COMING UP</span><h2>Your upcoming events</h2><p>The next moments on your calendar</p></div>
                <a href="my-events.php" class="participant-dashboard-view-link">View all <i class="bi bi-arrow-up-right"></i></a>
            </div>
            <?php if ($dashboardNextEvent && $dashboardNextEventStart): ?>
                <div class="participant-dashboard-featured-event">
                    <div class="participant-dashboard-date-tile"><span><?= htmlspecialchars($dashboardNextEventStart->format('M')) ?></span><strong><?= htmlspecialchars($dashboardNextEventStart->format('d')) ?></strong><small><?= htmlspecialchars($dashboardNextEventStart->format('D')) ?></small></div>
                    <div class="participant-dashboard-featured-copy">
                        <span class="participant-dashboard-event-label">YOUR NEXT EVENT</span>
                        <h3><?= htmlspecialchars($dashboardNextEvent['title'] ?? 'Untitled Event') ?></h3>
                        <p><?= htmlspecialchars($dashboardNextEvent['description'] ?? 'You are registered for this event.') ?></p>
                        <div class="participant-dashboard-event-details">
                            <span><i class="bi bi-clock"></i><?= htmlspecialchars($dashboardNextEventStart->format('g:i A')) ?> PHT</span>
                            <span><i class="bi bi-geo-alt"></i><?= htmlspecialchars($dashboardNextEvent['venue'] ?? 'Venue to be announced') ?></span>
                        </div>
                        <a href="my-events.php" class="participant-dashboard-text-link">View event details <i class="bi bi-arrow-right"></i></a>
                    </div>
                </div>
            <?php else: ?>
                <div class="participant-dashboard-empty"><i class="bi bi-calendar-plus"></i><strong>No upcoming registered events</strong><span>Explore available events and register for one.</span><a href="browse-events.php" class="participant-dashboard-text-link">Browse events <i class="bi bi-arrow-right"></i></a></div>
            <?php endif; ?>
        </article>

        <article class="participant-dashboard-panel participant-dashboard-progress">
            <div class="participant-dashboard-panel-heading">
                <div><span class="participant-dashboard-kicker">YOUR PARTICIPATION</span><h2>Attendance overview</h2><p>Your participation this semester</p></div>
                <span class="participant-dashboard-panel-icon"><i class="bi bi-activity"></i></span>
            </div>
            <div class="participant-dashboard-attendance-overview">
                <div class="participant-dashboard-donut" style="--attendance-rate:<?= max(0,min(100,(int)$dashboardAttendanceRate)) ?>%">
                    <div><strong><?= $dashboardAttendanceRate ?>%</strong><span>Attendance</span></div>
                </div>
                <div class="participant-dashboard-attendance-legend">
                    <div><span><i class="legend-present"></i> Attended</span><strong><?= number_format($dashboardAttendedCount) ?></strong></div>
                    <div><span><i class="legend-other"></i> Registered</span><strong><?= number_format($dashboardRegistrationCount) ?></strong></div>
                    <a href="attendance.php">View attendance history <i class="bi bi-arrow-right"></i></a>
                </div>
            </div>
            <div class="participant-dashboard-progress-footer"><span>Completed attendance time</span><strong><?= number_format($dashboardAttendanceHours, 1) ?> hrs</strong></div>
        </article>
    </section>

    <section class="participant-dashboard-lower-grid mb-4">
        <article class="participant-dashboard-panel">
            <div class="participant-dashboard-panel-heading">
                <div><span class="participant-dashboard-kicker">STAY IN THE LOOP</span><h2>Recent notifications</h2><p><?= number_format($unreadNotificationCount) ?> unread updates</p></div>
                <button type="button" class="participant-dashboard-icon-button" data-open-notifications aria-label="Open all notifications"><i class="bi bi-bell"></i></button>
            </div>
            <?php if (empty($notifications)): ?>
                <div class="participant-dashboard-empty compact"><i class="bi bi-inbox"></i><strong>You're all caught up</strong><span>New updates will appear here.</span></div>
            <?php else: ?>
                <div class="participant-dashboard-announcements">
                    <?php foreach (array_slice($notifications, 0, 3) as $announcement): ?>
                        <div class="participant-dashboard-announcement">
                            <span class="participant-dashboard-announcement-icon"><i class="bi bi-bell"></i></span>
                            <div><strong><?= htmlspecialchars($announcement['title'] ?? 'Announcement') ?></strong><p><?= htmlspecialchars($announcement['message'] ?? '') ?></p>
                            <?php if (!empty($announcement['created_at'])): ?><time><?= htmlspecialchars((new DateTime($announcement['created_at']))->setTimezone($manilaTimezone)->format('M j · g:i A')) ?></time><?php endif; ?></div>
                            <?php if (($announcement['is_read'] ?? false) !== true && ($announcement['is_read'] ?? '') !== 'true'): ?><span class="participant-dashboard-new-label">NEW</span><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="participant-dashboard-text-button" data-open-notifications>View all notifications <i class="bi bi-arrow-right"></i></button>
            <?php endif; ?>
        </article>

        <article class="participant-dashboard-panel participant-dashboard-profile-card">
            <div class="participant-dashboard-profile-top">
                <div><span class="participant-dashboard-kicker">PARTICIPANT PROFILE</span><h2>Your campus identity</h2><p>Keep your profile and face registration up to date for a smoother event experience.</p></div>
                <span class="participant-dashboard-profile-shield"><i class="bi bi-shield-check"></i></span>
            </div>
            <div class="participant-dashboard-profile-actions">
                <a href="face-registration.php"><i class="bi bi-person-bounding-box"></i> Face registration</a>
                <a href="my-events.php"><i class="bi bi-ticket-perforated"></i> My events</a>
            </div>
        </article>
    </section>

    <section class="participant-dashboard-quick-links mb-5">
        <div><span class="participant-dashboard-kicker">GET THINGS DONE</span><h2>Quick actions</h2></div>
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
    detailsModalElement.addEventListener('shown.bs.modal', function () {
        if (window.participantVenueMap) window.participantVenueMap.invalidateSize();
    });

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
        const mapLegend = document.getElementById('participantMapLegend');
        if (window.participantVenueMap) { window.participantVenueMap.remove(); window.participantVenueMap = null; }
        map.innerHTML = '';

        if (props.latitude !== null && props.latitude !== '' &&
            props.longitude !== null && props.longitude !== '') {
            const coordinates = props.latitude + ',' + props.longitude;

            map.style.display = 'block';
            window.participantVenueMap = L.map(map).setView([Number(props.latitude), Number(props.longitude)], 17);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 20, attribution: '&copy; OpenStreetMap contributors' }).addTo(window.participantVenueMap);
            L.marker([Number(props.latitude), Number(props.longitude)]).addTo(window.participantVenueMap).bindPopup('Event location');
            let boundary = props.venue_boundary;
            if (typeof boundary === 'string') { try { boundary = JSON.parse(boundary); } catch (error) { boundary = null; } }
            if (Array.isArray(boundary) && boundary.length >= 3) {
                const ring = boundary.map(p => [Number(p[1]), Number(p[0])]);
                if (ring.every(p => Number.isFinite(p[0]) && Number.isFinite(p[1]))) {
                    if (ring[0][0] !== ring[ring.length - 1][0] || ring[0][1] !== ring[ring.length - 1][1]) ring.push(ring[0]);
                    const feature = turf.polygon([ring]);
                    const allowedArea = turf.buffer(feature, 0.05, { units: 'kilometers' });
                    L.geoJSON(allowedArea, { style: { color: '#a78bfa', weight: 2, dashArray: '7 5', fillColor: '#8b5cf6', fillOpacity: .13 } }).addTo(window.participantVenueMap);
                    L.polygon(boundary, { color: '#4ade80', weight: 3, fillColor: '#22c55e', fillOpacity: .22 }).addTo(window.participantVenueMap).bindPopup('Organizer-marked venue');
                    window.participantVenueMap.fitBounds(L.geoJSON(allowedArea).getBounds(), { padding: [20, 20] });
                }
            }
            mapLegend.style.display = 'flex';

            mapLink.href = 'https://www.google.com/maps?q=' +
                encodeURIComponent(coordinates);
            mapLink.style.display = 'inline-block';
        } else {
            map.innerHTML = '';
            map.style.display = 'none';
            mapLegend.style.display = 'none';
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
<script src="../js/weather-widget.js?v=20261115-10" defer></script>

</body>
</html>
