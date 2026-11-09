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
$manilaTimezone = new DateTimeZone('Asia/Manila');
$now = new DateTimeImmutable('now', $manilaTimezone);
$eventsResponse = supabaseRequest('events?select=id,title,start_datetime,end_datetime,venue&order=start_datetime.asc', 'GET');
$ongoingEvents = [];
$ongoingEventMap = [];
if (is_array($eventsResponse['data'] ?? null)) {
    foreach ($eventsResponse['data'] as $event) {
        if (empty($event['start_datetime']) || empty($event['end_datetime'])) continue;
        try {
            $eventStart = (new DateTimeImmutable($event['start_datetime']))->setTimezone($manilaTimezone);
            $eventEnd = (new DateTimeImmutable($event['end_datetime']))->setTimezone($manilaTimezone);
            if ($eventStart <= $now && $eventEnd >= $now) {
                $ongoingEvents[] = $event;
                $ongoingEventMap[(string)$event['id']] = $event;
            }
        } catch (Exception $e) { continue; }
    }
}
$activeEvents = count($ongoingEvents);

// Fetch attendance separately and show check-ins belonging to ongoing events only.
$attendanceResponse = supabaseRequest('attendance?select=id,participant_id,event_id,check_in_time,time_out&order=check_in_time.desc&limit=50', 'GET');
$profilesResponse = supabaseRequest('profiles?select=id,first_name,last_name,course,section', 'GET');
$profilesMap = [];
if (is_array($profilesResponse['data'] ?? null)) {
    foreach ($profilesResponse['data'] as $profile) {
        $profilesMap[(string)($profile['id'] ?? '')] = $profile;
    }
}
$recentAttendance = [];
if (is_array($attendanceResponse['data'] ?? null)) {
    foreach ($attendanceResponse['data'] as $attendance) {
        $eventId = (string)($attendance['event_id'] ?? '');
        if ($eventId === '' || !isset($ongoingEventMap[$eventId])) continue;
        $participantId = (string)($attendance['participant_id'] ?? '');
        $profile = $profilesMap[$participantId] ?? [];
        $attendance['participant_name'] = trim((string)($profile['first_name'] ?? '') . ' ' . (string)($profile['last_name'] ?? '')) ?: 'Participant';
        $attendance['participant_course'] = $profile['course'] ?? '';
        $attendance['participant_section'] = $profile['section'] ?? '';
        $attendance['event_title'] = $ongoingEventMap[$eventId]['title'] ?? 'Ongoing event';
        $recentAttendance[] = $attendance;
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        .sidebar { min-height: 100vh; background-color: #343a40; color: white; }
        .sidebar a { color: #cfd8dc; text-decoration: none; padding: 12px 20px; display: block; }
        .sidebar a:hover, .sidebar a.active { background-color: #495057; color: white; border-left: 4px solid #0d6efd; }
        .content-area { padding: 30px; background-color: #f8f9fa; min-height: 100vh; }
        .stat-card { border-left: 5px solid #0d6efd; border-radius: 8px; }
    </style>
    <link rel="stylesheet" href="../css/portal-theme.css">
<style>
.admin-activity-card{overflow:hidden}.admin-activity-card>.card-header{gap:16px}.admin-activity-tabs{display:flex;flex-wrap:wrap;gap:7px}
.admin-activity-tab{display:inline-flex;align-items:center;gap:8px;padding:8px 11px;border:1px solid #49365e;border-radius:10px;background:transparent;color:#bba9cf;font-size:11px;font-weight:750;cursor:pointer}
.admin-activity-tab span{min-width:20px;padding:2px 6px;border-radius:20px;background:#30213f;color:#e7d8fa;text-align:center;font-size:10px}.admin-activity-tab.is-active{background:#38264f;border-color:#8e65ba;color:#fff}.admin-activity-tab.is-active span{background:#8b5cf6;color:#fff}
.admin-activity-body{min-height:280px}.admin-activity-panel[hidden]{display:none!important}.admin-activity-list{display:grid}.admin-activity-item{display:flex;align-items:center;gap:13px;padding:13px 2px;border-bottom:1px solid rgba(145,115,170,.2)}.admin-activity-item:last-child{border-bottom:0}
.admin-activity-icon{width:39px;height:39px;flex:0 0 39px;display:grid;place-items:center;border-radius:12px;font-size:17px}.admin-activity-icon.event{background:rgba(139,92,246,.16);color:#c4a5ff}.admin-activity-icon.attendance{background:rgba(16,185,129,.14);color:#6ee7b7}
.admin-activity-copy{display:flex;flex:1;flex-direction:column;gap:3px;min-width:0}.admin-activity-copy strong,.admin-activity-copy span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.admin-activity-copy strong{color:inherit;font-size:12px}.admin-activity-copy span{color:#b4a6c4;font-size:11px}.admin-activity-copy small{color:#93849f;font-size:10px}
.admin-live-label,.admin-attendance-label{display:inline-flex;align-items:center;gap:6px;flex:0 0 auto;color:#6ee7b7;font-size:10px;font-weight:800}.admin-live-label>span{width:7px;height:7px;border-radius:50%;background:#34d399;box-shadow:0 0 0 4px rgba(52,211,153,.12)}.admin-attendance-label{padding:5px 8px;border-radius:20px;background:rgba(16,185,129,.12)}
.admin-activity-empty{min-height:190px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;text-align:center;color:#a99ab9}.admin-activity-empty>i{font-size:28px;color:#9b7ac0}.admin-activity-empty strong{color:inherit;font-size:13px}.admin-activity-empty span{font-size:11px}
.admin-activity-footer{display:flex;justify-content:flex-end;padding-top:12px;border-top:1px solid rgba(145,115,170,.2)}.admin-activity-footer a{color:#c4a5ff;text-decoration:none;font-size:11px;font-weight:750}
html[data-admin-theme="light"] .admin-activity-tab{border-color:#e3d6ed;color:#725c83}html[data-admin-theme="light"] .admin-activity-tab span{background:#f0e8f7;color:#644b79}html[data-admin-theme="light"] .admin-activity-tab.is-active{background:#eee4f8;border-color:#b99bd3;color:#593a75}html[data-admin-theme="light"] .admin-activity-copy span{color:#766985}html[data-admin-theme="light"] .admin-activity-copy small{color:#8d8198}html[data-admin-theme="light"] .admin-activity-footer{border-color:#eee6f3}html[data-admin-theme="light"] .admin-activity-footer a{color:#70449b}
@media(max-width:600px){.admin-activity-card>.card-header{align-items:flex-start!important}.admin-activity-tabs{width:100%}.admin-activity-tab{flex:1;justify-content:center;padding:8px 6px;font-size:10px}.admin-activity-item{gap:9px}.admin-live-label,.admin-attendance-label{font-size:9px}}
</style></head>
<body class="admin-portal">
<?php require_once __DIR__ . '/../includes/admin-nav.php'; ?>
<main class="content-area admin-main-content">


        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">Dashboard Overview</h2>
            <div class="d-flex align-items-center">
                <span class="me-3">Welcome, <strong><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></strong></span>
                <div class="dropdown">
                    <button class="btn btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="../logout.php">Logout</a></li>
                    </ul>
                </div>
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
                                    <?php foreach (array_slice($ongoingEvents, 0, 8) as $event): ?>
                                        <article class="admin-activity-item"><span class="admin-activity-icon event"><i class="bi bi-calendar-event"></i></span><div class="admin-activity-copy"><strong><?= htmlspecialchars($event['title'] ?? 'Untitled event') ?></strong><span><?= htmlspecialchars($event['venue'] ?? 'Venue not specified') ?></span><small>Started <?= htmlspecialchars(formatDashboardActivityTime($event['start_datetime'] ?? '')) ?></small></div><span class="admin-live-label"><span></span> Ongoing</span></article>
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
                                    <?php foreach ($recentAttendance as $record): ?>
                                        <article class="admin-activity-item"><span class="admin-activity-icon attendance"><i class="bi bi-person-check"></i></span><div class="admin-activity-copy"><strong><?= htmlspecialchars($record['participant_name']) ?></strong><span>Checked in to <?= htmlspecialchars($record['event_title']) ?></span><small><?= htmlspecialchars(formatDashboardActivityTime($record['check_in_time'] ?? '')) ?><?php if (!empty($record['participant_course']) || !empty($record['participant_section'])): ?> · <?= htmlspecialchars(trim(($record['participant_course'] ?? '') . ' ' . ($record['participant_section'] ?? ''))) ?><?php endif; ?></small></div><span class="admin-attendance-label"><?= !empty($record['time_out']) ? 'Completed' : 'Present' ?></span></article>
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
<script>(function(){const tabs=document.querySelectorAll('[data-activity-tab]');const panels=document.querySelectorAll('[data-activity-panel]');tabs.forEach(function(tab){tab.addEventListener('click',function(){const selected=tab.dataset.activityTab;tabs.forEach(function(item){const active=item===tab;item.classList.toggle('is-active',active);item.setAttribute('aria-selected',active?'true':'false')});panels.forEach(function(panel){const active=panel.dataset.activityPanel===selected;panel.classList.toggle('is-active',active);panel.hidden=!active})})})})();</script></body>
</html>
