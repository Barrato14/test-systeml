<?php
session_start();
date_default_timezone_set('Asia/Manila');
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/daily-attendance.php';

// Administrators and Event Organizers may access Attendance Logs.
$currentRole = strtolower(trim((string)($_SESSION['user_role'] ?? '')));
if (
    !isset($_SESSION['user_id']) ||
    !in_array($currentRole, ['administrator', 'staff'], true)
) {
    http_response_code(403);
    exit('Access denied.');
}

// Fetch events visible to the current role.
$eventsQuery = 'events?select=id,title,end_datetime&order=start_datetime.desc';
if ($currentRole === 'event organizer') {
    $eventsQuery = 'events?select=id,title&organizer_id=eq.'
        . rawurlencode((string)$_SESSION['user_id'])
        . '&order=start_datetime.desc';
}
$eventsRes = supabaseRequest($eventsQuery, 'GET');
$eventsMap = [];
$eventEndMap = [];
$eventOptions = [];
if (($eventsRes['code'] ?? 0) === 200 && is_array($eventsRes['data'] ?? null)) {
    foreach ($eventsRes['data'] as $event) {
        $eventId = (string)($event['id'] ?? '');
        if ($eventId === '') continue;
        $eventsMap[$eventId] = (string)($event['title'] ?? 'Untitled Event');
        $eventEndMap[$eventId] = $event['end_datetime'] ?? null;
        $eventOptions[$eventId] = (string)($event['title'] ?? 'Untitled Event');
    }
}

// Allow Administrators and Staff to toggle attendance between Present and Absent.
if (empty($_SESSION['attendance_logs_csrf'])) {
    $_SESSION['attendance_logs_csrf'] = bin2hex(random_bytes(32));
}
$attendanceCsrf = $_SESSION['attendance_logs_csrf'];
$attendanceActionMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_attendance_status') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    $attendanceId = trim((string)($_POST['attendance_id'] ?? ''));
    $attendanceSource = ($_POST['attendance_source'] ?? '') === 'daily' ? 'daily_attendance' : 'attendance';

    if (!hash_equals($attendanceCsrf, $submittedToken)) {
        http_response_code(403);
        exit('Invalid security token. Please reload the page and try again.');
    }

    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $attendanceId)) {
        $attendanceActionMessage = 'Invalid attendance record.';
    } else {
        // Confirm that the record belongs to an event this account is allowed to manage.
        $recordRes = $attendanceSource === 'daily_attendance'
            ? supabaseServiceRequest($attendanceSource . '?select=id,event_id,participant_id,status&id=eq.' . rawurlencode($attendanceId), 'GET')
            : supabaseRequest($attendanceSource . '?select=id,event_id,participant_id,status&id=eq.' . rawurlencode($attendanceId), 'GET');
        $record = (($recordRes['code'] ?? 500) === 200 && is_array($recordRes['data'] ?? null))
            ? ($recordRes['data'][0] ?? null)
            : null;

        if (!$record || !isset($eventsMap[(string)($record['event_id'] ?? '')])) {
            $attendanceActionMessage = 'Attendance record not found or access denied.';
        } else {
            $currentStatus = trim((string)($record['status'] ?? 'Present')) ?: 'Present';
            $newStatus = strcasecmp($currentStatus, 'Absent') === 0 ? 'Present' : 'Absent';

            $updateEndpoint = $attendanceSource . '?id=eq.' . rawurlencode($attendanceId)
                . '&event_id=eq.' . rawurlencode((string)$record['event_id']);
            $updateRes = $attendanceSource === 'daily_attendance'
                ? supabaseServiceRequest($updateEndpoint, 'PATCH', ['status' => $newStatus])
                : supabaseRequest($updateEndpoint, 'PATCH', ['status' => $newStatus]);

            if (($updateRes['code'] ?? 500) >= 200 && ($updateRes['code'] ?? 500) < 300) {
                $participantId = (string)($record['participant_id'] ?? '');
                if (function_exists('logAudit')) {
                    try {
                        logAudit(
                            $_SESSION['user_id'],
                            'attendance_status_changed',
                            'Attendance record ' . $attendanceId . ' for participant ' . $participantId
                                . ' changed from ' . $currentStatus . ' to ' . $newStatus . '.'
                        );
                    } catch (Throwable $e) {
                        error_log('Attendance status audit failed: ' . $e->getMessage());
                    }
                }

                $redirectParams = $_GET;
                $redirectParams['attendance_updated'] = strtolower($newStatus);
                header('Location: attendance-logs.php?' . http_build_query($redirectParams));
                exit;
            }

            $attendanceActionMessage = 'Could not update the attendance status. Please try again.';
        }
    }
}

if (($_GET['attendance_updated'] ?? '') === 'absent') {
    $attendanceActionMessage = 'Attendance status successfully changed to Absent.';
} elseif (($_GET['attendance_updated'] ?? '') === 'present') {
    $attendanceActionMessage = 'Attendance status successfully changed to Present.';
}

// Fetch only attendance records belonging to the visible events.
$visibleEventIds = array_keys($eventsMap);
$attendanceLogs = [];
if ($currentRole === 'administrator') {
    $attendanceRes = supabaseRequest('attendance?select=*&order=check_in_time.desc', 'GET');
    $attendanceLogs = (($attendanceRes['code'] ?? 500) < 400 && is_array($attendanceRes['data'] ?? null))
        ? $attendanceRes['data']
        : [];
} elseif (!empty($visibleEventIds)) {
    $encodedEventIds = array_map('rawurlencode', $visibleEventIds);
    $attendanceRes = supabaseRequest(
        'attendance?select=*&event_id=in.(' . implode(',', $encodedEventIds) . ')&order=check_in_time.desc',
        'GET'
    );
    $attendanceLogs = (($attendanceRes['code'] ?? 500) < 400 && is_array($attendanceRes['data'] ?? null))
        ? $attendanceRes['data']
        : [];
}

// Fetch per-day attendance rows for multi-day events and merge into the same log.
$dailyLogsQuery = 'daily_attendance?select=*&order=check_in_time.desc';
if ($currentRole === 'event organizer') {
    $dailyLogsQuery = !empty($visibleEventIds)
        ? 'daily_attendance?select=*&event_id=in.(' . implode(',', array_map('rawurlencode', $visibleEventIds)) . ')&order=check_in_time.desc'
        : null;
}
if ($dailyLogsQuery !== null) {
    $dailyRes = supabaseServiceRequest($dailyLogsQuery, 'GET');
    if (($dailyRes['code'] ?? 500) < 400 && is_array($dailyRes['data'] ?? null)) {
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        foreach ($dailyRes['data'] as $dailyRow) {
            $dailyRow['_attendance_source'] = 'daily';
            $eventEnded = false;
            $eventEndValue = $eventEndMap[(string)($dailyRow['event_id'] ?? '')] ?? null;
            if ($eventEndValue) {
                try { $eventEnded = (new DateTimeImmutable($eventEndValue))->setTimezone(new DateTimeZone('Asia/Manila')) <= new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')); }
                catch (Throwable $e) {}
            }
            if (strcasecmp((string)($dailyRow['status'] ?? ''), 'Absent') === 0) {
                $dailyRow['_display_status'] = 'Absent';
            } elseif (empty($dailyRow['time_out']) && !empty($dailyRow['attendance_date']) && ($dailyRow['attendance_date'] < $today || ($dailyRow['attendance_date'] === $today && $eventEnded))) {
                $dailyRow['_display_status'] = 'Missing Time Out';
            } else {
                $dailyRow['_display_status'] = $dailyRow['status'] ?? 'Present';
            }
            $attendanceLogs[] = $dailyRow;
        }
    }
}
usort($attendanceLogs, function ($a, $b) {
    return strtotime((string)($b['check_in_time'] ?? '')) <=> strtotime((string)($a['check_in_time'] ?? ''));
});

// Fetch profiles to map course/section info if available
$profilesRes = supabaseRequest('profiles?select=*', 'GET');
$profilesMap = [];
if ($profilesRes['code'] === 200 && is_array($profilesRes['data'])) {
    foreach ($profilesRes['data'] as $profile) {
        $profilesMap[$profile['id']] = $profile;
    }
}

// Filter parameters from query string
$filterCourse = $_GET['course'] ?? '';
$filterSection = $_GET['section'] ?? '';
$filterEvent = trim((string)($_GET['event_id'] ?? ''));

// Apply event, course, and section filters if selected
if (!empty($filterEvent) || !empty($filterCourse) || !empty($filterSection)) {
    $attendanceLogs = array_filter($attendanceLogs, function($log) use ($profilesMap, $filterCourse, $filterSection, $filterEvent) {
        if ($filterEvent !== '' && (string)($log['event_id'] ?? '') !== $filterEvent) return false;
        $pid = $log['participant_id'] ?? '';
        $profile = $profilesMap[$pid] ?? [];
        $pCourse = strtoupper($profile['course'] ?? '');
        $pSection = strtoupper($profile['section'] ?? '');

        if (!empty($filterCourse) && $pCourse !== strtoupper($filterCourse)) return false;
        if (!empty($filterSection) && $pSection !== strtoupper($filterSection)) return false;
        return true;
    });
}

// Helper function to safely format UTC strings to Manila Time
function formatManilaTime($utcString) {
    if (empty($utcString)) return 'N/A';
    try {
        $date = new DateTime($utcString);
        $date->setTimezone(new DateTimeZone('Asia/Manila'));
        return $date->format('M j, g:i A');
    } catch (Exception $e) {
        return 'Invalid Date';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Logs - EMIS</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        .sidebar { min-height: 100vh; background-color: #343a40; color: white; }
        .sidebar a { color: #cfd8dc; text-decoration: none; padding: 12px 20px; display: block; }
        .sidebar a:hover, .sidebar a.active { background-color: #495057; color: white; border-left: 4px solid #0d6efd; }
        .content-area { padding: 30px; background-color: #f8f9fa; min-height: 100vh; }
        .snapshot-thumb { width: 50px; height: 50px; object-fit: cover; border-radius: 4px; cursor: pointer; transition: transform 0.2s; }
        .snapshot-thumb:hover { transform: scale(3); }
        .attendance-map-trigger { display:inline-flex; align-items:center; gap:4px; padding:0; border:0; background:transparent; color:#0d6efd; font:inherit; font-size:.875rem; cursor:pointer; }
        .attendance-map-trigger:hover { color:#084298; text-decoration:underline; }
        .attendance-location-map { display:block; width:100%; height:min(58vh,460px); min-height:280px; border:0; border-radius:12px; background:#f1edf5; }
        .attendance-map-coordinate { color:#a99bbd; font-size:.78rem; }
    </style>
    <link rel="stylesheet" href="../css/portal-theme.css?v=20261114-1">
</head>
<body class="admin-portal">
<?php require_once __DIR__ . '/../includes/admin-nav.php'; ?>
<main class="content-area admin-main-content">

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-person-bounding-box text-primary me-2"></i> Course Attendance Logs</h2>
            <span class="badge bg-secondary fs-6">Showing: <?= count($attendanceLogs) ?> Records</span>
        </div>

        <section class="card shadow-sm border-0 mb-4" style="background:#21172f;color:#e9ddf7;border:1px solid #49335f!important">
            <div class="card-body p-3 p-md-4">
                <div class="d-flex align-items-start gap-3">
                    <i class="bi bi-info-circle-fill" style="color:#c5a0ff;font-size:1.35rem"></i>
                    <div>
                        <h5 class="mb-2" style="color:#fff">How attendance records work</h5>
                        <p class="mb-2 small" style="color:#d0c0e1">This page lists actual participant check-ins. Event registrations alone do not create an attendance record. A record appears after a participant successfully checks in.</p>
                        <div class="small" style="color:#e4d6f2"><strong>Single-day event:</strong> one row per participant with time-in and time-out. <strong>Multi-day event:</strong> a separate row for each participant and event date. The event column shows the date for daily records.</div>
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <span class="badge rounded-pill" style="background:#244b36;color:#a8f0bd">Present</span>
                            <span class="badge rounded-pill" style="background:#5a4020;color:#ffdb9b">Missing Time Out</span>
                            <span class="badge rounded-pill" style="background:#542b32;color:#ffb7c0">Absent</span>
                        </div>
                        <p class="small mb-0 mt-2" style="color:#bcaaca">Missing Time Out means the participant checked in but did not time out before that attendance day or the event ended. It does not automatically create a time-out time.</p>
                    </div>
                </div>
            </div>
        </section>

        <?php if ($attendanceActionMessage !== ''): ?>
            <div class="alert <?= strpos($attendanceActionMessage, 'successfully') !== false ? 'alert-success' : 'alert-info' ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($attendanceActionMessage, ENT_QUOTES, 'UTF-8') ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Filter Card -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <form method="GET" action="attendance-logs.php" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label for="course" class="form-label fw-bold small">Filter by Course</label>
                        <select name="course" id="course" class="form-select">
                            <option value="">All Courses (ACT, HM, CT)</option>
                            <option value="ACT" <?= $filterCourse === 'ACT' ? 'selected' : '' ?>>ACT (Associate in Computer Technology)</option>
                            <option value="HM" <?= $filterCourse === 'HM' ? 'selected' : '' ?>>HM (Hotel Management)</option>
                            <option value="CT" <?= $filterCourse === 'CT' ? 'selected' : '' ?>>CT</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="event_id" class="form-label fw-bold small">Filter by Event</label>
                        <select name="event_id" id="event_id" class="form-select">
                            <option value="">All Events</option>
                            <?php foreach ($eventOptions as $eventId => $eventTitle): ?>
                                <option value="<?= htmlspecialchars($eventId, ENT_QUOTES, 'UTF-8') ?>" <?= $filterEvent === (string)$eventId ? 'selected' : '' ?>><?= htmlspecialchars($eventTitle) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="section" class="form-label fw-bold small">Filter by Year & Section</label>
                        <select name="section" id="section" class="form-select">
                            <option value="">All Sections (1A-2C)</option>
                            <optgroup label="Year 1">
                                <option value="1A" <?= $filterSection === '1A' ? 'selected' : '' ?>>1A</option>
                                <option value="1B" <?= $filterSection === '1B' ? 'selected' : '' ?>>1B</option>
                                <option value="1C" <?= $filterSection === '1C' ? 'selected' : '' ?>>1C</option>
                            </optgroup>
                            <optgroup label="Year 2">
                                <option value="2A" <?= $filterSection === '2A' ? 'selected' : '' ?>>2A</option>
                                <option value="2B" <?= $filterSection === '2B' ? 'selected' : '' ?>>2B</option>
                                <option value="2C" <?= $filterSection === '2C' ? 'selected' : '' ?>>2C</option>
                            </optgroup>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary me-2"><i class="bi bi-filter me-1"></i> Apply Filter</button>
                        <a href="attendance-logs.php" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Logs Table -->
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Participant / ID</th>
                                <th>Course & Section</th>
                                <th>Event</th>
                                <th>Time In (Face & GPS)</th>
                                <th>Time Out (Face & GPS)</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($attendanceLogs)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">No attendance records found matching the filter criteria.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($attendanceLogs as $log): 
                                    $pid = $log['participant_id'] ?? '';
                                    $profile = $profilesMap[$pid] ?? [];
                                    $courseName = $profile['course'] ?? 'ACT/HM/CT';
                                    $sectionName = $profile['section'] ?? '1A';
                                    $fullName = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? '')) ?: 'Participant';
                                ?>
                                    <tr>
                                        <td>
                                            <div><strong><?= htmlspecialchars($fullName) ?></strong></div>
                                            <code class="small text-muted"><?= htmlspecialchars($pid) ?></code>
                                        </td>
                                        <td>
                                            <span class="badge bg-info text-dark"><?= htmlspecialchars(strtoupper($courseName)) ?></span>
                                            <span class="badge bg-secondary"><?= htmlspecialchars(strtoupper($sectionName)) ?></span>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($eventsMap[(string)($log['event_id'] ?? '')] ?? 'Unknown Event') ?></strong>
                                            <?php if (!empty($log['attendance_date'])): ?>
                                                <div class="small text-muted"><?= htmlspecialchars(date('M j, Y', strtotime($log['attendance_date']))) ?> · Daily attendance</div>
                                            <?php endif; ?>
                                            <div><code class="small text-muted"><?= htmlspecialchars($log['event_id'] ?? 'N/A') ?></code></div>
                                        </td>
                                        
                                        <!-- Time In Details -->
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <?php if (!empty($log['photo_url'])): ?>
                                                    <img src="<?= htmlspecialchars($log['photo_url']) ?>" alt="Check-in Face" class="snapshot-thumb border" title="Hover to zoom">
                                                <?php else: ?>
                                                    <span class="text-muted small">No photo</span>
                                                <?php endif; ?>
                                                <div>
                                                    <div class="small fw-bold"><?= formatManilaTime($log['check_in_time'] ?? '') ?></div>
                                                    <?php if (is_numeric($log['latitude'] ?? null) && is_numeric($log['longitude'] ?? null)): ?>
                                                        <button type="button" class="attendance-map-trigger" data-attendance-map
                                                            data-map-lat="<?= htmlspecialchars((string)$log['latitude'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-map-lng="<?= htmlspecialchars((string)$log['longitude'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-map-title="Check-in Location"
                                                            data-map-participant="<?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>">
                                                            <i class="bi bi-geo-alt-fill"></i> Map In
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Time Out Details -->
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <?php if (!empty($log['timeout_photo_url'])): ?>
                                                    <img src="<?= htmlspecialchars($log['timeout_photo_url']) ?>" alt="Time-out Face" class="snapshot-thumb border" title="Hover to zoom">
                                                    <div>
                                                        <div class="small fw-bold text-success"><?= formatManilaTime($log['time_out'] ?? '') ?></div>
                                                        <?php if (is_numeric($log['timeout_latitude'] ?? null) && is_numeric($log['timeout_longitude'] ?? null)): ?>
                                                            <button type="button" class="attendance-map-trigger" data-attendance-map
                                                                data-map-lat="<?= htmlspecialchars((string)$log['timeout_latitude'], ENT_QUOTES, 'UTF-8') ?>"
                                                                data-map-lng="<?= htmlspecialchars((string)$log['timeout_longitude'], ENT_QUOTES, 'UTF-8') ?>"
                                                                data-map-title="Time-out Location"
                                                                data-map-participant="<?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>">
                                                                <i class="bi bi-geo-alt-fill"></i> Map Out
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark">Active / Not Timed Out</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <td>
                                            <?php $attendanceStatus = trim((string)($log['_display_status'] ?? $log['status'] ?? 'Present')) ?: 'Present'; ?>
                                            <?php $isAbsent = strcasecmp($attendanceStatus, 'Absent') === 0; ?>
                                            <form method="POST" class="m-0 d-inline-block" onsubmit="return confirm('Change this participant\'s attendance status to <?= $isAbsent ? 'Present' : 'Absent' ?>?');">
                                                <input type="hidden" name="action" value="toggle_attendance_status">
                                                <input type="hidden" name="attendance_id" value="<?= htmlspecialchars((string)($log['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="attendance_source" value="<?= htmlspecialchars((string)($log['_attendance_source'] ?? 'standard'), ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($attendanceCsrf, ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="submit" class="badge border-0 <?= $isAbsent ? 'bg-danger' : 'bg-success' ?>" style="cursor:pointer; font:inherit;"
                                                    title="Click to change status to <?= $isAbsent ? 'Present' : 'Absent' ?>"
                                                    aria-label="Current status <?= htmlspecialchars($attendanceStatus, ENT_QUOTES, 'UTF-8') ?>. Click to change to <?= $isAbsent ? 'Present' : 'Absent' ?>">
                                                    <?= htmlspecialchars($attendanceStatus) ?>
                                                    <i class="bi bi-arrow-repeat ms-1"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="attendanceLocationModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <div>
                        <span class="small text-uppercase fw-bold text-primary">ATTENDANCE GPS</span>
                        <h5 class="modal-title mb-1" id="attendanceLocationTitle">Participant Location</h5>
                        <div class="small text-muted" id="attendanceLocationParticipant"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <iframe id="attendanceLocationFrame" class="attendance-location-map"
                        title="Participant attendance location map" loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade"></iframe>
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3">
                        <span class="attendance-map-coordinate" id="attendanceLocationCoordinates"></span>
                        <a id="attendanceGoogleMapsLink" href="#" target="_blank" rel="noopener noreferrer"
                            class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-box-arrow-up-right me-1"></i> Open in Google Maps
                        </a>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalElement = document.getElementById('attendanceLocationModal');
    if (!modalElement || typeof bootstrap === 'undefined') return;

    const mapModal = bootstrap.Modal.getOrCreateInstance(modalElement);
    const mapFrame = document.getElementById('attendanceLocationFrame');
    const googleMapsLink = document.getElementById('attendanceGoogleMapsLink');

    document.querySelectorAll('[data-attendance-map]').forEach(function (button) {
        button.addEventListener('click', function () {
            const latitude = Number(button.dataset.mapLat);
            const longitude = Number(button.dataset.mapLng);

            if (!Number.isFinite(latitude) || !Number.isFinite(longitude) ||
                latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) {
                return;
            }

            const coordinates = latitude + ',' + longitude;
            document.getElementById('attendanceLocationTitle').textContent =
                button.dataset.mapTitle || 'Participant Location';
            document.getElementById('attendanceLocationParticipant').textContent =
                button.dataset.mapParticipant || 'Participant';
            document.getElementById('attendanceLocationCoordinates').textContent =
                'Coordinates: ' + coordinates;
            mapFrame.src = 'https://maps.google.com/maps?q=' +
                encodeURIComponent(coordinates) + '&z=17&output=embed';
            googleMapsLink.href = 'https://www.google.com/maps?q=' +
                encodeURIComponent(coordinates);
            mapModal.show();
        });
    });

    modalElement.addEventListener('hidden.bs.modal', function () {
        mapFrame.removeAttribute('src');
    });
});
</script>
</body>
</html>
