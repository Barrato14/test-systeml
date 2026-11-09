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

// Fetch event dates so we can count only events that have not ended.
$eventsResponse = supabaseRequest('events?select=start_datetime,end_datetime', 'GET');
$activeEvents = 0;

if (
    is_array($eventsResponse) &&
    isset($eventsResponse['data']) &&
    is_array($eventsResponse['data'])
) {
    $manilaTimezone = new DateTimeZone('Asia/Manila');
    $now = new DateTime('now', $manilaTimezone);

    foreach ($eventsResponse['data'] as $event) {
        if (empty($event['end_datetime'])) {
            // Cannot determine whether an event is still active without its end date.
            continue;
        }

        try {
            $eventEnd = new DateTime($event['end_datetime']);
            $eventEnd->setTimezone($manilaTimezone);

            // Count upcoming and ongoing events; exclude events that have ended.
            if ($eventEnd >= $now) {
                $activeEvents++;
            }
        } catch (Exception $e) {
            // Ignore records with invalid end_datetime values.
            continue;
        }
    }
}

// Fetch total attendance check-ins
$attendanceResponse = supabaseRequest('attendance?select=id', 'GET');
$totalCheckIns = (
    is_array($attendanceResponse) &&
    isset($attendanceResponse['data']) &&
    is_array($attendanceResponse['data'])
) ? count($attendanceResponse['data']) : 0;

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
</head>
<body class="admin-portal">

<div class="d-flex">
    <!-- Sidebar Navigation -->
    <div class="sidebar flex-shrink-0" style="width: 250px;">
        <div class="p-4 text-center border-bottom border-secondary">
            <h4 class="mb-0">EMIS Admin</h4>
            <small class="text-muted">Command Center</small>
        </div>
        <nav class="mt-3">
            <a href="dashboard.php" class="active"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a>
            <a href="events.php"><i class="bi bi-calendar-event me-2"></i> Event Management</a>
            <a href="equipment.php"><i class="bi bi-tools me-2"></i> Equipment & Services</a>
            <a href="users.php"><i class="bi bi-people me-2"></i> Users & Roles</a>
            <a href="reports.php"><i class="bi bi-bar-chart me-2"></i> Reports & Audits</a>
            <a href="attendance-logs.php"><i class="bi bi-person-bounding-box me-2"></i> Attendance Logs</a>
            <a href="../logout.php" class="text-danger mt-5"><i class="bi bi-box-arrow-right me-2"></i> Logout</a>
        </nav>
    </div>

    <!-- Main Content Area -->
    <div class="content-area flex-grow-1">

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

        <!-- Quick Actions & Recent Activity -->
        <div class="row">
            <div class="col-md-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">Recent System Activity</h5>
                    </div>
                    <div class="card-body text-center text-muted py-5">
                        <i class="bi bi-activity display-4 d-block mb-3"></i>
                        <p>No recent activity logs found. As you create events and participants check in, logs will appear here.</p>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-3">
                            <a href="events.php?action=new" class="btn btn-outline-primary text-start">
                                <i class="bi bi-plus-circle me-2"></i> Create New Event
                            </a>
                            <a href="attendance-logs.php" class="btn btn-outline-success text-start">
                                <i class="bi bi-person-bounding-box me-2"></i> View Face & GPS Attendance
                            </a>
                            <a href="equipment.php?action=approve" class="btn btn-outline-warning text-start">
                                <i class="bi bi-tools me-2"></i> Review Borrowing Requests
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
