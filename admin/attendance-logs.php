<?php
session_start();
date_default_timezone_set('Asia/Manila');
require_once '../config/database.php';
require_once '../includes/auth.php';

// Ensure user is logged in as an Administrator
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role']) !== 'administrator') {
    header("Location: ../login.php");
    exit;
}

// Fetch attendance records and profiles from Supabase
$attendanceRes = supabaseRequest('attendance?select=*&order=check_in_time.desc', 'GET');
$attendanceLogs = ($attendanceRes['code'] === 200 && is_array($attendanceRes['data'])) ? $attendanceRes['data'] : [];

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

// Apply filters if selected
if (!empty($filterCourse) || !empty($filterSection)) {
    $attendanceLogs = array_filter($attendanceLogs, function($log) use ($profilesMap, $filterCourse, $filterSection) {
        $pid = $log['participant_id'] ?? '';
        $profile = $profilesMap[$pid] ?? [];
        $pCourse = strtoupper($profile['course'] ?? '');
        $pSection = strtoupper($profile['section'] ?? '');

        if (!empty($filterCourse) && $pCourse !== strtoupper($filterCourse)) return false;
        if (!empty($filterSection) && $pSection !== strtoupper($filterSection)) return false;
        return true;
    });
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Logs - EMIS Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        .sidebar { min-height: 100vh; background-color: #343a40; color: white; }
        .sidebar a { color: #cfd8dc; text-decoration: none; padding: 12px 20px; display: block; }
        .sidebar a:hover, .sidebar a.active { background-color: #495057; color: white; border-left: 4px solid #0d6efd; }
        .content-area { padding: 30px; background-color: #f8f9fa; min-height: 100vh; }
        .snapshot-thumb { width: 50px; height: 50px; object-fit: cover; border-radius: 4px; cursor: pointer; transition: transform 0.2s; }
        .snapshot-thumb:hover { transform: scale(3); }
    </style>
</head>
<body>

<div class="d-flex">
    <!-- Sidebar Navigation -->
    <div class="sidebar flex-shrink-0" style="width: 250px;">
        <div class="p-4 text-center border-bottom border-secondary">
            <h4 class="mb-0">EMIS Admin</h4>
            <small class="text-muted">Command Center</small>
        </div>
        <nav class="mt-3">
            <a href="dashboard.php"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a>
            <a href="events.php"><i class="bi bi-calendar-event me-2"></i> Event Management</a>
            <a href="equipment.php"><i class="bi bi-tools me-2"></i> Equipment & Services</a>
            <a href="users.php"><i class="bi bi-people me-2"></i> Users & Roles</a>
            <a href="reports.php"><i class="bi bi-bar-chart me-2"></i> Reports & Audits</a>
            <a href="attendance-logs.php" class="active"><i class="bi bi-person-bounding-box me-2"></i> Attendance Logs</a>
            <a href="../logout.php" class="text-danger mt-5"><i class="bi bi-box-arrow-right me-2"></i> Logout</a>
        </nav>
    </div>

    <!-- Main Content Area -->
    <div class="content-area flex-grow-1">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-person-bounding-box text-primary me-2"></i> Course Attendance Logs</h2>
            <span class="badge bg-secondary fs-6">Showing: <?= count($attendanceLogs) ?> Records</span>
        </div>

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
                                <th>Face Snapshot</th>
                                <th>Participant / ID</th>
                                <th>Course & Section</th>
                                <th>Event ID</th>
                                <th>Check-in Time</th>
                                <th>Time Out</th>
                                <th>Status</th>
                                <th>GPS Location</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($attendanceLogs)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">No attendance records found matching the filter criteria.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($attendanceLogs as $log): 
                                    $pid = $log['participant_id'] ?? '';
                                    $profile = $profilesMap[$pid] ?? [];
                                    $courseName = $profile['course'] ?? 'ACT/HM/CT';
                                    $sectionName = $profile['section'] ?? '1A';
                                ?>
                                    <tr>
                                        <td>
                                            <?php if (!empty($log['photo_url'])): ?>
                                                <img src="<?= htmlspecialchars($log['photo_url']) ?>" alt="Face Scan" class="snapshot-thumb border" title="Hover to zoom">
                                            <?php else: ?>
                                                <span class="text-muted small">No photo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div><strong><?= htmlspecialchars(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? 'Participant')) ?></strong></div>
                                            <code class="small text-muted"><?= htmlspecialchars($pid) ?></code>
                                        </td>
                                        <td>
                                            <span class="badge bg-info text-dark"><?= htmlspecialchars(strtoupper($courseName)) ?></span>
                                            <span class="badge bg-secondary"><?= htmlspecialchars(strtoupper($sectionName)) ?></span>
                                        </td>
                                        <td><code class="small text-muted"><?= htmlspecialchars($log['event_id'] ?? 'N/A') ?></code></td>
                                        <td><?= !empty($log['check_in_time']) ? date('M j, Y, g:i A', strtotime($log['check_in_time'])) : 'N/A' ?></td>
                                        <td>
                                            <?php if (!empty($log['time_out'])): ?>
                                                <span class="text-success fw-bold"><?= date('M j, Y, g:i A', strtotime($log['time_out'])) ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">Active / Not Timed Out</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-success"><?= htmlspecialchars($log['status'] ?? 'Present') ?></span>
                                        </td>
                                        <td>
                                            <?php if (!empty($log['latitude']) && !empty($log['longitude'])): ?>
                                                <a href="https://www.google.com/maps?q=<?= $log['latitude'] ?>,<?= $log['longitude'] ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-geo-alt-fill me-1"></i> View Map
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted small">No GPS</span>
                                            <?php endif; ?>
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
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>