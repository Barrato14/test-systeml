<?php
date_default_timezone_set('Asia/Manila');

require_once '../config/database.php';
require_once '../includes/auth.php';

// Only Event Organizers may access this dashboard.
// auth.php starts the session, so do not call session_start() again.
requireRole(['Event Organizer']);

$organizerId = (string)$_SESSION['user_id'];

$manilaTimezone = new DateTimeZone('Asia/Manila');
$now = new DateTimeImmutable('now', $manilaTimezone);

/* =========================================================
   HELPER FUNCTIONS
========================================================= */

function organizerDashboardTime($value)
{
    if (empty($value)) {
        return 'Time unavailable';
    }

    try {

        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone('Asia/Manila'))
            ->format('M j, Y · g:i A');

    } catch (Exception $e) {

        return 'Invalid date';

    }
}

function organizerDashboardEscape($value)
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

/* =========================================================
   FETCH ORGANIZER'S EVENTS ONLY
========================================================= */

$eventsResponse = supabaseRequest(
    'events?select=id,title,description,venue,start_datetime,end_datetime,capacity,status,organizer_id,event_registrations(status)'
    . '&organizer_id=eq.' . rawurlencode($organizerId)
    . '&order=start_datetime.asc',
    'GET'
);

$events = [];
$eventMap = [];

$totalEvents = 0;
$upcomingEventsCount = 0;
$ongoingEventsCount = 0;
$completedEventsCount = 0;
$totalApprovedRegistrations = 0;

if (
    is_array($eventsResponse['data'] ?? null)
) {

    foreach ($eventsResponse['data'] as $event) {

        $eventId = (string)($event['id'] ?? '');

        if ($eventId === '') {
            continue;
        }

        $event['current_registrations'] = 0;

        // Count approved registrations.
        if (is_array($event['event_registrations'] ?? null)) {

            foreach ($event['event_registrations'] as $registration) {

                if (($registration['status'] ?? '') === 'Approved') {

                    $event['current_registrations']++;
                    $totalApprovedRegistrations++;

                }

            }

        }

        // Calculate event status using Manila time.
        $calculatedStatus = 'Unknown';

        try {

            if (
                !empty($event['start_datetime']) &&
                !empty($event['end_datetime'])
            ) {

                $eventStart = new DateTimeImmutable(
                    $event['start_datetime']
                );

                $eventEnd = new DateTimeImmutable(
                    $event['end_datetime']
                );

                $eventStart = $eventStart->setTimezone($manilaTimezone);
                $eventEnd = $eventEnd->setTimezone($manilaTimezone);

                if ($eventEnd < $eventStart) {

                    $calculatedStatus = 'Invalid Schedule';

                } elseif ($now < $eventStart) {

                    $calculatedStatus = 'Upcoming';
                    $upcomingEventsCount++;

                } elseif ($now <= $eventEnd) {

                    $calculatedStatus = 'Ongoing';
                    $ongoingEventsCount++;

                } else {

                    $calculatedStatus = 'Completed';
                    $completedEventsCount++;

                }

            }

        } catch (Exception $e) {

            $calculatedStatus = 'Invalid Schedule';

        }

        $event['calculated_status'] = $calculatedStatus;

        $events[] = $event;
        $eventMap[$eventId] = $event;

    }

}

$totalEvents = count($events);

/* =========================================================
   FETCH ATTENDANCE FOR ORGANIZER'S EVENTS ONLY
========================================================= */

$recentAttendance = [];
$totalCheckIns = 0;

if (!empty($eventMap)) {

    $eventIds = array_map(
        'rawurlencode',
        array_keys($eventMap)
    );

    $eventFilter = 'event_id=in.(' . implode(',', $eventIds) . ')';

    // Fetch attendance records for the organizer's events.
    $attendanceResponse = supabaseRequest(
        'attendance?select=id,participant_id,event_id,check_in_time,time_out,status'
        . '&' . $eventFilter
        . '&order=check_in_time.desc'
        . '&limit=1000',
        'GET'
    );

    $attendanceRecords = [];

    if (is_array($attendanceResponse['data'] ?? null)) {

        $attendanceRecords = $attendanceResponse['data'];

    }

    $totalCheckIns = count($attendanceRecords);

    // Collect participant IDs for the attendance activity panel.
    $participantIds = [];

    foreach (array_slice($attendanceRecords, 0, 8) as $record) {

        $participantId = (string)($record['participant_id'] ?? '');

        if ($participantId !== '') {
            $participantIds[$participantId] = true;
        }

    }

    // Fetch profiles only when there are attendance records.
    $profilesMap = [];

    if (!empty($participantIds)) {

        $profileIds = array_map(
            'rawurlencode',
            array_keys($participantIds)
        );

        $profileResponse = supabaseRequest(
            'profiles?select=id,first_name,last_name,course,section'
            . '&id=in.(' . implode(',', $profileIds) . ')',
            'GET'
        );

        if (is_array($profileResponse['data'] ?? null)) {

            foreach ($profileResponse['data'] as $profile) {

                $profilesMap[(string)$profile['id']] = $profile;

            }

        }

    }

    // Prepare the latest eight attendance records.
    foreach (array_slice($attendanceRecords, 0, 8) as $record) {

        $participantId = (string)($record['participant_id'] ?? '');
        $eventId = (string)($record['event_id'] ?? '');

        $profile = $profilesMap[$participantId] ?? [];
        $event = $eventMap[$eventId] ?? [];

        $record['participant_name'] = trim(
            (string)($profile['first_name'] ?? '') . ' ' .
            (string)($profile['last_name'] ?? '')
        ) ?: 'Participant';

        $record['participant_course'] = $profile['course'] ?? '';
        $record['participant_section'] = $profile['section'] ?? '';

        $record['event_title'] = $event['title'] ?? 'Event';

        $recentAttendance[] = $record;

    }

}

/* =========================================================
   RECENT ACTIVITY EVENT LIST
   Show ongoing events first, then upcoming events.
========================================================= */

$activityEvents = array_values(
    array_filter(
        $events,
        function ($event) {
            return in_array(
                $event['calculated_status'] ?? '',
                ['Ongoing', 'Upcoming'],
                true
            );
        }
    )
);

// Sort ongoing events before upcoming events.
usort($activityEvents, function ($a, $b) {

    $priority = [
        'Ongoing' => 0,
        'Upcoming' => 1
    ];

    $aPriority = $priority[$a['calculated_status']] ?? 2;
    $bPriority = $priority[$b['calculated_status']] ?? 2;

    if ($aPriority !== $bPriority) {
        return $aPriority <=> $bPriority;
    }

    return strcmp(
        $a['start_datetime'] ?? '',
        $b['start_datetime'] ?? ''
    );

});

$activityEvents = array_slice($activityEvents, 0, 8);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Organizer Dashboard - EMIS</title>

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
    >

    <link rel="stylesheet" href="../css/portal-theme.css">

    <link rel="stylesheet" href="../css/weather-widget.css">

</head>

<body class="admin-portal">

<?php require_once __DIR__ . '/../includes/organizer-nav.php'; ?>

<main class="content-area admin-main-content">

    <!-- DASHBOARD HEADER -->

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">

        <div>
            <h2 class="mb-0">Dashboard Overview</h2>

            <p class="text-muted mb-0 mt-2">
                Monitor your events and participant attendance.
            </p>
        </div>

        <div class="d-flex align-items-center">

            <span>
                Welcome,
                <strong>
                    <?= organizerDashboardEscape($_SESSION['user_name'] ?? 'Organizer') ?>
                </strong>
            </span>

        </div>

    </div>

    <!-- WEATHER WIDGET -->

    <?php include __DIR__ . '/../includes/weather-widget.php'; ?>

    <!-- METRIC CARDS -->

    <div class="row mb-4">

        <div class="col-md-3">

            <div class="card stat-card shadow-sm mb-3">

                <div class="card-body">

                    <h6 class="text-muted text-uppercase mb-2">
                        My Events
                    </h6>

                    <h2 class="mb-0">
                        <?= $totalEvents ?>
                    </h2>

                </div>

            </div>

        </div>

        <div class="col-md-3">

            <div
                class="card stat-card shadow-sm mb-3"
                style="border-left-color: #4f46e5;"
            >

                <div class="card-body">

                    <h6 class="text-muted text-uppercase mb-2">
                        Upcoming Events
                    </h6>

                    <h2 class="mb-0">
                        <?= $upcomingEventsCount ?>
                    </h2>

                </div>

            </div>

        </div>

        <div class="col-md-3">

            <div
                class="card stat-card shadow-sm mb-3"
                style="border-left-color: #198754;"
            >

                <div class="card-body">

                    <h6 class="text-muted text-uppercase mb-2">
                        Ongoing Events
                    </h6>

                    <h2 class="mb-0">
                        <?= $ongoingEventsCount ?>
                    </h2>

                </div>

            </div>

        </div>

        <div class="col-md-3">

            <div
                class="card stat-card shadow-sm mb-3"
                style="border-left-color: #dc3545;"
            >

                <div class="card-body">

                    <h6 class="text-muted text-uppercase mb-2">
                        Total Check-Ins
                    </h6>

                    <h2 class="mb-0">
                        <?= $totalCheckIns ?>
                    </h2>

                </div>

            </div>

        </div>

    </div>

    <!-- ADDITIONAL REGISTRATION SUMMARY -->

    <div class="row mb-4">

        <div class="col-md-6">

            <div
                class="card stat-card shadow-sm mb-3"
                style="border-left-color: #ffc107;"
            >

                <div class="card-body">

                    <h6 class="text-muted text-uppercase mb-2">
                        Approved Registrations
                    </h6>

                    <h2 class="mb-0">
                        <?= $totalApprovedRegistrations ?>
                    </h2>

                </div>

            </div>

        </div>

        <div class="col-md-6">

            <div
                class="card stat-card shadow-sm mb-3"
                style="border-left-color: #64748b;"
            >

                <div class="card-body">

                    <h6 class="text-muted text-uppercase mb-2">
                        Completed Events
                    </h6>

                    <h2 class="mb-0">
                        <?= $completedEventsCount ?>
                    </h2>

                </div>

            </div>

        </div>

    </div>

    <!-- RECENT ACTIVITY AND QUICK ACTIONS -->

    <div class="row g-4">

        <div class="col-lg-8">

            <section class="card shadow-sm admin-activity-card">

                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">

                    <div>

                        <h5 class="mb-1">
                            Recent Activity
                        </h5>

                        <p class="small text-muted mb-0">
                            Activity from your events and their attendance records.
                        </p>

                    </div>

                    <div
                        class="admin-activity-tabs"
                        role="tablist"
                        aria-label="Recent activity categories"
                    >

                        <button
                            type="button"
                            class="admin-activity-tab is-active"
                            id="organizerEventsTab"
                            role="tab"
                            aria-selected="true"
                            aria-controls="organizerEventsPanel"
                            data-organizer-tab="events"
                        >

                            <i class="bi bi-calendar2-check"></i>

                            My Events

                            <span><?= count($activityEvents) ?></span>

                        </button>

                        <button
                            type="button"
                            class="admin-activity-tab"
                            id="organizerAttendanceTab"
                            role="tab"
                            aria-selected="false"
                            aria-controls="organizerAttendancePanel"
                            data-organizer-tab="attendance"
                        >

                            <i class="bi bi-person-check"></i>

                            Attendance

                            <span><?= count($recentAttendance) ?></span>

                        </button>

                    </div>

                </div>

                <div class="card-body admin-activity-body">

                    <!-- EVENTS PANEL -->

                    <div
                        class="admin-activity-panel is-active"
                        id="organizerEventsPanel"
                        role="tabpanel"
                        aria-labelledby="organizerEventsTab"
                        data-organizer-panel="events"
                    >

                        <?php if (empty($activityEvents)): ?>

                            <div class="admin-activity-empty">

                                <i class="bi bi-calendar2-x"></i>

                                <strong>
                                    No upcoming or ongoing events
                                </strong>

                                <span>
                                    Events you organize will appear here.
                                </span>

                            </div>

                        <?php else: ?>

                            <div class="admin-activity-list">

                                <?php foreach ($activityEvents as $event): ?>

                                    <?php
                                    $eventStatus = $event['calculated_status'] ?? 'Unknown';
                                    $isOngoing = $eventStatus === 'Ongoing';
                                    ?>

                                    <article class="admin-activity-item">

                                        <span class="admin-activity-icon event">

                                            <i class="bi bi-calendar-event"></i>

                                        </span>

                                        <div class="admin-activity-copy">

                                            <strong>
                                                <?= organizerDashboardEscape($event['title'] ?? 'Untitled event') ?>
                                            </strong>

                                            <span>
                                                <?= organizerDashboardEscape($event['venue'] ?? 'Venue not specified') ?>
                                            </span>

                                            <small>

                                                <?= $isOngoing ? 'Started ' : 'Starts ' ?>

                                                <?= organizerDashboardEscape(
                                                    organizerDashboardTime(
                                                        $event['start_datetime'] ?? ''
                                                    )
                                                ) ?>

                                            </small>

                                        </div>

                                        <?php if ($isOngoing): ?>

                                            <span class="admin-live-label">
                                                <span></span>
                                                Ongoing
                                            </span>

                                        <?php else: ?>

                                            <span class="admin-attendance-label">
                                                Upcoming
                                            </span>

                                        <?php endif; ?>

                                    </article>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>

                        <div class="admin-activity-footer">

                            <a href="events.php">
                                Manage my events
                                <i class="bi bi-arrow-up-right"></i>
                            </a>

                        </div>

                    </div>

                    <!-- ATTENDANCE PANEL -->

                    <div
                        class="admin-activity-panel"
                        id="organizerAttendancePanel"
                        role="tabpanel"
                        aria-labelledby="organizerAttendanceTab"
                        data-organizer-panel="attendance"
                        hidden
                    >

                        <?php if (empty($recentAttendance)): ?>

                            <div class="admin-activity-empty">

                                <i class="bi bi-person-check"></i>

                                <strong>
                                    No recent attendance yet
                                </strong>

                                <span>
                                    Check-ins for your events will appear here.
                                </span>

                            </div>

                        <?php else: ?>

                            <div class="admin-activity-list">

                                <?php foreach ($recentAttendance as $record): ?>

                                    <article class="admin-activity-item">

                                        <span class="admin-activity-icon attendance">

                                            <i class="bi bi-person-check"></i>

                                        </span>

                                        <div class="admin-activity-copy">

                                            <strong>
                                                <?= organizerDashboardEscape($record['participant_name']) ?>
                                            </strong>

                                            <span>

                                                Checked in to
                                                <?= organizerDashboardEscape($record['event_title']) ?>

                                            </span>

                                            <small>

                                                <?= organizerDashboardEscape(
                                                    organizerDashboardTime(
                                                        $record['check_in_time'] ?? ''
                                                    )
                                                ) ?>

                                                <?php
                                                $courseSection = trim(
                                                    ($record['participant_course'] ?? '') . ' ' .
                                                    ($record['participant_section'] ?? '')
                                                );
                                                ?>

                                                <?php if ($courseSection !== ''): ?>

                                                    · <?= organizerDashboardEscape($courseSection) ?>

                                                <?php endif; ?>

                                            </small>

                                        </div>

                                        <span class="admin-attendance-label">

                                            <?= !empty($record['time_out']) ? 'Completed' : 'Present' ?>

                                        </span>

                                    </article>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>

                        <div class="admin-activity-footer">

                            <a href="attendance-logs.php">
                                View attendance logs
                                <i class="bi bi-arrow-up-right"></i>
                            </a>

                        </div>

                    </div>

                </div>

            </section>

        </div>

        <!-- QUICK ACTIONS -->

        <div class="col-lg-4">

            <div class="card shadow-sm h-100">

                <div class="card-header">

                    <h5 class="mb-0">
                        Quick Actions
                    </h5>

                </div>

                <div class="card-body">

                    <div class="d-grid gap-3">

                        <a
                            href="events.php"
                            class="btn btn-outline-primary text-start"
                        >

                            <i class="bi bi-calendar-plus me-2"></i>

                            Manage My Events

                        </a>

                        <a
                            href="events.php"
                            class="btn btn-outline-success text-start"
                        >

                            <i class="bi bi-plus-circle me-2"></i>

                            Create New Event

                        </a>

                        <a
                            href="attendance-logs.php"
                            class="btn btn-outline-warning text-start"
                        >

                            <i class="bi bi-person-bounding-box me-2"></i>

                            View Attendance Logs

                        </a>

                    </div>

                </div>

            </div>

        </div>

    </div>

</main>

<script src="../js/weather-widget.js" defer></script>

<script>
(function () {

    const tabs = Array.from(
        document.querySelectorAll('[data-organizer-tab]')
    );

    const panels = Array.from(
        document.querySelectorAll('[data-organizer-panel]')
    );

    function showActivity(selected) {

        tabs.forEach(function (tab) {

            const active = tab.dataset.organizerTab === selected;

            tab.classList.toggle('is-active', active);

            tab.setAttribute(
                'aria-selected',
                active ? 'true' : 'false'
            );

        });

        panels.forEach(function (panel) {

            const active = panel.dataset.organizerPanel === selected;

            panel.classList.toggle('is-active', active);

            panel.hidden = !active;

        });

    }

    tabs.forEach(function (tab) {

        tab.addEventListener('click', function () {

            showActivity(tab.dataset.organizerTab);

        });

    });

    const activityCard = document.querySelector('.admin-activity-card');

    let touchStartX = 0;
    let touchStartY = 0;

    if (activityCard) {

        activityCard.addEventListener('touchstart', function (event) {

            if (!event.changedTouches.length) return;

            touchStartX = event.changedTouches[0].clientX;
            touchStartY = event.changedTouches[0].clientY;

        }, { passive: true });

        activityCard.addEventListener('touchend', function (event) {

            if (!event.changedTouches.length) return;

            const deltaX = event.changedTouches[0].clientX - touchStartX;
            const deltaY = event.changedTouches[0].clientY - touchStartY;

            if (
                Math.abs(deltaX) < 55 ||
                Math.abs(deltaX) < Math.abs(deltaY)
            ) {
                return;
            }

            const current = tabs.findIndex(function (tab) {
                return tab.classList.contains('is-active');
            });

            const next = deltaX < 0 ? current + 1 : current - 1;

            if (next >= 0 && next < tabs.length) {

                showActivity(tabs[next].dataset.organizerTab);

            }

        }, { passive: true });

    }

})();
</script>

</body>
</html>
