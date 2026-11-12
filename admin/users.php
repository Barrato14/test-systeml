<?php
date_default_timezone_set('Asia/Manila');


require_once '../config/database.php';
require_once '../includes/auth.php';

/* =========================================================
   1. ADMINISTRATOR ACCESS
========================================================= */

$currentRole = strtolower(trim($_SESSION['user_role'] ?? ''));

if (
    !isset($_SESSION['user_id']) ||
    $currentRole !== 'administrator'
) {
    header('Location: ../login.php');
    exit;
}

/* =========================================================
   2. CSRF PROTECTION
========================================================= */

if (empty($_SESSION['users_csrf'])) {
    $_SESSION['users_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['users_csrf'];

/* =========================================================
   3. SUPABASE SERVICE KEY
========================================================= */

$serviceKey = getenv('SUPABASE_SERVICE_ROLE_KEY')
    ?: ($env['SUPABASE_SERVICE_ROLE_KEY'] ?? '');

$successMsg = '';
$errorMsg = '';

/* =========================================================
   4. HELPER FUNCTIONS
========================================================= */

function userH($value)
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function userIsActive($value)
{
    if ($value === null) {
        return true;
    }

    return !(
        $value === false ||
        $value === 0 ||
        $value === '0' ||
        $value === 'false'
    );
}

function userAccountIsActive($user)
{
    $role = strtolower(trim((string)($user['role'] ?? '')));
    $isActive = userIsActive($user['is_active'] ?? null);

    if ($role === 'participant') {
        $isVerified = isset($user['is_verified'])
            && $user['is_verified'] !== null
            && userIsActive($user['is_verified']);

        return $isActive && $isVerified;
    }

    return $isActive;
}

function usersApiRequest($endpoint, $method = 'GET', $payload = null)
{
    global $supabaseUrl, $serviceKey;

    if (empty($serviceKey)) {
        throw new Exception(
            'SUPABASE_SERVICE_ROLE_KEY is missing from the server environment.'
        );
    }

    if (empty($supabaseUrl)) {
        throw new Exception('Supabase URL is not configured.');
    }

    $url = rtrim($supabaseUrl, '/')
        . '/rest/v1/'
        . ltrim($endpoint, '/');

    $headers = [
        'apikey: ' . $serviceKey,
        'Authorization: Bearer ' . $serviceKey,
        'Content-Type: application/json',
        'Prefer: return=representation'
    ];

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30
    ]);

    if ($payload !== null) {
        curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            json_encode($payload)
        );
    }

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    if ($body === false) {
        throw new Exception(
            'Database connection failed: ' . $curlError
        );
    }

    $data = json_decode($body, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        $message = is_array($data)
            ? ($data['message'] ?? 'Database request failed.')
            : 'Database request failed.';

        throw new Exception($message);
    }

    return is_array($data) ? $data : [];
}

/* =========================================================
   5. ALLOWED ROLES
========================================================= */

$allowedRoles = [
    'Administrator',
    'Event Organizer',
    'Staff',
    'Participant'
];

/* =========================================================
   6. PROCESS ROLE AND STATUS UPDATES
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        // Verify CSRF token.
        $submittedToken = $_POST['csrf_token'] ?? '';

        if (
            !is_string($submittedToken) ||
            !hash_equals($csrfToken, $submittedToken)
        ) {
            throw new Exception(
                'Invalid security token. Refresh the page and try again.'
            );
        }

        $action = $_POST['action'] ?? '';

        if (!in_array(
            $action,
            ['update_role', 'toggle_status'],
            true
        )) {
            throw new Exception('Invalid action.');
        }

        $targetId = trim($_POST['user_id'] ?? '');

        if ($targetId === '') {
            throw new Exception('Invalid user account.');
        }

        // Prevent modifying your own account.
        if ($targetId === (string)$_SESSION['user_id']) {
            throw new Exception(
                'You cannot change your own role or account status.'
            );
        }

        // Retrieve the target account.
        $targetRows = usersApiRequest(
            'profiles?id=eq.' . rawurlencode($targetId)
            . '&select=id,role,is_active,is_verified'
        );

        if (empty($targetRows)) {
            throw new Exception('User account not found.');
        }

        $targetUser = $targetRows[0];

        $oldRole = $targetUser['role'] ?? '';
        $oldStatus = userAccountIsActive($targetUser);

        /* -----------------------------------------
           ACTION A: CHANGE ROLE
        ----------------------------------------- */

        if ($action === 'update_role') {

            $newRole = trim($_POST['new_role'] ?? '');

            if (!in_array($newRole, $allowedRoles, true)) {
                throw new Exception('Invalid role selected.');
            }

            if ($oldRole === $newRole) {
                throw new Exception(
                    'This account already has that role.'
                );
            }

            // Protect the last active administrator.
            if (
                $oldRole === 'Administrator' &&
                $newRole !== 'Administrator' &&
                $oldStatus
            ) {

                $activeAdmins = usersApiRequest(
                    'profiles?role=eq.Administrator'
                    . '&is_active=eq.true'
                    . '&select=id'
                );

                if (count($activeAdmins) <= 1) {
                    throw new Exception(
                        'You cannot demote the last active administrator.'
                    );
                }
            }

            usersApiRequest(
                'profiles?id=eq.' . rawurlencode($targetId),
                'PATCH',
                ['role' => $newRole]
            );

            $successMsg = 'User role updated successfully.';
        }

        /* -----------------------------------------
           ACTION B: ACTIVATE / DEACTIVATE
        ----------------------------------------- */

        elseif ($action === 'toggle_status') {

            $newStatus = $_POST['new_status'] ?? '';

            if (!in_array($newStatus, ['true', 'false'], true)) {
                throw new Exception('Invalid account status.');
            }

            $newActive = $newStatus === 'true';

            if ($oldStatus === $newActive) {
                throw new Exception(
                    'This account already has that status.'
                );
            }

            // Protect the last active administrator.
            if (
                $oldRole === 'Administrator' &&
                $oldStatus &&
                !$newActive
            ) {

                $activeAdmins = usersApiRequest(
                    'profiles?role=eq.Administrator'
                    . '&is_active=eq.true'
                    . '&select=id'
                );

                if (count($activeAdmins) <= 1) {
                    throw new Exception(
                        'You cannot deactivate the last active administrator.'
                    );
                }
            }

            usersApiRequest(
                'profiles?id=eq.' . rawurlencode($targetId),
                'PATCH',
                ['is_active' => $newActive]
            );

            $successMsg = $newActive
                ? 'Account activated successfully.'
                : 'Account deactivated successfully.';
        }

    } catch (Exception $e) {

        $errorMsg = $e->getMessage();
    }
}

/* =========================================================
   7. FETCH ALL USERS
========================================================= */

$users = [];
$allUsers = [];

try {

    $allUsers = usersApiRequest(
        'profiles?select=id,first_name,last_name,email,role,'
        . 'is_verified,is_active,course,section,created_at,updated_at'
        . '&order=first_name.asc&limit=1000'
    );

    $users = $allUsers;

} catch (Exception $e) {

    $errorMsg = $errorMsg ?: $e->getMessage();
}

/* =========================================================
   8. SEARCH AND FILTER
========================================================= */

$search = trim($_GET['search'] ?? '');
$filterRole = trim($_GET['role'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');

if (!in_array($filterRole, array_merge([''], $allowedRoles), true)) {
    $filterRole = '';
}

if (!in_array($filterStatus, ['', 'active', 'inactive'], true)) {
    $filterStatus = '';
}

if (
    $search !== '' ||
    $filterRole !== '' ||
    $filterStatus !== ''
) {

    $users = array_values(array_filter(
        $allUsers,
        function ($user) use (
            $search,
            $filterRole,
            $filterStatus
        ) {

            $name = trim(
                ($user['first_name'] ?? '') . ' ' .
                ($user['last_name'] ?? '')
            );

            $email = $user['email'] ?? '';
            $role = $user['role'] ?? '';

            $active = userAccountIsActive($user);

            $matchesSearch = (
                $search === '' ||
                stripos($name, $search) !== false ||
                stripos($email, $search) !== false
            );

            $matchesRole = (
                $filterRole === '' ||
                $role === $filterRole
            );

            $matchesStatus = (
                $filterStatus === '' ||
                ($filterStatus === 'active' && $active) ||
                ($filterStatus === 'inactive' && !$active)
            );

            return (
                $matchesSearch &&
                $matchesRole &&
                $matchesStatus
            );
        }
    ));
}

/* =========================================================
   9. DASHBOARD COUNTS
========================================================= */

$roleCounts = array_fill_keys($allowedRoles, 0);

$activeCount = 0;
$inactiveCount = 0;

foreach ($allUsers as $account) {

    $role = $account['role'] ?? '';
    $isActive = userAccountIsActive($account);
    $isVerified = isset($account['is_verified'])
        && $account['is_verified'] !== null
        && userIsActive($account['is_verified']);

    if ($role === 'Participant') {
        // Only verified and active participants belong in the Participants total.
        if ($isVerified && $isActive) {
            $roleCounts['Participant']++;
        }
    } elseif (isset($roleCounts[$role])) {
        $roleCounts[$role]++;
    }

    if ($isActive) {
        $activeCount++;
    } else {
        $inactiveCount++;
    }
}

$totalUsers = count($allUsers);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>User Management - EMIS</title>

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
    >

    <link rel="stylesheet" href="../css/portal-theme.css">

    <style>

        .users-page {
            max-width: 1450px;
            margin: auto;
        }

        .users-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        .users-header h2 {
            margin: 0;
        }

        .users-header p {
            color: var(--text-muted, #aa9dbd);
            margin: 6px 0 0;
        }

        /* Statistics */

        .users-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 24px;
        }

        .users-stat {
            padding: 20px;
            border: 1px solid var(--border-color, #382b50);
            border-radius: 15px;
            background: var(--card-bg, #1a1328);
        }

        .users-stat span {
            display: block;
            color: var(--text-muted, #aa9dbd);
            font-size: 12px;
            margin-bottom: 9px;
        }

        .users-stat strong {
            display: block;
            font-size: 27px;
            color: var(--text-color, #fff);
        }

        /* Main panel */

        .users-panel {
            padding: 22px;
            border: 1px solid var(--border-color, #382b50);
            border-radius: 15px;
            background: var(--card-bg, #1a1328);
        }

        /* Toolbar */

        .users-toolbar {
            display: flex;
            align-items: end;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
        }

        .users-field {
            flex: 1;
            min-width: 180px;
        }

        .users-field label {
            display: block;
            margin-bottom: 7px;
            color: var(--text-muted, #cbb8f2);
            font-size: 12px;
            font-weight: 700;
        }

        .users-field input,
        .users-field select,
        .role-select {
            width: 100%;
            min-height: 42px;
            padding: 9px 12px;
            color: var(--text-color, #f4efff);
            background: var(--input-bg, #120d1d);
            border: 1px solid var(--border-color, #423254);
            border-radius: 9px;
        }

        .users-field input:focus,
        .users-field select:focus,
        .role-select:focus {
            outline: 2px solid #8b5cf6;
            outline-offset: 1px;
        }

        /* Buttons */

        .users-btn {
            min-height: 40px;
            padding: 9px 13px;
            border: 0;
            border-radius: 9px;
            color: white;
            cursor: pointer;
            font-weight: 700;
            font-size: 12px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .users-btn-primary {
            background: #7c3aed;
        }

        .users-btn-primary:hover {
            background: #6d28d9;
        }

        .users-btn-secondary {
            background: #514363;
        }

        .users-btn-secondary:hover {
            background: #665477;
        }

        .users-btn-info {
            background: #2563eb;
        }

        .users-btn-info:hover {
            background: #1d4ed8;
        }

        .users-btn-danger {
            background: #dc3545;
        }

        .users-btn-danger:hover {
            background: #b02a37;
        }

        .users-btn-success {
            background: #198754;
        }

        .users-btn-success:hover {
            background: #146c43;
        }

        /* Table */

        .users-table-wrap {
            overflow-x: auto;
            border: 1px solid var(--border-color, #382b50);
            border-radius: 12px;
        }

        .users-table {
            width: 100%;
            min-width: 1000px;
            border-collapse: collapse;
        }

        .users-table th {
            padding: 15px;
            text-align: left;
            color: var(--text-muted, #cbb8f2);
            background: var(--table-header-bg, #211832);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .05em;
            white-space: nowrap;
        }

        .users-table td {
            padding: 14px 15px;
            border-top: 1px solid var(--border-color, #382b50);
            color: var(--text-color, #e9e1f4);
            vertical-align: middle;
        }

        .users-table tbody tr:hover td {
            background: rgba(139, 92, 246, .06);
        }

        .user-name {
            font-weight: 700;
            color: var(--text-color, #fff);
        }

        .user-email {
            color: var(--text-muted, #aa9dbd);
            font-size: 12px;
            margin-top: 4px;
        }

        /* Badges */

        .role-badge,
        .status-badge,
        .verification-badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 750;
            white-space: nowrap;
        }

        .role-badge {
            background: #302244;
            color: #d8c3ff;
        }

        .status-active {
            background: #12382e;
            color: #a7f3d0;
        }

        .status-inactive {
            background: #421d2a;
            color: #fecaca;
        }

        .verified {
            background: #12382e;
            color: #a7f3d0;
        }

        .unverified {
            background: #42351b;
            color: #fde68a;
        }

        /* Row actions */

        .user-actions {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 7px;
        }

        .role-form,
        .status-form {
            margin: 0;
        }

        .role-form {
            display: flex;
            align-items: center;
            gap: 7px;
            min-width: 225px;
        }

        .role-form button {
            flex-shrink: 0;
        }

        .role-select {
            min-width: 150px;
        }

        .your-account {
            color: var(--text-muted, #aa9dbd);
            font-size: 12px;
        }

        /* Alerts */

        .users-alert {
            padding: 13px 16px;
            margin-bottom: 18px;
            border-radius: 10px;
            font-size: 13px;
        }

        .users-alert.success {
            color: #a7f3d0;
            background: #12382e;
            border: 1px solid #246b53;
        }

        .users-alert.error {
            color: #fecaca;
            background: #421d2a;
            border: 1px solid #84394e;
        }

        .users-empty {
            padding: 35px;
            text-align: center;
            color: var(--text-muted, #aa9dbd);
        }

        /* Notes */

        .users-note {
            margin-top: 17px;
            padding: 14px 16px;
            border: 1px solid var(--border-color, #49365e);
            border-radius: 12px;
            color: var(--text-muted, #cbb8f2);
            background: var(--table-header-bg, #211832);
            font-size: 12px;
            line-height: 1.6;
        }

        /* Details Modal */

        .users-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 9999;
            background: rgba(0, 0, 0, .7);
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .users-modal-overlay.show {
            display: flex;
        }

        .users-modal {
            width: 100%;
            max-width: 560px;
            max-height: 85vh;
            overflow-y: auto;
            border: 1px solid var(--border-color, #49365e);
            border-radius: 16px;
            background: var(--card-bg, #1a1328);
            color: var(--text-color, #fff);
            box-shadow: 0 20px 60px rgba(0, 0, 0, .35);
        }

        .users-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 19px 22px;
            border-bottom: 1px solid var(--border-color, #382b50);
        }

        .users-modal-header h3 {
            margin: 0;
            font-size: 18px;
        }

        .users-modal-close {
            border: 0;
            background: transparent;
            color: var(--text-color, #fff);
            font-size: 25px;
            cursor: pointer;
        }

        .users-modal-body {
            padding: 20px 22px;
        }

        .detail-row {
            padding: 12px 0;
            border-bottom: 1px solid var(--border-color, #382b50);
        }

        .detail-row:last-child {
            border-bottom: 0;
        }

        .detail-label {
            display: block;
            margin-bottom: 5px;
            color: var(--text-muted, #aa9dbd);
            font-size: 11px;
        }

        .detail-value {
            display: block;
            overflow-wrap: anywhere;
            font-size: 14px;
            font-weight: 600;
        }

        @media(max-width: 650px) {
            .users-panel {
                padding: 13px;
            }

            .users-toolbar {
                align-items: stretch;
            }

            .users-field {
                min-width: 100%;
            }

            .users-toolbar .users-btn {
                flex: 1;
            }
        }

    </style>

</head>

<body class="admin-portal">

<?php require_once __DIR__ . '/../includes/admin-nav.php'; ?>

<main class="content-area admin-main-content">

<div class="users-page">

    <!-- PAGE HEADER -->

    <header class="users-header">

        <div>
            <h2>
                <i class="bi bi-people me-2"></i>
                User Management
            </h2>

            <p>
                View registered accounts and manage their system access.
            </p>
        </div>

    </header>

    <!-- ALERTS -->

    <?php if ($successMsg): ?>

        <div class="users-alert success">
            <i class="bi bi-check-circle me-2"></i>
            <?= userH($successMsg) ?>
        </div>

    <?php endif; ?>

    <?php if ($errorMsg): ?>

        <div class="users-alert error">
            <i class="bi bi-exclamation-circle me-2"></i>
            <?= userH($errorMsg) ?>
        </div>

    <?php endif; ?>

    <!-- STATISTICS -->

    <section class="users-stats">

        <div class="users-stat">
            <span>Total Users</span>
            <strong><?= $totalUsers ?></strong>
        </div>

        <div class="users-stat">
            <span>Administrators</span>
            <strong><?= $roleCounts['Administrator'] ?></strong>
        </div>

        <div class="users-stat">
            <span>Event Organizers</span>
            <strong><?= $roleCounts['Event Organizer'] ?></strong>
        </div>

        <div class="users-stat">
            <span>Staff Members</span>
            <strong><?= $roleCounts['Staff'] ?></strong>
        </div>

        <div class="users-stat">
            <span>Participants</span>
            <strong><?= $roleCounts['Participant'] ?></strong>
        </div>

        <div class="users-stat">
            <span>Active Accounts</span>
            <strong><?= $activeCount ?></strong>
        </div>

        <div class="users-stat">
            <span>Deactivated Accounts</span>
            <strong><?= $inactiveCount ?></strong>
        </div>

    </section>

    <!-- USER MANAGEMENT PANEL -->

    <section class="users-panel">

        <!-- SEARCH AND FILTER -->

        <form method="GET" action="users.php" class="users-toolbar">

            <div class="users-field">

                <label for="search">Search Users</label>

                <input
                    type="search"
                    id="search"
                    name="search"
                    placeholder="Search name or email..."
                    value="<?= userH($search) ?>"
                >

            </div>

            <div class="users-field">

                <label for="role">Filter by Role</label>

                <select id="role" name="role">

                    <option value="">All Roles</option>

                    <?php foreach ($allowedRoles as $role): ?>

                        <option
                            value="<?= userH($role) ?>"
                            <?= $filterRole === $role ? 'selected' : '' ?>
                        >
                            <?= userH($role) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="users-field">

                <label for="status">Filter by Status</label>

                <select id="status" name="status">

                    <option value="" <?= $filterStatus === '' ? 'selected' : '' ?>>
                        All Statuses
                    </option>

                    <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>
                        Active
                    </option>

                    <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>
                        Deactivated
                    </option>

                </select>

            </div>

            <button type="submit" class="users-btn users-btn-primary">
                <i class="bi bi-search"></i>
                Search
            </button>

            <a href="users.php" class="users-btn users-btn-secondary">
                <i class="bi bi-arrow-counterclockwise"></i>
                Reset
            </a>

        </form>

        <!-- USERS TABLE -->

        <div class="users-table-wrap">

            <table class="users-table">

                <thead>

                    <tr>
                        <th>User</th>
                        <th>Current Role</th>
                        <th>Account Status</th>
                        <th>Actions</th>
                    </tr>

                </thead>

                <tbody>

                <?php if (empty($users)): ?>

                    <tr>
                        <td colspan="4" class="users-empty">
                            <i class="bi bi-people fs-3 d-block mb-2"></i>
                            No user accounts found.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($users as $user): ?>

                        <?php

                        $userId = (string)($user['id'] ?? '');

                        $fullName = trim(
                            ($user['first_name'] ?? '') . ' ' .
                            ($user['last_name'] ?? '')
                        );

                        $fullName = $fullName ?: 'Unnamed User';

                        $email = $user['email'] ?? '';

                        $role = $user['role'] ?? 'Participant';

                        $isActive = userAccountIsActive($user);

                        $isVerified = userIsActive(
                            $user['is_verified'] ?? false
                        ) && (
                            isset($user['is_verified']) &&
                            $user['is_verified'] !== null
                        );

                        $isCurrentUser = (
                            $userId === (string)$_SESSION['user_id']
                        );

                        $details = [
                            'name' => $fullName,
                            'email' => $email,
                            'id' => $userId,
                            'role' => $role,
                            'course' => $user['course'] ?? '',
                            'section' => $user['section'] ?? '',
                            'verified' => $isVerified,
                            'active' => $isActive,
                            'created' => $user['created_at'] ?? '',
                            'updated' => $user['updated_at'] ?? ''
                        ];

                        $detailsJson = json_encode(
                            $details,
                            JSON_HEX_APOS |
                            JSON_HEX_QUOT |
                            JSON_HEX_TAG |
                            JSON_HEX_AMP
                        );

                        ?>

                        <tr>

                            <!-- USER INFORMATION -->

                            <td>

                                <div class="user-name">
                                    <?= userH($fullName) ?>
                                </div>

                                <div class="user-email">
                                    <?= userH($email) ?>
                                </div>

                            </td>

                            <!-- ROLE -->

                            <td>

                                <span class="role-badge">
                                    <?= userH($role) ?>
                                </span>

                            </td>

                            <!-- ACCOUNT STATUS -->

                            <td>

                                <span class="status-badge <?= $isActive ? 'status-active' : 'status-inactive' ?>">

                                    <i class="bi <?= $isActive ? 'bi-check-circle' : 'bi-x-circle' ?> me-1"></i>

                                    <?= $isActive ? 'Active' : 'Deactivated' ?>

                                </span>

                            </td>

                            <!-- ACTIONS -->

                            <td>

                                <div class="user-actions">

                                    <!-- VIEW DETAILS -->

                                    <button
                                        type="button"
                                        class="users-btn users-btn-info view-user-btn"
                                        data-user="<?= userH($detailsJson) ?>"
                                    >
                                        <i class="bi bi-eye"></i>
                                        Details
                                    </button>

                                    <?php if (!$isCurrentUser): ?>

                                        <!-- CHANGE ROLE -->

                                        <form
                                            method="POST"
                                            action="users.php"
                                            class="role-form"
                                            onsubmit="return confirm('Are you sure you want to change this user role?');"
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= userH($csrfToken) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="update_role"
                                            >

                                            <input
                                                type="hidden"
                                                name="user_id"
                                                value="<?= userH($userId) ?>"
                                            >

                                            <select
                                                name="new_role"
                                                class="role-select"
                                                aria-label="New role for <?= userH($fullName) ?>"
                                            >

                                                <?php foreach ($allowedRoles as $option): ?>

                                                    <option
                                                        value="<?= userH($option) ?>"
                                                        <?= $role === $option ? 'selected' : '' ?>
                                                    >
                                                        <?= userH($option) ?>
                                                    </option>

                                                <?php endforeach; ?>

                                            </select>

                                            <button
                                                type="submit"
                                                class="users-btn users-btn-primary"
                                                title="Save role"
                                            >
                                                <i class="bi bi-check-lg"></i>
                                            </button>

                                        </form>

                                        <!-- ACTIVATE / DEACTIVATE -->

                                        <form
                                            method="POST"
                                            action="users.php"
                                            class="status-form"
                                            onsubmit="return confirm('Are you sure you want to <?= $isActive ? 'deactivate' : 'activate' ?> this account?');"
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= userH($csrfToken) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="toggle_status"
                                            >

                                            <input
                                                type="hidden"
                                                name="user_id"
                                                value="<?= userH($userId) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="new_status"
                                                value="<?= $isActive ? 'false' : 'true' ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="users-btn <?= $isActive ? 'users-btn-danger' : 'users-btn-success' ?>"
                                            >

                                                <i class="bi <?= $isActive ? 'bi-person-x' : 'bi-person-check' ?>"></i>

                                                <?= $isActive ? 'Deactivate' : 'Activate' ?>

                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <span class="your-account">
                                            <i class="bi bi-shield-check"></i>
                                            Your account
                                        </span>

                                    <?php endif; ?>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

        <!-- INFORMATION NOTE -->

        <div class="users-note">

            <i class="bi bi-shield-lock me-1"></i>

            <strong>Account Management:</strong>

            Only administrators can manage account roles and statuses.
            You cannot change your own role or deactivate your own account.
            The last active administrator is protected.

            <br>

            <i class="bi bi-info-circle me-1"></i>

            Deactivating an account changes its database status.
            Login and existing-session protection must also be enforced
            in the authentication and protected-page code.

        </div>

    </section>

</div>

</main>

<!-- =====================================================
     ACCOUNT DETAILS MODAL
====================================================== -->

<div
    class="users-modal-overlay"
    id="userDetailsModal"
    aria-hidden="true"
>

    <div
        class="users-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="userDetailsTitle"
    >

        <div class="users-modal-header">

            <h3 id="userDetailsTitle">
                <i class="bi bi-person-vcard me-2"></i>
                Account Details
            </h3>

            <button
                type="button"
                class="users-modal-close"
                id="closeUserModal"
                aria-label="Close"
            >
                &times;
            </button>

        </div>

        <div
            class="users-modal-body"
            id="userDetailsContent"
        ></div>

    </div>

</div>

<script>

/* =========================================================
   ACCOUNT DETAILS MODAL
========================================================= */

const userModal = document.getElementById('userDetailsModal');
const userDetailsContent = document.getElementById('userDetailsContent');

function showUserDetails(user) {

    const fields = [
        ['Full Name', user.name],
        ['Email Address', user.email],
        ['User ID', user.id],
        ['Role', user.role],
        ['Course', user.course || 'Not specified'],
        ['Section', user.section || 'Not specified'],
        [
            'Email Verification',
            user.verified ? 'Verified' : 'Unverified'
        ],
        [
            'Account Status',
            user.active ? 'Active' : 'Deactivated'
        ],
        ['Date Registered', user.created || 'Not available'],
        ['Last Updated', user.updated || 'Not available']
    ];

    userDetailsContent.replaceChildren();

    fields.forEach(([label, value]) => {

        const row = document.createElement('div');
        row.className = 'detail-row';

        const heading = document.createElement('span');
        heading.className = 'detail-label';
        heading.textContent = label;

        const detail = document.createElement('span');
        detail.className = 'detail-value';
        detail.textContent = String(value ?? '');

        row.appendChild(heading);
        row.appendChild(detail);

        userDetailsContent.appendChild(row);

    });

    userModal.classList.add('show');
    userModal.setAttribute('aria-hidden', 'false');

}

document.querySelectorAll('.view-user-btn').forEach(button => {

    button.addEventListener('click', function () {

        try {

            const user = JSON.parse(this.dataset.user);

            showUserDetails(user);

        } catch (error) {

            alert('Unable to display account details.');

            console.error(error);

        }

    });

});

/* =========================================================
   CLOSE MODAL
========================================================= */

function closeUserDetails() {

    userModal.classList.remove('show');
    userModal.setAttribute('aria-hidden', 'true');

}

document.getElementById('closeUserModal')
    .addEventListener('click', closeUserDetails);

userModal.addEventListener('click', function(event) {

    if (event.target === userModal) {
        closeUserDetails();
    }

});

document.addEventListener('keydown', function(event) {

    if (event.key === 'Escape') {
        closeUserDetails();
    }

});

</script>

</body>
</html>
