<?php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

// Ensure user is logged in as an Admin
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role']) !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Fetch counts for dashboard stats from Supabase
$eventsRes = supabaseRequest('events?select=id', 'GET');
$totalEvents = ($eventsRes['code'] === 200 && is_array($eventsRes['data'])) ? count($eventsRes['data']) : 0;

$attendanceRes = supabaseRequest('attendance?select=id', 'GET');
$totalCheckIns = ($attendanceRes['code'] === 200 && is_array($attendanceRes['data'])) ? count($attendanceRes['data']) : 0;
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
        body { background-color: #f8f9fa; }
        .sidebar { min-height: 100vh; background: #343a40; color: white; }
        .sidebar a { color: #cfd2d6; text-decoration: none; display: block; padding: 10px 15px; border-radius: 4px; margin-bottom: 5px; }
        .sidebar a:hover, .sidebar a.active { background: #495057; color: white; }
        .stat-card { border: none; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-3px); }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-3 col-lg-2 sidebar p-3">
            <h4 class="text-white mb-4"><i class="bi bi-shield-lock me-2"></i>Admin Panel</h4>
            <ul class="list-unstyled">
                <li><a href="dashboard.php" class="active"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a></li>
                <li><a href="events.php"><i class="bi bi-calendar-event me-2"></i> Manage Events</a></li>
                <li><a href="attendance-logs.php"><i class="bi bi-clipboard-check me-2"></i> Attendance Logs</a></li>
                <li><hr class="text-secondary"></li>
                <li><a href="../logout.php" class="text-danger"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="col-md-9 col-lg-10 p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="bi bi-speedometer2 text-primary me-2"></i> Welcome, Admin</h2>
                <span class="text-muted small"><?= date('F j, Y') ?></span>
            </div>

            <!-- Stats Row -->
            <div class="row g-4 mb-5">
                <div class="col-md-6">
                    <div class="card stat-card bg-white p-4">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted text-uppercase small fw-bold">Total Events</h6>
                                <h2 class="fw-bold mb-0 text-primary"><?= $totalEvents ?></h2>
                            </div>
                            <div class="fs-1 text-primary opacity-50"><i class="bi bi-calendar-event"></i></div>
                        </div>
                        <div class="mt-3">
                            <a href="events.php" class="text-decoration-none small fw-bold">Manage Events &rarr;</a>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card stat-card bg-white p-4">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted text-uppercase small fw-bold">Total Check-Ins</h6>
                                <h2 class="fw-bold mb-0 text-success"><?= $totalCheckIns ?></h2>
                            </div>
                            <div class="fs-1 text-success opacity-50"><i class="bi bi-clipboard-check"></i></div>
                        </div>
                        <div class="mt-3">
                            <a href="attendance-logs.php" class="text-decoration-none small fw-bold text-success">View Face Scans & Logs &rarr;</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions Section -->
            <div class="card border-0 shadow-sm p-4 rounded-3">
                <h5 class="mb-3"><i class="bi bi-lightning-charge text-warning me-2"></i> Quick System Overview</h5>
                <p class="text-muted mb-0">
                    Your participant face-scan check-in system is fully operational. Participants can check in using their camera and GPS coordinates, storing records instantly into Supabase with live photo snapshots and location mapping. Use the sidebar to inspect logs or set up new events.
                </p>
            </div>
        </div>
    </div>
</div>

</body>
</html>
