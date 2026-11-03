<?php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

// Ensure user is logged in as an Admin
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role']) !== 'admin') {
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
    <title>Attendance Logs - Admin EMIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8f9fa; }
        .sidebar { min-height: 100vh; background: #343a40; color: white; }
        .sidebar a { color: #cfd2d6; text-decoration: none; display: block; padding: 10px 15px; border-radius: 4px; margin-bottom: 5px; }
        .sidebar a:hover, .sidebar a.active { background: #495057; color: white; }
        .table-container { background: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .snapshot-thumb { width: 50px; height: 50px; object-fit: cover; border-radius: 4px; cursor: pointer; transition: transform 0.2s; }
        .snapshot-thumb:hover { transform: scale(2.5); }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 col-lg-2 sidebar p-3">
            <h4 class="text-white mb-4"><i class="bi bi-shield-lock me-2"></i>Admin Panel</h4>
            <ul class="list-unstyled">
                <li><a href="dashboard.php"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a></li>
                <li><a href="events.php"><i class="bi bi-calendar-event me-2"></i> Manage Events</a></li>
                <li><a href="attendance-logs.php" class="active"><i class="bi bi-clipboard-check me-2"></i> Attendance Logs</a></li>
                <li><hr class="text-secondary"></li>
                <li><a href="../logout.php" class="text-danger"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="col-md-9 col-lg-10 p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="bi bi-clipboard-check text-primary me-2"></i> Event Attendance Logs</h2>
                <span class="badge bg-secondary">Total Check-ins: <?= count($attendanceLogs) ?></span>
            </div>

            <div class="table-container">
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

</body>
</html>