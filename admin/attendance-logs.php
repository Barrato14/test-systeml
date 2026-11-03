<?php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

// Ensure user is logged in as an Administrator
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role']) !== 'administrator') {
    header("Location: ../login.php");
    exit;
}

// Fetch attendance records from Supabase
$response = supabaseRequest('attendance?select=*&order=check_in_time.desc', 'GET');
$attendanceLogs = ($response['code'] === 200 && is_array($response['data'])) ? $response['data'] : [];
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
            <h2><i class="bi bi-person-bounding-box text-primary me-2"></i> Event Attendance Logs</h2>
            <span class="badge bg-secondary fs-6">Total Check-ins: <?= count($attendanceLogs) ?></span>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Face Snapshot</th>
                                <th>Participant ID</th>
                                <th>Event ID</th>
                                <th>Check-in Time</th>
                                <th>Status</th>
                                <th>GPS Location</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($attendanceLogs)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">No attendance records found yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($attendanceLogs as $log): ?>
                                    <tr>
                                        <td>
                                            <?php if (!empty($log['photo_url'])): ?>
                                                <img src="<?= htmlspecialchars($log['photo_url']) ?>" alt="Face Scan" class="snapshot-thumb border" title="Hover to zoom">
                                            <?php else: ?>
                                                <span class="text-muted small">No photo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><code class="small text-muted"><?= htmlspecialchars($log['participant_id'] ?? 'N/A') ?></code></td>
                                        <td><code class="small text-muted"><?= htmlspecialchars($log['event_id'] ?? 'N/A') ?></code></td>
                                        <td><?= date('M j, Y, g:i A', strtotime($log['check_in_time'])) ?></td>
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
