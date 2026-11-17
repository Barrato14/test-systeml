<?php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/daily-attendance.php';

if (!isset($_SESSION['user_id']) || strtolower((string)($_SESSION['user_role'] ?? '')) !== 'participant') {
    header('Location: ../login.php');
    exit;
}

$participantId = (string)$_SESSION['user_id'];
$selectedEventId = trim((string)($_GET['event_id'] ?? ''));
$manila = new DateTimeZone('Asia/Manila');
$now = new DateTimeImmutable('now', $manila);

$regRes = supabaseRequest('event_registrations?participant_id=eq.' . rawurlencode($participantId), 'GET');
$registrations = (($regRes['code'] ?? 500) < 400 && is_array($regRes['data'] ?? null)) ? $regRes['data'] : [];
$eventsRes = supabaseRequest('events?order=start_datetime.desc', 'GET');
$events = (($eventsRes['code'] ?? 500) < 400 && is_array($eventsRes['data'] ?? null)) ? $eventsRes['data'] : [];
$eventMap = [];
foreach ($events as $event) if (!empty($event['id'])) $eventMap[(string)$event['id']] = $event;

$standardRes = supabaseRequest('attendance?participant_id=eq.' . rawurlencode($participantId) . '&select=event_id,check_in_time,time_out,status&order=check_in_time.desc', 'GET');
$standardRows = (($standardRes['code'] ?? 500) < 400 && is_array($standardRes['data'] ?? null)) ? $standardRes['data'] : [];
$standardByEvent = [];
foreach ($standardRows as $row) $standardByEvent[(string)($row['event_id'] ?? '')] = $row;

$dailyRes = supabaseServiceRequest('daily_attendance?participant_id=eq.' . rawurlencode($participantId) . '&select=event_id,attendance_date,check_in_time,time_out,status&order=attendance_date.desc', 'GET');
$dailyRows = (($dailyRes['code'] ?? 500) < 400 && is_array($dailyRes['data'] ?? null)) ? $dailyRes['data'] : [];
$dailyByEvent = [];
foreach ($dailyRows as $row) $dailyByEvent[(string)($row['event_id'] ?? '')][] = $row;

$myAttendanceEvents = [];
foreach ($registrations as $registration) {
    $eventId = (string)($registration['event_id'] ?? '');
    if ($eventId === '' || !isset($eventMap[$eventId])) continue;
    if ($selectedEventId !== '' && $eventId !== $selectedEventId) continue;
    $event = $eventMap[$eventId];
    $multi = isMultiDayAttendanceEvent($event);
    $rows = $multi ? ($dailyByEvent[$eventId] ?? []) : (isset($standardByEvent[$eventId]) ? [$standardByEvent[$eventId]] : []);
    usort($rows, function($a, $b) { return strcmp((string)($b['attendance_date'] ?? $b['check_in_time'] ?? ''), (string)($a['attendance_date'] ?? $a['check_in_time'] ?? '')); });
    $myAttendanceEvents[] = ['event' => $event, 'multi' => $multi, 'rows' => $rows];
}
usort($myAttendanceEvents, function($a, $b) {
    return strtotime((string)($b['event']['start_datetime'] ?? '')) <=> strtotime((string)($a['event']['start_datetime'] ?? ''));
});
$totalDays = 0;
$totalRecordedMinutes = 0;
foreach ($myAttendanceEvents as $item) {
    $totalDays += count($item['rows']);
    foreach ($item['rows'] as $attendanceRow) {
        if (empty($attendanceRow['check_in_time']) || empty($attendanceRow['time_out'])) continue;
        try {
            $in = new DateTimeImmutable($attendanceRow['check_in_time']);
            $out = new DateTimeImmutable($attendanceRow['time_out']);
            if ($out > $in) $totalRecordedMinutes += (int)(($out->getTimestamp() - $in->getTimestamp()) / 60);
        } catch (Throwable $e) {}
    }
}
$totalRecordedHours = intdiv($totalRecordedMinutes, 60);
$totalRecordedRemainder = $totalRecordedMinutes % 60;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Attendance - Zentrix</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
<link rel="stylesheet" href="../css/style.css?v=20261117-1">
<style>
.attendance-page{max-width:1120px;margin:38px auto;padding:0 22px;color:#f4edff}
.attendance-heading{display:flex;justify-content:space-between;align-items:end;gap:18px;margin-bottom:24px}
.attendance-kicker{color:#c5a0ff;font-size:11px;font-weight:800;letter-spacing:.15em}
.attendance-heading h1{font-size:30px;font-weight:800;margin:5px 0}
.attendance-heading p{color:#b9a9cc;margin:0}
.attendance-summary{background:#1b1427;border:1px solid #382747;border-radius:16px;padding:18px 22px;min-width:140px;text-align:center}
.attendance-summary strong{display:block;font-size:27px}.attendance-summary span{font-size:12px;color:#bba9d0}
.attendance-guide{background:#21172f;border:1px solid #49335f;border-radius:13px;padding:16px 18px;margin-bottom:22px;color:#d9cbe9}
.attendance-guide strong{color:#fff}.attendance-guide p{margin:5px 0 0;color:#c3b3d4;font-size:13px}
.attendance-event{background:#171121;border:1px solid #342440;border-radius:16px;margin-bottom:16px;overflow:hidden}
.attendance-event-head{padding:17px 20px;border-bottom:1px solid #342440;display:flex;justify-content:space-between;gap:14px;align-items:center}
.attendance-event-head h2{font-size:17px;font-weight:750;margin:0 0 5px}.attendance-event-head p{margin:0;color:#b9a9ca;font-size:12px}
.attendance-type{font-size:10px;font-weight:800;color:#d7baff;background:#38254b;border-radius:20px;padding:6px 10px;white-space:nowrap}
.attendance-table-wrap{overflow-x:auto;padding:0 16px 12px}
.attendance-table{width:100%;border-collapse:collapse;min-width:680px}
.attendance-table th{color:#c8a9f7;font-size:10px;letter-spacing:.06em;text-align:left;padding:13px 10px}
.attendance-table td{padding:13px 10px;border-top:1px solid #30223d;color:#e6dcef;font-size:12px}
.attendance-muted{color:#a997ba!important}.attendance-status{display:inline-block;border-radius:20px;padding:5px 9px;font-size:10px;font-weight:800;background:#253c32;color:#9af0bb}
.attendance-status.missing{background:#49331e;color:#ffd08a}.attendance-status.absent{background:#49252c;color:#ffabb5}
.attendance-empty{padding:22px;color:#bba9cc;text-align:center;font-size:13px}
@media(max-width:650px){.attendance-page{margin:24px auto;padding:0 13px}.attendance-heading{align-items:start;flex-direction:column}.attendance-summary{width:100%;text-align:left}.attendance-summary strong,.attendance-summary span{display:inline;margin-right:5px}}
</style>
</head>
<body class="participant-page">
<?php include '../includes/participant-nav.php'; ?>
<main class="attendance-page">
<header class="attendance-heading"><div><span class="attendance-kicker">YOUR PERSONAL RECORD</span><h1>My Attendance</h1><p>See every event you attended and your daily check-in and check-out history.</p><?php if ($selectedEventId !== ''): ?><a href="attendance.php" style="display:inline-block;margin-top:9px;color:#d7baff;font-size:12px;text-decoration:none">&larr; View all event attendance</a><?php endif; ?></div><div class="attendance-summary"><strong><?= number_format($totalDays) ?></strong><span>attendance days</span><div style="margin-top:8px;font-size:17px;font-weight:800"><?= $totalRecordedHours ?>h <?= $totalRecordedRemainder ?>m</div><span>completed recorded time</span></div></header>
<section class="attendance-guide"><strong><i class="bi bi-info-circle me-1"></i> How daily attendance works</strong><p>Single-day events have one attendance record. Multi-day events have a separate record for each event date. Each day has its own timer: it starts at your actual time-in and stops at your actual time-out. Only completed time-in/time-out pairs count toward recorded hours. If you miss time-out, that day is marked as missing and adds no hours; the next day's attendance is separate.</p></section>
<?php if (!$myAttendanceEvents): ?>
<div class="attendance-event"><div class="attendance-empty">Your registered events and attendance records will appear here.</div></div>
<?php else: foreach ($myAttendanceEvents as $item):
$event = $item['event'];
$rows = $item['rows'];
?>
<section class="attendance-event">
<header class="attendance-event-head"><div><h2><?= htmlspecialchars((string)($event['title'] ?? 'Event')) ?></h2><p><i class="bi bi-calendar-event me-1"></i><?= !empty($event['start_datetime']) ? htmlspecialchars((new DateTimeImmutable($event['start_datetime']))->setTimezone($manila)->format('M j, Y')) : 'Date unavailable' ?><?= !empty($event['venue']) ? ' · ' . htmlspecialchars((string)$event['venue']) : '' ?></p></div><span class="attendance-type"><?= $item['multi'] ? 'MULTI-DAY EVENT' : 'SINGLE-DAY EVENT' ?></span></header>
<?php if (!$rows): ?><div class="attendance-empty">No attendance has been recorded for this event yet.</div>
<?php else: ?>
<div class="attendance-table-wrap"><table class="attendance-table"><thead><tr><th><?= $item['multi'] ? 'EVENT DAY' : 'DATE' ?></th><th>TIME IN</th><th>TIME OUT</th><th>DAILY DURATION</th><th>STATUS</th></tr></thead><tbody>
<?php foreach ($rows as $row):
$dateValue = $row['attendance_date'] ?? $row['check_in_time'] ?? '';
$dateLabel = $dateValue ? (new DateTimeImmutable($dateValue))->setTimezone($manila)->format('M j, Y') : '—';
$endTime = null;
try { $endTime = !empty($event['end_datetime']) ? (new DateTimeImmutable($event['end_datetime']))->setTimezone($manila) : null; } catch (Throwable $e) {}
$recordDate = !empty($row['attendance_date']) ? (string)$row['attendance_date'] : (!empty($row['check_in_time']) ? (new DateTimeImmutable($row['check_in_time']))->setTimezone($manila)->format('Y-m-d') : '');
$dayClosed = $recordDate !== '' && ($recordDate < $now->format('Y-m-d') || ($endTime && $endTime <= $now));
$status = (string)($row['status'] ?? 'Present');
if (strcasecmp($status, 'Absent') !== 0 && empty($row['time_out']) && $dayClosed) $status = 'Missing Time Out';
$statusClass = stripos($status, 'Missing') !== false ? 'missing' : (strcasecmp($status, 'Absent') === 0 ? 'absent' : '');
$durationLabel = 'Incomplete';
if (!empty($row['check_in_time']) && !empty($row['time_out'])) {
    try {
        $durationIn = new DateTimeImmutable($row['check_in_time']);
        $durationOut = new DateTimeImmutable($row['time_out']);
        if ($durationOut > $durationIn) {
            $durationMinutes = (int)(($durationOut->getTimestamp() - $durationIn->getTimestamp()) / 60);
            $durationLabel = intdiv($durationMinutes, 60) . 'h ' . ($durationMinutes % 60) . 'm';
        }
    } catch (Throwable $e) {}
}
?>
<tr><td><?= htmlspecialchars($dateLabel) ?></td><td><?= !empty($row['check_in_time']) ? htmlspecialchars((new DateTimeImmutable($row['check_in_time']))->setTimezone($manila)->format('g:i A')) : '—' ?></td><td><?= !empty($row['time_out']) ? htmlspecialchars((new DateTimeImmutable($row['time_out']))->setTimezone($manila)->format('g:i A')) : '<span class="attendance-muted">Not recorded</span>' ?></td><td><?= htmlspecialchars($durationLabel) ?></td><td><span class="attendance-status <?= $statusClass ?>"><?= htmlspecialchars($status) ?></span></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
</section>
<?php endforeach; endif; ?>
</main>
<link rel="stylesheet" href="../css/participant-content-refresh.css?v=20261117-2">
</body></html>