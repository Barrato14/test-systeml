<?php
date_default_timezone_set('Asia/Manila');

require_once '../config/database.php';
require_once '../includes/auth.php';

// Only Event Organizers may access this dashboard.
// auth.php already starts the session.
if (
    !isset($_SESSION['user_id']) ||
    strtolower(trim($_SESSION['user_role'] ?? '')) !== 'event organizer'
) {
    header("Location: ../login.php");
    exit;
}

$organizerId = (string)$_SESSION['user_id'];

$manilaTimezone = new DateTimeZone('Asia/Manila');
$now = new DateTimeImmutable('now', $manilaTimezone);

/* =========================================================
   FETCH EVENTS BELONGING TO THIS ORGANIZER ONLY
========================================================= */

$eventsResponse = supabaseRequest(
    'events?select=id,title,start_datetime,end_datetime,venue,organizer_id'
    . '&organizer_id=eq.' . rawurlencode($organizerId)
    . '&order=start_datetime.asc',
    'GET'
);

$myEvents = [];
$ongoingEvents = [];
$ongoingEventMap = [];

$activeEvents = 0;
$upcomingEvents = 0;
$completedEvents = 0;

if (is_array($eventsResponse['data'] ?? null)) {

    foreach ($eventsResponse['data'] as $event) {

        if (
            empty($event['id']) ||
            empty($event['start_datetime']) ||
            empty($event['end_datetime'])
        ) {
            continue;
        }

        try {

            $eventStart = (new DateTimeImmutable($event['start_datetime']))
                ->setTimezone($manilaTimezone);

            $eventEnd = (new DateTimeImmutable($event['end_datetime']))
                ->setTimezone($manilaTimezone);

            if ($eventEnd < $eventStart) {
                continue;
            }

            $eventId = (string)$event['id'];

            $myEvents[] = $event;

            // Active means upcoming or currently ongoing.
            if ($eventEnd >= $now) {
                $activeEvents++;
            } else {
                $completedEvents++;
            }

            if ($eventStart > $now) {
                $upcomingEvents++;
            }

            // Only currently ongoing events appear in Recent Activity.
            if ($eventStart <= $now && $eventEnd >= $now) {

                $ongoingEvents[] = $event;
                $ongoingEventMap[$eventId] = $event;

            }

        } catch (Exception $e) {
            continue;
        }
    }
}

$totalMyEvents = count($myEvents);

/* =========================================================
   FETCH TOTAL ATTENDANCE FOR THIS ORGANIZER'S EVENTS
========================================================= */

$totalCheckIns = 0;

$allOrganizerEventIds = array_values(
    array_unique(
        array_map(
            function ($event) {
                return (string)$event['id'];
            },
            $myEvents
        )
    )
);

if (!empty($allOrganizerEventIds)) {

    $encodedEventIds = array_map(
        'rawurlencode',
        $allOrganizerEventIds
    );

    $eventFilter = 'event_id=in.(' . implode(',', $encodedEventIds) . ')';

    $totalAttendanceResponse = supabaseRequest(
        'attendance?select=id&' . $eventFilter,
        'GET'
    );

    if (is_array($totalAttendanceResponse['data'] ?? null)) {
        $totalCheckIns = count($totalAttendanceResponse['data']);
    }
}

/* =========================================================
   FETCH RECENT ATTENDANCE FOR ONGOING EVENTS ONLY
========================================================= */

$attendanceResponse = ['data' => []];

if (!empty($ongoingEventMap)) {

    $eventIds = array_map(
        'rawurlencode',
        array_keys($ongoingEventMap)
    );

    $eventFilter = 'event_id=in.(' . implode(',', $eventIds) . ')';

    $attendanceResponse = supabaseRequest(
        'attendance?select=id,participant_id,event_id,check_in_time,time_out&'
        . $eventFilter
        . '&order=check_in_time.desc&limit=50',
        'GET'
    );
}

/* =========================================================
   FETCH PARTICIPANT PROFILES
========================================================= */

$profilesMap = [];

$participantIds = [];

if (is_array($attendanceResponse['data'] ?? null)) {

    foreach ($attendanceResponse['data'] as $attendance) {

        $participantId = (string)($attendance['participant_id'] ?? '');

        if ($participantId !== '') {
            $participantIds[$participantId] = true;
        }

    }

}

if (!empty($participantIds)) {

    $encodedParticipantIds = array_map(
        'rawurlencode',
        array_keys($participantIds)
    );

    $profilesResponse = supabaseRequest(
        'profiles?select=id,first_name,last_name,course,section'
        . '&id=in.(' . implode(',', $encodedParticipantIds) . ')',
        'GET'
    );

    if (is_array($profilesResponse['data'] ?? null)) {

        foreach ($profilesResponse['data'] as $profile) {

            $profilesMap[(string)($profile['id'] ?? '')] = $profile;

        }

    }
}

/* =========================================================
   PREPARE RECENT ATTENDANCE
========================================================= */

$recentAttendance = [];

if (is_array($attendanceResponse['data'] ?? null)) {

    foreach ($attendanceResponse['data'] as $attendance) {

        $eventId = (string)($attendance['event_id'] ?? '');

        // Only include attendance belonging to ongoing events
        // owned by the currently logged-in organizer.
        if (
            $eventId === '' ||
            !isset($ongoingEventMap[$eventId])
        ) {
            continue;
        }

        $participantId = (string)($attendance['participant_id'] ?? '');

        $profile = $profilesMap[$participantId] ?? [];

        $attendance['participant_name'] = trim(
            (string)($profile['first_name'] ?? '') . ' ' .
            (string)($profile['last_name'] ?? '')
        ) ?: 'Participant';

        $attendance['participant_course'] = $profile['course'] ?? '';
        $attendance['participant_section'] = $profile['section'] ?? '';

        $attendance['event_title'] =
            $ongoingEventMap[$eventId]['title'] ?? 'Ongoing event';

        $recentAttendance[] = $attendance;

        if (count($recentAttendance) >= 8) {
            break;
        }
    }
}

/* =========================================================
   EVENT FILTER FOR RECENT ATTENDANCE
========================================================= */

$selectedEventId = trim((string)($_GET['event_id'] ?? ''));

$selectedEventTitle = '';

if (
    $selectedEventId !== '' &&
    isset($ongoingEventMap[$selectedEventId])
) {

    $selectedEventTitle = (string)(
        $ongoingEventMap[$selectedEventId]['title'] ?? 'Selected event'
    );

    $recentAttendance = array_values(
        array_filter(
            $recentAttendance,
            function ($record) use ($selectedEventId) {

                return (string)($record['event_id'] ?? '') === $selectedEventId;

            }
        )
    );

} else {

    $selectedEventId = '';

}

/* =========================================================
   FORMAT ACTIVITY TIME
========================================================= */

function formatOrganizerActivityTime($value)
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

    <div class="d-flex justify-content-between align-items-center mb-4">

        <h2 class="mb-0">Dashboard Overview</h2>

        <div class="d-flex align-items-center">

            <span class="me-3">

                Welcome,

                <strong>
                    <?= htmlspecialchars($_SESSION['user_name'] ?? 'Organizer') ?>
                </strong>

            </span>

        </div>

    </div>

    <?php include __DIR__ . '/../includes/weather-widget.php'; ?>

    <!-- METRIC CARDS -->

    <div class="row mb-4">

        <!-- MY EVENTS -->

        <div class="col-md-3">

            <div class="card stat-card shadow-sm mb-3">

                <div class="card-body">

                    <h6 class="text-muted text-uppercase mb-2">
                        My Events
                    </h6>

                    <h2 class="mb-0">
                        <?= $totalMyEvents ?>
                    </h2>

                </div>

            </div>

        </div>

        <!-- ACTIVE EVENTS -->

        <div class="col-md-3">

            <div
                class="card stat-card shadow-sm mb-3"
                style="border-left-color: #198754;"
            >

                <div class="card-body">

                    <h6 class="text-muted text-uppercase mb-2">
                        Active Events
                    </h6>

                    <h2 class="mb-0">
                        <?= $activeEvents ?>
                    </h2>

                </div>

            </div>

        </div>

        <!-- UPCOMING EVENTS -->

        <div class="col-md-3">

            <div
                class="card stat-card shadow-sm mb-3"
                style="border-left-color: #ffc107;"
            >

                <div class="card-body">

                    <h6 class="text-muted text-uppercase mb-2">
                        Upcoming Events
                    </h6>

                    <h2 class="mb-0">
                        <?= $upcomingEvents ?>
                    </h2>

                </div>

            </div>

        </div>

        <!-- TOTAL CHECK-INS -->

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
                            Activity from your events that are currently ongoing.
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
                            id="ongoingTab"
                            role="tab"
                            aria-selected="true"
                            aria-controls="ongoingPanel"
                            data-activity-tab="ongoing"
                        >

                            <i class="bi bi-calendar2-check"></i>

                            My Ongoing Events

                            <span><?= count($ongoingEvents) ?></span>

                        </button>

                        <button
                            type="button"
                            class="admin-activity-tab"
                            id="attendanceTab"
                            role="tab"
                            aria-selected="false"
                            aria-controls="attendancePanel"
                            data-activity-tab="attendance"
                        >

                            <i class="bi bi-person-check"></i>

                            Attendance

                            <span><?= count($recentAttendance) ?></span>

                        </button>

                    </div>

                </div>

                <div class="card-body admin-activity-body">

                    <!-- ONGOING EVENTS PANEL -->

                    <div
                        class="admin-activity-panel is-active"
                        id="ongoingPanel"
                        role="tabpanel"
                        aria-labelledby="ongoingTab"
                        data-activity-panel="ongoing"
                    >

                        <?php if (empty($ongoingEvents)): ?>

                            <div class="admin-activity-empty">

                                <i class="bi bi-calendar2-x"></i>

                                <strong>
                                    None of your events are ongoing right now
                                </strong>

                                <span>
                                    Only your currently ongoing events appear here.
                                </span>

                            </div>

                        <?php else: ?>

                            <div class="admin-activity-list">

                                <?php foreach (array_slice($ongoingEvents, 0, 8) as $event): ?>

                                    <article
                                        class="admin-activity-item"
                                        data-event-id="<?= htmlspecialchars((string)($event['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                        role="button"
                                        tabindex="0"
                                        style="cursor:pointer"
                                    >

                                        <span class="admin-activity-icon event">

                                            <i class="bi bi-calendar-event"></i>

                                        </span>

                                        <div class="admin-activity-copy">

                                            <strong>
                                                <?= htmlspecialchars($event['title'] ?? 'Untitled event') ?>
                                            </strong>

                                            <span>
                                                <?= htmlspecialchars($event['venue'] ?? 'Venue not specified') ?>
                                            </span>

                                            <small>

                                                Started

                                                <?= htmlspecialchars(
                                                    formatOrganizerActivityTime(
                                                        $event['start_datetime'] ?? ''
                                                    )
                                                ) ?>

                                            </small>

                                        </div>

                                        <span class="admin-live-label">

                                            <span></span>

                                            Ongoing

                                        </span>

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
                        id="attendancePanel"
                        role="tabpanel"
                        aria-labelledby="attendanceTab"
                        data-activity-panel="attendance"
                        hidden
                    >

                        <?php if (empty($recentAttendance)): ?>

                            <div class="admin-activity-empty">

                                <i class="bi bi-person-check"></i>

                                <strong>

                                    <?= $selectedEventId !== ''
                                        ? 'Attendance for ' . htmlspecialchars($selectedEventTitle)
                                        : 'No recent attendance yet'
                                    ?>

                                </strong>

                                <span>

                                    <?= $selectedEventId !== ''
                                        ? 'No attendance records were found for this event.'
                                        : 'Check-ins for your currently ongoing events will appear here.'
                                    ?>

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
                                                <?= htmlspecialchars($record['participant_name']) ?>
                                            </strong>

                                            <span>

                                                Checked in to

                                                <?= htmlspecialchars($record['event_title']) ?>

                                            </span>

                                            <small>

                                                <?= htmlspecialchars(
                                                    formatOrganizerActivityTime(
                                                        $record['check_in_time'] ?? ''
                                                    )
                                                ) ?>

                                                <?php if (
                                                    !empty($record['participant_course']) ||
                                                    !empty($record['participant_section'])
                                                ): ?>

                                                    ·

                                                    <?= htmlspecialchars(
                                                        trim(
                                                            ($record['participant_course'] ?? '') . ' ' .
                                                            ($record['participant_section'] ?? '')
                                                        )
                                                    ) ?>

                                                <?php endif; ?>

                                            </small>

                                        </div>

                                        <span class="admin-attendance-label">

                                            <?= !empty($record['time_out'])
                                                ? 'Completed'
                                                : 'Present'
                                            ?>

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
                            href="events.php?action=new"
                            class="btn btn-outline-primary text-start"
                        >

                            <i class="bi bi-plus-circle me-2"></i>

                            Create New Event

                        </a>

                        <a
                            href="events.php"
                            class="btn btn-outline-success text-start"
                        >

                            <i class="bi bi-calendar-event me-2"></i>

                            Manage My Events

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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function () {

    const tabs = Array.from(
        document.querySelectorAll('[data-activity-tab]')
    );

    const panels = Array.from(
        document.querySelectorAll('[data-activity-panel]')
    );

    function showActivity(selected) {

        tabs.forEach(function (tab) {

            const active = tab.dataset.activityTab === selected;

            tab.classList.toggle('is-active', active);

            tab.setAttribute(
                'aria-selected',
                active ? 'true' : 'false'
            );

        });

        panels.forEach(function (panel) {

            const active = panel.dataset.activityPanel === selected;

            panel.classList.toggle('is-active', active);

            panel.hidden = !active;

        });

    }

    tabs.forEach(function (tab) {

        tab.addEventListener('click', function () {

            // Leaving an event-specific attendance view clears its filter.
            if (tab.dataset.activityTab === 'ongoing') {

                const url = new URL(window.location.href);

                if (url.searchParams.has('event_id')) {

                    url.searchParams.delete('event_id');
                    url.hash = '';

                    window.location.href = url.toString();

                    return;

                }

            }

            showActivity(tab.dataset.activityTab);

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

                showActivity(tabs[next].dataset.activityTab);

            }

        }, { passive: true });

    }

})();
</script>

<script>
(function () {

    const eventItems = document.querySelectorAll(
        '#ongoingPanel .admin-activity-item[data-event-id]'
    );

    function openEventAttendance(eventItem) {

        const eventId = eventItem.dataset.eventId;

        if (!eventId) return;

        const url = new URL(window.location.href);

        url.searchParams.set('event_id', eventId);

        url.hash = 'attendancePanel';

        window.location.href = url.toString();

    }

    eventItems.forEach(function (eventItem) {

        eventItem.addEventListener('click', function () {

            openEventAttendance(eventItem);

        });

        eventItem.addEventListener('keydown', function (event) {

            if (event.key === 'Enter' || event.key === ' ') {

                event.preventDefault();

                openEventAttendance(eventItem);

            }

        });

    });

    // Open the Attendance tab when an event ID is selected.
    const selectedEventId = new URLSearchParams(
        window.location.search
    ).get('event_id');

    if (selectedEventId) {

        const attendanceTab = document.querySelector('#attendanceTab');

        if (attendanceTab) {
            attendanceTab.click();
        }

    }

})();
</script>

</body>
</html>
