<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once '../config/database.php';
require_once '../includes/auth.php';

// Ensure user is logged in and is an Administrator
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role'] ?? '') !== 'administrator') {
    header("Location: ../login.php");
    exit;
}

// Fetch total users
$totalUsersResponse = supabaseRequest('profiles?select=id', 'GET');
$totalUsers = (
    is_array($totalUsersResponse) &&
    isset($totalUsersResponse['data']) &&
    is_array($totalUsersResponse['data'])
) ? count($totalUsersResponse['data']) : 0;

// Load currently ongoing events for the dashboard activity panel.
$manilaTimezone = new DateTimeZone('Asia/Manila');$now = new DateTimeImmutable('now', $manilaTimezone);$eventsResponse = supabaseRequest('events?select=id,title,start_datetime,end_datetime,venue&order=start_datetime.asc', 'GET');
$ongoingEvents = [];
$ongoingEventMap = [];$activeEvents = 0;
if (is_array($eventsResponse['data'] ?? null)) {
    foreach ($eventsResponse['data'] as$event) {
        if (empty($event['start_datetime']) || empty($event['end_datetime'])) continue;
        try {
            $eventStart = (new DateTimeImmutable($event['start_datetime']))->setTimezone($manilaTimezone);$eventEnd = (new DateTimeImmutable($event['end_datetime']))->setTimezone($manilaTimezone);

            // Keep the dashboard metric counting upcoming and ongoing events, but only show ongoing events in Recent Activity.
            if ($eventEnd >= $now)$activeEvents++;

            if ($eventStart <=$now && $eventEnd >=$now) {
                $ongoingEvents[] =$event;
                $ongoingEventMap[(string)$event['id']] =$event;
            }
        } catch (Exception $e) { continue; }
    }
}

// Fetch attendance for the currently ongoing event IDs only.
$attendanceResponse = ['data' => []];
if (!empty($ongoingEventMap)) {$eventIds = array_map('rawurlencode', array_keys($ongoingEventMap));$eventFilter = 'event_id=in.(' . implode(',', $eventIds) . ')';$attendanceResponse = supabaseRequest(
        'attendance?select=id,participant_id,event_id,check_in_time,time_out&' . $eventFilter . '&order=check_in_time.desc&limit=50',
        'GET'
    );
}
$profilesResponse = supabaseRequest('profiles?select=id,first_name,last_name,course,section', 'GET');
$profilesMap = [];
if (is_array($profilesResponse['data'] ?? null)) {
    foreach ($profilesResponse['data'] as $profile) {$profilesMap[(string)($profile['id'] ?? '')] =$profile;
    }
}
$recentAttendance = [];
if (is_array($attendanceResponse['data'] ?? null)) {
    foreach ($attendanceResponse['data'] as$attendance) {
        $eventId = (string)($attendance['event_id'] ?? '');
        if ($eventId === '' || !isset($ongoingEventMap[$eventId])) continue;
        $participantId = (string)($attendance['participant_id'] ?? '');$profile = $profilesMap[$participantId] ?? [];
        $attendance['participant_name'] = trim((string)($profile['first_name'] ?? '') . ' ' . (string)($profile['last_name'] ?? '')) ?: 'Participant';$attendance['participant_course'] = $profile['course'] ?? '';$attendance['participant_section'] = $profile['section'] ?? '';$attendance['event_title'] = $ongoingEventMap[$eventId]['title'] ?? 'Ongoing event';
        $recentAttendance[] =$attendance;
        if (count($recentAttendance) >= 8) break;
    }
}
function formatDashboardActivityTime($value) {
    if (empty($value)) return 'Time unavailable';
    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y · g:i A');
    } catch (Exception $e) { return 'Invalid date'; }
}
$totalAttendanceResponse = supabaseRequest('attendance?select=id', 'GET');
$totalCheckIns = is_array($totalAttendanceResponse['data'] ?? null) ? count($totalAttendanceResponse['data']) : 0;
$pendingEquipment = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - EMIS</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    
    <link rel="stylesheet" href="../css/portal-theme.css">
</head>
<body class="admin-portal">
<?php require_once __DIR__ . '/../includes/admin-nav.php'; ?>
<main class="content-area admin-main-content">


        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">Dashboard Overview</h2>
            <div class="d-flex align-items-center">
                <span class="me-3">Welcome, <strong><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></strong></span>
            </div>
        </div>

        <!-- Metric Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card stat-card shadow-sm mb-3">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase mb-2">Total Users</h6>
                        <h2 class="mb-0"><?= $totalUsers ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card shadow-sm mb-3" style="border-left-color: #198754;">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase mb-2">Active Events</h6>
                        <h2 class="mb-0"><?= $activeEvents ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card shadow-sm mb-3" style="border-left-color: #ffc107;">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase mb-2">Pending Equipment</h6>
                        <h2 class="mb-0"><?= $pendingEquipment ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card shadow-sm mb-3" style="border-left-color: #dc3545;">
                    <div class="card-body">
                        <h6 class="text-muted text-uppercase mb-2">Total Check-Ins</h6>
                        <h2 class="mb-0"><?= $totalCheckIns ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activity and Quick Actions -->
        <div class="row g-4">
            <div class="col-lg-8">
                <section class="card shadow-sm admin-activity-card">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
                        <div><h5 class="mb-1">Recent Activity</h5><p class="small text-muted mb-0">Activity from events that are currently ongoing.</p></div>
                        <div class="admin-activity-tabs" role="tablist" aria-label="Recent activity categories">
                            <button type="button" class="admin-activity-tab is-active" id="ongoingTab" role="tab" aria-selected="true" aria-controls="ongoingPanel" data-activity-tab="ongoing"><i class="bi bi-calendar2-check"></i> Ongoing Events <span><?= count($ongoingEvents) ?></span></button>
                            <button type="button" class="admin-activity-tab" id="attendanceTab" role="tab" aria-selected="false" aria-controls="attendancePanel" data-activity-tab="attendance"><i class="bi bi-person-check"></i> Attendance <span><?= count($recentAttendance) ?></span></button>
                        </div>
                    </div>
                    <div class="card-body admin-activity-body">
                        <div class="admin-activity-panel is-active" id="ongoingPanel" role="tabpanel" aria-labelledby="ongoingTab" data-activity-panel="ongoing">
                            <?php if (empty($ongoingEvents)): ?>
                                <div class="admin-activity-empty"><i class="bi bi-calendar2-x"></i><strong>No events are ongoing right now</strong><span>Upcoming and completed events are excluded from this view.</span></div>
                            <?php else: ?>
                                <div class="admin-activity-list">
                                    <?php foreach (array_slice($ongoingEvents, 0, 8) as$event): ?>
                                        <article class="admin-activity-item" data-event-id="<?= htmlspecialchars((string)($event['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><span class="admin-activity-icon event"><i class="bi bi-calendar-event"></i></span><div class="admin-activity-copy"><strong><?= htmlspecialchars($event['title'] ?? 'Untitled event') ?></strong><span><?= htmlspecialchars($event['venue'] ?? 'Venue not specified') ?></span><small>Started <?= htmlspecialchars(formatDashboardActivityTime($event['start_datetime'] ?? '')) ?></small></div><span class="admin-live-label"><span></span> Ongoing</span></article>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <div class="admin-activity-footer"><a href="events.php">Manage events <i class="bi bi-arrow-up-right"></i></a></div>
                        </div>
                        <div class="admin-activity-panel" id="attendancePanel" role="tabpanel" aria-labelledby="attendanceTab" data-activity-panel="attendance" hidden>
                            <?php if (empty($recentAttendance)): ?>
                                <div class="admin-activity-empty"><i class="bi bi-person-check"></i><strong>No recent attendance yet</strong><span>Check-ins for currently ongoing events will appear here.</span></div>
                            <?php else: ?>
                                <div class="admin-activity-list">
                                    <?php foreach ($recentAttendance as$record): ?>
                                        <article class="admin-activity-item" data-event-id="<?= htmlspecialchars((string)($record['event_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><span class="admin-activity-icon attendance"><i class="bi bi-person-check"></i></span><div class="admin-activity-copy"><strong><?= htmlspecialchars($record['participant_name']) ?></strong><span>Checked in to <?= htmlspecialchars($record['event_title']) ?></span><small><?= htmlspecialchars(formatDashboardActivityTime($record['check_in_time'] ?? '')) ?><?php if (!empty($record['participant_course']) || !empty($record['participant_section'])): ?> · <?= htmlspecialchars(trim(($record['participant_course'] ?? '') . ' ' . ($record['participant_section'] ?? ''))) ?><?php endif; ?></small></div><span class="admin-attendance-label"><?= !empty($record['time_out']) ? 'Completed' : 'Present' ?></span></article>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <div class="admin-activity-footer"><a href="attendance-logs.php">View attendance logs <i class="bi bi-arrow-up-right"></i></a></div>
                        </div>
                    </div>
                </section>
            </div>
            <div class="col-lg-4"><div class="card shadow-sm h-100"><div class="card-header"><h5 class="mb-0">Quick Actions</h5></div><div class="card-body"><div class="d-grid gap-3">
                <a href="events.php?action=new" class="btn btn-outline-primary text-start"><i class="bi bi-plus-circle me-2"></i> Create New Event</a>
                <a href="attendance-logs.php" class="btn btn-outline-success text-start"><i class="bi bi-person-bounding-box me-2"></i> View Face &amp; GPS Attendance</a>
                <a href="equipment.php?action=approve" class="btn btn-outline-warning text-start"><i class="bi bi-tools me-2"></i> Review Borrowing Requests</a>
            </div></div></div></div>
        </div>

    </div>

</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const tabs = Array.from(document.querySelectorAll('[data-activity-tab]'));
    const panels = Array.from(document.querySelectorAll('[data-activity-panel]'));

    function showActivity(selected) {
        tabs.forEach(function (tab) {
            const active = tab.dataset.activityTab === selected;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        panels.forEach(function (panel) {
            const active = panel.dataset.activityPanel === selected;
            panel.classList.toggle('is-active', active);
            panel.hidden = !active;
        });
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
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

            if (Math.abs(deltaX) < 55 || Math.abs(deltaX) < Math.abs(deltaY)) return;

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
    const eventItems = Array.from(
        document.querySelectorAll('#ongoingPanel .admin-activity-item')
    );
    const attendancePanel = document.querySelector('#attendancePanel');
    const attendanceTab = document.querySelector('#attendanceTab');

    if (!attendancePanel || !attendanceTab || !eventItems.length) return;

    let attendanceList = attendancePanel.querySelector('.admin-activity-list');
    let originalEmptyState = attendancePanel.querySelector(
        '.admin-activity-empty:not([data-event-attendance-empty])'
    );

    // Keep a separate empty state for event-specific filtering.
    let filteredEmptyState = attendancePanel.querySelector(
        '[data-event-attendance-empty]'
    );

    if (!filteredEmptyState) {
        filteredEmptyState = document.createElement('div');
        filteredEmptyState.className = 'admin-activity-empty';
        filteredEmptyState.setAttribute('data-event-attendance-empty', '');
        filteredEmptyState.innerHTML =
            '<i class="bi bi-person-check"></i>' +
            '<strong></strong>' +
            '<span>No attendance records were found for this event.</span>';
        attendancePanel.insertBefore(
            filteredEmptyState,
            attendanceList || originalEmptyState || attendancePanel.firstChild
        );
    }

    function setVisible(element, visible) {
        if (!element) return;
        element.hidden = !visible;
        element.style.display = visible ? '' : 'none';
    }

    function getAttendanceRows() {
        return attendanceList
            ? Array.from(attendanceList.querySelectorAll('.admin-activity-item'))
            : [];
    }

    function showAllAttendance() {
        const rows = getAttendanceRows();

        rows.forEach(function (row) {
            setVisible(row, true);
        });

        setVisible(attendanceList, rows.length > 0);
        setVisible(filteredEmptyState, false);
        setVisible(originalEmptyState, rows.length === 0);
    }

    eventItems.forEach(function (eventItem) {
        eventItem.style.cursor = 'pointer';
        eventItem.setAttribute('role', 'button');
        eventItem.setAttribute('tabindex', '0');

        function showEventAttendance() {
            const eventId = String(eventItem.dataset.eventId || '');
            const eventTitle = eventItem.querySelector(
                '.admin-activity-copy strong'
            )?.textContent.trim() || 'Selected event';

            if (!eventId) return;

            const rows = getAttendanceRows();
            let matchingCount = 0;

            rows.forEach(function (row) {
                const matches = String(row.dataset.eventId || '') === eventId;
                setVisible(row, matches);
                if (matches) matchingCount++;
            });

            // The original PHP empty state is only for the unfiltered view.
            setVisible(originalEmptyState, false);
            setVisible(attendanceList, matchingCount > 0);
            setVisible(filteredEmptyState, matchingCount === 0);
            filteredEmptyState.querySelector('strong').textContent =
                'Attendance for ' + eventTitle;

            // Switch tabs without triggering the "show all" reset.
            selectingEvent = true;
            attendanceTab.click();
            selectingEvent = false;
        }

        eventItem.addEventListener('click', showEventAttendance);
        eventItem.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                showEventAttendance();
            }
        });
    });

    let selectingEvent = false;

    attendanceTab.addEventListener('click', function () {
        if (!selectingEvent) showAllAttendance();
    });
})();
</script>

</body>
</html>
