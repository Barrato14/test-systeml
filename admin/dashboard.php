<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once '../config/database.php';
require_once '../includes/auth.php';

// Administrators, Event Organizers, and Staff share this dashboard.
$currentRole = strtolower(trim((string)($_SESSION['user_role'] ?? '')));

if (
    !isset($_SESSION['user_id']) ||
    !in_array($currentRole, ['administrator', 'event organizer', 'staff'], true)
) {
    header("Location: ../login.php");
    exit;
}

$isEventOrganizer = $currentRole === 'event organizer';
$isStaff = $currentRole === 'staff';

// ============================================================
// TOTAL USERS
// ============================================================

$serviceKey = getenv('SUPABASE_SERVICE_ROLE_KEY')
    ?: ($env['SUPABASE_SERVICE_ROLE_KEY'] ?? '');

$totalUserAccounts = [];

if ($serviceKey !== '' && !empty($supabaseUrl)) {

    $fetchAdminJson = function ($url) use ($serviceKey) {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'apikey: ' . $serviceKey,
                'Authorization: Bearer ' . $serviceKey,
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 20
        ]);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            error_log(
                'EMIS Total Users Supabase request failed. HTTP status: '
                . $status
                . '; cURL error: '
                . ($curlError !== '' ? $curlError : 'none')
                . '; endpoint: '
                . (parse_url($url, PHP_URL_PATH) ?: 'unknown')
                . '; response: '
                . substr((string)$response, 0, 1000)
            );

            return null;
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            error_log(
                'EMIS Total Users Supabase response was not valid JSON.'
            );

            return null;
        }

        return $decoded;
    };

    $profileRowsFetched = 0;
    $eligibleParticipants = 0;
    $eligibleStaffAndOrganizers = 0;

    for ($offset = 0; ; $offset += 1000) {

        $profilesUrl = rtrim($supabaseUrl, '/')
            . '/rest/v1/profiles?select=id,email,first_name,last_name,role,is_verified'
            . '&order=id.asc&limit=1000&offset=' . $offset;

        $profileRows = $fetchAdminJson($profilesUrl);

        if (!is_array($profileRows)) {
            break;
        }

        $profileRowsFetched += count($profileRows);

        foreach ($profileRows as $profileRow) {

            $emailKey = strtolower(trim((string)($profileRow['email'] ?? '')));
            $accountId = trim((string)($profileRow['id'] ?? ''));
            $firstName = trim((string)($profileRow['first_name'] ?? ''));
            $lastName = trim((string)($profileRow['last_name'] ?? ''));

            $role = strtolower(trim((string)($profileRow['role'] ?? '')));
            $role = str_replace(['_', '-'], ' ', $role);

            if ($firstName === '' || $lastName === '') {
                continue;
            }

            $isVerified = in_array(
                strtolower(trim((string)($profileRow['is_verified'] ?? 'false'))),
                ['true', '1', 't', 'yes'],
                true
            );

            $isEligible = false;

            if ($role === 'participant' && $isVerified) {
                $isEligible = true;
                $eligibleParticipants++;
            } elseif (in_array($role, ['staff', 'event organizer'], true)) {
                $isEligible = true;
                $eligibleStaffAndOrganizers++;
            }

            if (!$isEligible) {
                continue;
            }

            if ($emailKey !== '') {
                $totalUserAccounts['email:' . $emailKey] = true;
            } elseif ($accountId !== '') {
                $totalUserAccounts['profile:' . $accountId] = true;
            }
        }

        if (count($profileRows) < 1000) {
            break;
        }
    }

    $totalUsers = count($totalUserAccounts);

    error_log(
        'EMIS Total Users counts: profiles fetched=' . $profileRowsFetched
        . ', verified participants with complete names=' . $eligibleParticipants
        . ', staff and event organizers with complete names=' . $eligibleStaffAndOrganizers
        . ', eligible unique accounts counted=' . $totalUsers
    );

} else {

    // Fallback for local setups.
    $totalUsersResponse = supabaseRequest(
        'profiles?select=id,email,first_name,last_name,role,is_verified&limit=1000',
        'GET'
    );

    $fallbackAccounts = [];

    if (is_array($totalUsersResponse['data'] ?? null)) {

        foreach ($totalUsersResponse['data'] as $profileRow) {

            $firstName = trim((string)($profileRow['first_name'] ?? ''));
            $lastName = trim((string)($profileRow['last_name'] ?? ''));

            if ($firstName === '' || $lastName === '') {
                continue;
            }

            $role = strtolower(
                str_replace(
                    ['_', '-'],
                    ' ',
                    trim((string)($profileRow['role'] ?? ''))
                )
            );

            $isVerified = in_array(
                strtolower(trim((string)($profileRow['is_verified'] ?? 'false'))),
                ['true', '1', 't', 'yes'],
                true
            );

            if (
                ($role === 'participant' && $isVerified) ||
                in_array($role, ['staff', 'event organizer'], true)
            ) {

                $emailKey = strtolower(trim((string)($profileRow['email'] ?? '')));
                $accountId = trim((string)($profileRow['id'] ?? ''));

                if ($emailKey !== '') {
                    $fallbackAccounts['email:' . $emailKey] = true;
                } elseif ($accountId !== '') {
                    $fallbackAccounts['profile:' . $accountId] = true;
                }
            }
        }
    }

    $totalUsers = count($fallbackAccounts);
}


// ============================================================
// FETCH ALL EVENTS
// Administrators, Organizers, and Staff can see all events.
// ============================================================

$manilaTimezone = new DateTimeZone('Asia/Manila');
$now = new DateTimeImmutable('now', $manilaTimezone);

$eventsQuery = 'events?select=id,title,start_datetime,end_datetime,venue,capacity&order=start_datetime.asc';

$eventsResponse = supabaseRequest($eventsQuery, 'GET');

$ongoingEvents = [];
$ongoingEventMap = [];
$activeEvents = 0;

if (is_array($eventsResponse['data'] ?? null)) {

    foreach ($eventsResponse['data'] as $event) {

        if (
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

            // Upcoming and ongoing events count as active.
            if ($eventEnd >= $now) {
                $activeEvents++;
            }

            // Only currently ongoing events appear in the activity panel.
            if ($eventStart <= $now && $eventEnd >= $now) {

                $ongoingEvents[] = $event;

                $ongoingEventMap[(string)$event['id']] = $event;
            }

        } catch (Exception $e) {
            continue;
        }
    }
}


// ============================================================
// ORGANIZER: CALCULATE LIVE VENUE OCCUPANCY
// ADMIN AND STAFF: KEEP EXISTING ATTENDANCE VIEW
// ============================================================

$occupancyByEvent = [];
$recentAttendance = [];
$profilesMap = [];
$selectedEventId = '';
$selectedEventTitle = '';

$attendanceResponse = ['data' => []];

if ($isEventOrganizer) {

    // Initialize each ongoing event's occupancy to zero.
    foreach ($ongoingEventMap as $eventId => $event) {
        $occupancyByEvent[$eventId] = 0;
    }

    // Fetch attendance records for ongoing events.
    if (!empty($ongoingEventMap)) {

        $eventIds = array_map(
            'rawurlencode',
            array_keys($ongoingEventMap)
        );

        $eventFilter = 'event_id=in.(' . implode(',', $eventIds) . ')';

        $occupancyResponse = supabaseRequest(
            'attendance?select=id,event_id,participant_id,check_in_time,time_out&'
            . $eventFilter
            . '&limit=1000',
            'GET'
        );

        if (is_array($occupancyResponse['data'] ?? null)) {

            foreach ($occupancyResponse['data'] as $record) {

                $eventId = (string)($record['event_id'] ?? '');

                // Count only students who have checked in
                // and have not checked out.
                if (
                    isset($occupancyByEvent[$eventId]) &&
                    !empty($record['check_in_time']) &&
                    empty($record['time_out'])
                ) {
                    $occupancyByEvent[$eventId]++;
                }
            }
        }
    }

    $totalCurrentOccupancy = array_sum($occupancyByEvent);

} else {

    // ========================================================
    // ADMINISTRATOR AND STAFF ATTENDANCE VIEW
    // Keep the existing attendance behavior.
    // ========================================================

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

    // Fetch profiles to display participant names.
    $profilesResponse = supabaseRequest(
        'profiles?select=id,first_name,last_name,course,section',
        'GET'
    );

    if (is_array($profilesResponse['data'] ?? null)) {

        foreach ($profilesResponse['data'] as $profile) {

            $profilesMap[(string)($profile['id'] ?? '')] = $profile;
        }
    }

    // Prepare recent attendance activity.
    if (is_array($attendanceResponse['data'] ?? null)) {

        foreach ($attendanceResponse['data'] as $attendance) {

            $eventId = (string)($attendance['event_id'] ?? '');

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

    // Filter attendance using the selected event ID.
    $selectedEventId = trim((string)($_GET['event_id'] ?? ''));

    if (
        $selectedEventId !== '' &&
        isset($ongoingEventMap[$selectedEventId])
    ) {

        $selectedEventTitle =
            (string)($ongoingEventMap[$selectedEventId]['title'] ?? 'Selected event');

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
}


// ============================================================
// FORMAT ACTIVITY TIME
// ============================================================

function formatDashboardActivityTime($value) {

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


// ============================================================
// TOTAL CHECK-INS FOR ADMIN AND STAFF
// ============================================================

$totalCheckIns = 0;

if (!$isEventOrganizer && !empty($ongoingEventMap)) {

    $activeEventIds = array_map(
        'rawurlencode',
        array_keys($ongoingEventMap)
    );

    $activeEventFilter = 'event_id=in.(' . implode(',', $activeEventIds) . ')';

    $totalAttendanceResponse = supabaseRequest(
        'attendance?select=id&' . $activeEventFilter,
        'GET'
    );

    $totalCheckIns = is_array($totalAttendanceResponse['data'] ?? null)
        ? count($totalAttendanceResponse['data'])
        : 0;
}

$pendingEquipment = 0;

$equipmentItems = [];
$equipmentItemsMap = [];
$recentEquipmentBorrowings = [];
$pendingBorrowRequests = 0;
$currentBorrowedUnits = 0;
$totalEquipmentUnits = 0;
$availableEquipmentUnits = 0;

if (!$isEventOrganizer) {
    $equipmentItemsResponse = supabaseRequest(
        'equipment_items?select=id,name,quantity,condition_status&order=name.asc&limit=1000',
        'GET'
    );
    if (is_array($equipmentItemsResponse['data'] ?? null)) {
        $equipmentItems = $equipmentItemsResponse['data'];
        foreach ($equipmentItems as $equipmentItem) {
            $equipmentId = (string)($equipmentItem['id'] ?? '');
            if ($equipmentId !== '') $equipmentItemsMap[$equipmentId] = $equipmentItem;
            $totalEquipmentUnits += max(0, (int)($equipmentItem['quantity'] ?? 0));
        }
    }

    $borrowRequestsResponse = supabaseRequest(
        'equipment_borrow_requests?select=id,equipment_id,participant_id,quantity,status,created_at,borrow_date,returned_at&order=created_at.desc&limit=1000',
        'GET'
    );
    $borrowRequests = is_array($borrowRequestsResponse['data'] ?? null)
        ? $borrowRequestsResponse['data'] : [];
    $borrowedUnitsByEquipment = [];

    foreach ($borrowRequests as $borrowRequest) {
        $requestStatus = (string)($borrowRequest['status'] ?? '');
        $equipmentId = (string)($borrowRequest['equipment_id'] ?? '');
        $quantity = max(0, (int)($borrowRequest['quantity'] ?? 0));

        if ($requestStatus === 'Pending') {
            $pendingBorrowRequests++;
        } elseif ($requestStatus === 'Approved') {
            $currentBorrowedUnits += $quantity;
            $borrowedUnitsByEquipment[$equipmentId] = ($borrowedUnitsByEquipment[$equipmentId] ?? 0) + $quantity;
        }

        if (!in_array($requestStatus, ['Approved', 'Returned'], true)) continue;

        $profileId = (string)($borrowRequest['participant_id'] ?? '');
        $borrowerProfile = $profilesMap[$profileId] ?? [];
        $equipmentItem = $equipmentItemsMap[$equipmentId] ?? [];
        $borrowRequest['borrower_name'] = trim(
            (string)($borrowerProfile['first_name'] ?? '') . ' ' .
            (string)($borrowerProfile['last_name'] ?? '')
        ) ?: 'Participant';
        $borrowRequest['equipment_name'] = (string)($equipmentItem['name'] ?? 'Equipment item');
        $borrowRequest['activity_time'] = $requestStatus === 'Returned'
            ? ($borrowRequest['returned_at'] ?? $borrowRequest['created_at'] ?? '')
            : ($borrowRequest['created_at'] ?? $borrowRequest['borrow_date'] ?? '');
        $recentEquipmentBorrowings[] = $borrowRequest;
    }

    usort($recentEquipmentBorrowings, function ($a, $b) {
        return (strtotime((string)($b['activity_time'] ?? '')) ?: 0)
            <=> (strtotime((string)($a['activity_time'] ?? '')) ?: 0);
    });
    $recentEquipmentBorrowings = array_slice($recentEquipmentBorrowings, 0, 8);

    foreach ($equipmentItems as $equipmentItem) {
        $equipmentId = (string)($equipmentItem['id'] ?? '');
        $quantity = max(0, (int)($equipmentItem['quantity'] ?? 0));
        if (($equipmentItem['condition_status'] ?? 'Available') === 'Available') {
            $availableEquipmentUnits += max(0, $quantity - ($borrowedUnitsByEquipment[$equipmentId] ?? 0));
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= $isEventOrganizer ? 'Event Organizer Dashboard - EMIS' : ($isStaff ? 'Staff Dashboard - EMIS' : 'Admin Dashboard - EMIS') ?>
    </title>

    <?php if ($isEventOrganizer || $isStaff): ?>
        <meta http-equiv="refresh" content="30">
    <?php endif; ?>

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
    >

    <link rel="stylesheet" href="../css/portal-theme.css?v=20261114-1">

    <link
        rel="stylesheet"
        href="../css/weather-widget.css?v=20261113-2"
    >

</head>

<body class="admin-portal">

<?php require_once __DIR__ . '/../includes/admin-nav.php'; ?>

<main class="content-area admin-main-content">

    <div class="d-flex justify-content-between align-items-center mb-4">

        <h2 class="mb-0">
            <?= $isEventOrganizer ? 'Event Organizer Overview' : ($isStaff ? 'Staff Overview' : 'Dashboard Overview') ?>
        </h2>

        <div class="d-flex align-items-center">

            <span class="me-3">
                Welcome,
                <strong>
                    <?= htmlspecialchars(
                        $_SESSION['user_name'] ??
                        ($isEventOrganizer ? 'Event Organizer' : 'Admin')
                    ) ?>
                </strong>
            </span>

        </div>

    </div>

    <?php include __DIR__ . '/../includes/weather-widget.php'; ?>


    <!-- ROLE-SPECIFIC DASHBOARD METRICS -->
    <div class="row mb-4">
        <?php if ($isStaff): ?>
            <div class="col-sm-6 col-xl-3"><div class="card stat-card shadow-sm mb-3"><div class="card-body"><h6 class="text-muted text-uppercase mb-2">Equipment Types</h6><h2 class="mb-0"><?= count($equipmentItems) ?></h2></div></div></div>
            <div class="col-sm-6 col-xl-3"><div class="card stat-card shadow-sm mb-3" style="border-left-color:#198754;"><div class="card-body"><h6 class="text-muted text-uppercase mb-2">Available Units</h6><h2 class="mb-0"><?= $availableEquipmentUnits ?></h2></div></div></div>
            <div class="col-sm-6 col-xl-3"><div class="card stat-card shadow-sm mb-3" style="border-left-color:#ffc107;"><div class="card-body"><h6 class="text-muted text-uppercase mb-2">Pending Borrow Requests</h6><h2 class="mb-0"><?= $pendingBorrowRequests ?></h2></div></div></div>
            <div class="col-sm-6 col-xl-3"><div class="card stat-card shadow-sm mb-3" style="border-left-color:#0dcaf0;"><div class="card-body"><h6 class="text-muted text-uppercase mb-2">Currently Borrowed Units</h6><h2 class="mb-0"><?= $currentBorrowedUnits ?></h2></div></div></div>
        <?php else: ?>
            <div class="col-md-3"><div class="card stat-card shadow-sm mb-3"><div class="card-body"><h6 class="text-muted text-uppercase mb-2">Total Users</h6><h2 class="mb-0"><?= $totalUsers ?></h2></div></div></div>
            <div class="col-md-3"><div class="card stat-card shadow-sm mb-3" style="border-left-color:#198754;"><div class="card-body"><h6 class="text-muted text-uppercase mb-2">Active Events</h6><h2 class="mb-0"><?= $activeEvents ?></h2></div></div></div>
            <div class="col-md-3"><div class="card stat-card shadow-sm mb-3" style="border-left-color:#ffc107;"><div class="card-body"><h6 class="text-muted text-uppercase mb-2">Pending Equipment</h6><h2 class="mb-0"><?= $pendingEquipment ?></h2></div></div></div>
            <div class="col-md-3"><div class="card stat-card shadow-sm mb-3" style="border-left-color:#dc3545;"><div class="card-body"><h6 class="text-muted text-uppercase mb-2">Active Event Check-Ins</h6><h2 class="mb-0"><?= $totalCheckIns ?></h2></div></div></div>
        <?php endif; ?>
    </div>


    <!-- ====================================================
         RECENT ACTIVITY / VENUE OCCUPANCY AND QUICK ACTIONS
         ==================================================== -->

    <div class="row g-4">

        <div class="col-lg-8">


            <?php if ($isEventOrganizer): ?>

                <!-- =========================================
                     EVENT ORGANIZER: LIVE VENUE OCCUPANCY
                     ========================================= -->

                <section class="card shadow-sm admin-activity-card">

                    <div class="card-header">

                        <div>

                            <h5 class="mb-1">
                                Live Venue Occupancy
                            </h5>

                            <p class="small text-muted mb-0">
                                Students currently inside ongoing event venues.
                            </p>

                        </div>

                    </div>


                    <div class="card-body admin-activity-body">

                        <?php if (empty($ongoingEvents)): ?>

                            <div class="admin-activity-empty">

                                <i class="bi bi-calendar2-x"></i>

                                <strong>
                                    No events are ongoing right now
                                </strong>

                                <span>
                                    Venue occupancy will appear when an event begins.
                                </span>

                            </div>

                        <?php else: ?>

                            <div class="admin-activity-list">

                                <?php foreach ($ongoingEvents as $event): ?>

                                    <?php

                                    $eventId = (string)($event['id'] ?? '');

                                    $inside = $occupancyByEvent[$eventId] ?? 0;

                                    $capacity = max(
                                        0,
                                        (int)($event['capacity'] ?? 0)
                                    );

                                    $percentage = $capacity > 0
                                        ? min(100, ($inside / $capacity) * 100)
                                        : 0;

                                    ?>

                                    <article class="admin-activity-item">

                                        <span class="admin-activity-icon event">

                                            <i class="bi bi-people-fill"></i>

                                        </span>


                                        <div class="admin-activity-copy">

                                            <strong>
                                                <?= htmlspecialchars(
                                                    $event['title'] ?? 'Untitled event'
                                                ) ?>
                                            </strong>

                                            <span>

                                                <i class="bi bi-geo-alt"></i>

                                                <?= htmlspecialchars(
                                                    $event['venue'] ?? 'Venue not specified'
                                                ) ?>

                                            </span>


                                            <div class="mt-2">

                                                <strong>
                                                    <?= $inside ?>

                                                    <?php if ($capacity > 0): ?>
                                                        / <?= $capacity ?>
                                                    <?php endif; ?>
                                                </strong>

                                                <small class="text-muted">
                                                    students currently inside
                                                </small>

                                            </div>


                                            <?php if ($capacity > 0): ?>

                                                <div
                                                    class="progress mt-2"
                                                    style="height: 7px;"
                                                >

                                                    <div
                                                        class="progress-bar bg-success"
                                                        role="progressbar"
                                                        style="width: <?= $percentage ?>%;"
                                                        aria-valuenow="<?= round($percentage) ?>"
                                                        aria-valuemin="0"
                                                        aria-valuemax="100"
                                                    ></div>

                                                </div>

                                            <?php endif; ?>

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

                                View all events

                                <i class="bi bi-arrow-up-right"></i>

                            </a>

                        </div>

                    </div>

                </section>


            <?php else: ?>


                <!-- =========================================
                     ADMINISTRATOR AND STAFF:
                     ORIGINAL ONGOING EVENTS AND ATTENDANCE
                     ========================================= -->

                <section class="card shadow-sm admin-activity-card">

                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">

                        <div>

                            <h5 class="mb-1">
                                Recent Activity
                            </h5>

                            <p class="small text-muted mb-0">
                                Activity from events that are currently ongoing.
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

                                Ongoing Events

                                <span>
                                    <?= count($ongoingEvents) ?>
                                </span>

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

                                <span>
                                    <?= count($recentAttendance) ?>
                                </span>

                            </button>

                            <button type="button" class="admin-activity-tab" id="equipmentBorrowingTab" role="tab" aria-selected="false" aria-controls="equipmentBorrowingPanel" data-activity-tab="equipment">
                                <i class="bi bi-tools"></i>
                                Equipment Borrowing
                                <span><?= count($recentEquipmentBorrowings) ?></span>
                            </button>

                        </div>

                    </div>


                    <div class="card-body admin-activity-body">


                        <!-- ONGOING EVENTS TAB -->

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
                                        No events are ongoing right now
                                    </strong>

                                    <span>
                                        Upcoming and completed events are excluded from this view.
                                    </span>

                                </div>

                            <?php else: ?>

                                <div class="admin-activity-list">

                                    <?php foreach (array_slice($ongoingEvents, 0, 8) as $event): ?>

                                        <article
                                            class="admin-activity-item"
                                            data-event-id="<?= htmlspecialchars(
                                                (string)($event['id'] ?? ''),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>"
                                            role="button"
                                            tabindex="0"
                                            style="cursor:pointer"
                                        >

                                            <span class="admin-activity-icon event">

                                                <i class="bi bi-calendar-event"></i>

                                            </span>


                                            <div class="admin-activity-copy">

                                                <strong>
                                                    <?= htmlspecialchars(
                                                        $event['title'] ?? 'Untitled event'
                                                    ) ?>
                                                </strong>

                                                <span>
                                                    <?= htmlspecialchars(
                                                        $event['venue'] ?? 'Venue not specified'
                                                    ) ?>
                                                </span>

                                                <small>
                                                    Started
                                                    <?= htmlspecialchars(
                                                        formatDashboardActivityTime(
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

                                    Manage events

                                    <i class="bi bi-arrow-up-right"></i>

                                </a>

                            </div>

                        </div>


                        <!-- ATTENDANCE TAB -->

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
                                            : 'Check-ins for currently ongoing events will appear here.'
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
                                                    <?= htmlspecialchars(
                                                        $record['participant_name']
                                                    ) ?>
                                                </strong>

                                                <span>
                                                    Checked in to
                                                    <?= htmlspecialchars(
                                                        $record['event_title']
                                                    ) ?>
                                                </span>

                                                <small>

                                                    <?= htmlspecialchars(
                                                        formatDashboardActivityTime(
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
                                                                ($record['participant_course'] ?? '')
                                                                . ' '
                                                                . ($record['participant_section'] ?? '')
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

                        <div class="admin-activity-panel" id="equipmentBorrowingPanel" role="tabpanel" aria-labelledby="equipmentBorrowingTab" data-activity-panel="equipment" hidden>
                            <?php if (empty($recentEquipmentBorrowings)): ?>
                                <div class="admin-activity-empty">
                                    <i class="bi bi-tools"></i>
                                    <strong>No equipment borrowing activity yet</strong>
                                    <span>Approved borrowings and completed returns will appear here.</span>
                                </div>
                            <?php else: ?>
                                <div class="admin-activity-list">
                                    <?php foreach ($recentEquipmentBorrowings as $borrowActivity): ?>
                                        <article class="admin-activity-item">
                                            <span class="admin-activity-icon event"><i class="bi bi-box-seam"></i></span>
                                            <div class="admin-activity-copy">
                                                <strong><?= htmlspecialchars($borrowActivity['borrower_name']) ?></strong>
                                                <span><?= ($borrowActivity['status'] ?? '') === 'Returned' ? 'Returned' : 'Borrowed' ?> <?= htmlspecialchars($borrowActivity['equipment_name']) ?> · <?= (int)($borrowActivity['quantity'] ?? 0) ?> unit(s)</span>
                                                <small><?= htmlspecialchars(formatDashboardActivityTime($borrowActivity['activity_time'] ?? '')) ?></small>
                                            </div>
                                            <span class="admin-attendance-label"><?= htmlspecialchars($borrowActivity['status'] ?? 'Approved') ?></span>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <div class="admin-activity-footer">
                                <a href="equipment.php?tab=history">View borrowing history <i class="bi bi-arrow-up-right"></i></a>
                            </div>
                        </div>

                    </div>

                </section>

            <?php endif; ?>

        </div>


        <!-- =================================================
             QUICK ACTIONS
             ================================================= -->

        <div class="col-lg-4">

            <div class="card shadow-sm h-100">

                <div class="card-header">

                    <h5 class="mb-0">
                        Quick Actions
                    </h5>

                </div>


                <div class="card-body">

                    <div class="d-grid gap-3">
                        <?php if ($isStaff): ?>
                            <a href="equipment.php?tab=requests" class="btn btn-outline-warning text-start"><i class="bi bi-inbox me-2"></i>Review Equipment Requests</a>
                            <a href="equipment.php?tab=history" class="btn btn-outline-primary text-start"><i class="bi bi-clock-history me-2"></i>View Borrowing History</a>
                            <a href="attendance-logs.php" class="btn btn-outline-success text-start"><i class="bi bi-person-bounding-box me-2"></i>View Attendance Logs</a>
                        <?php else: ?>
                            <a href="events.php?action=new" class="btn btn-outline-primary text-start"><i class="bi bi-plus-circle me-2"></i><?= $isEventOrganizer ? 'Create Event' : 'Create New Event' ?></a>
                            <a href="attendance-logs.php" class="btn btn-outline-success text-start"><i class="bi bi-person-bounding-box me-2"></i>View Face &amp; GPS Attendance</a>
                            <a href="equipment.php?action=approve" class="btn btn-outline-warning text-start"><i class="bi bi-tools me-2"></i>Review Borrowing Requests</a>
                        <?php endif; ?>
                    </div>                    </div>

                </div>

            </div>

        </div>

    </div>

</main>


<script src="../js/weather-widget.js" defer></script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>


<?php if (!$isEventOrganizer): ?>

<script>

// ============================================================
// ADMINISTRATOR AND STAFF ACTIVITY TABS
// ============================================================

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

            // Leaving the event-specific Attendance view
            // clears the URL filter.

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


    // Swipe support for mobile devices.

    const activityCard = document.querySelector(
        '.admin-activity-card'
    );

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

            const deltaX =
                event.changedTouches[0].clientX - touchStartX;

            const deltaY =
                event.changedTouches[0].clientY - touchStartY;

            if (
                Math.abs(deltaX) < 55 ||
                Math.abs(deltaX) < Math.abs(deltaY)
            ) {
                return;
            }

            const current = tabs.findIndex(function (tab) {

                return tab.classList.contains('is-active');

            });

            const next = deltaX < 0
                ? current + 1
                : current - 1;

            if (next >= 0 && next < tabs.length) {

                showActivity(
                    tabs[next].dataset.activityTab
                );

            }

        }, { passive: true });

    }

})();


// ============================================================
// EVENT CARD CLICKING
// ============================================================

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


    // Open the Attendance tab automatically
    // when an event ID is present in the URL.

    const selectedEventId = new URLSearchParams(
        window.location.search
    ).get('event_id');


    if (selectedEventId) {

        const attendanceTab = document.querySelector(
            '#attendanceTab'
        );

        if (attendanceTab) {
            attendanceTab.click();
        }

    }

})();

</script>

<?php endif; ?>


</body>
</html>
