<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once '../config/database.php';
require_once '../includes/auth.php';

// Only administrators can access User Management.
$currentRole = strtolower(trim($_SESSION['user_role'] ?? ''));

if (!isset($_SESSION['user_id']) || $currentRole !== 'administrator') {
    header('Location: ../login.php');
    exit;
}

if (empty($_SESSION['users_csrf'])) {
    $_SESSION['users_csrf'] = bin2hex(random_bytes(32));
}

// Use the server-side service key. Never expose this key to JavaScript or HTML.
$serviceKey = getenv('SUPABASE_SERVICE_ROLE_KEY')
    ?: ($env['SUPABASE_SERVICE_ROLE_KEY'] ?? '');

$successMsg = '';
$errorMsg = '';

function userH($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function usersApiRequest($endpoint, $method = 'GET', $payload = null) {
    global $supabaseUrl, $serviceKey;

    if (empty($serviceKey)) {
        throw new Exception(
            'SUPABASE_SERVICE_ROLE_KEY is missing from the server environment.'
        );
    }

    $url = rtrim($supabaseUrl, '/') . '/rest/v1/' . ltrim($endpoint, '/');

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
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new Exception('Database connection failed: ' . $curlError);
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

$allowedRoles = [
    'Administrator',
    'Event Organizer',
    'Staff',
    'Participant'
];

// Process role updates.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals(
            $_SESSION['users_csrf'],
            $_POST['csrf_token'] ?? ''
        )) {
            throw new Exception('Invalid security token. Refresh the page and try again.');
        }

        if (($_POST['action'] ?? '') !== 'update_role') {
            throw new Exception('Invalid action.');
        }

        $targetId = trim($_POST['user_id'] ?? '');
        $newRole = trim($_POST['new_role'] ?? '');

        if ($targetId === '' || !in_array($newRole, $allowedRoles, true)) {
            throw new Exception('Invalid user or role selection.');
        }

        // Load the target account's current role.
        $targetRows = usersApiRequest(
            'profiles?id=eq.' . rawurlencode($targetId)
            . '&select=id,role'
        );

        if (empty($targetRows)) {
            throw new Exception('User account not found.');
        }

        $targetUser = $targetRows[0];
        $oldRole = $targetUser['role'] ?? '';

        // Prevent administrators from changing their own role.
        if ($targetId === (string)$_SESSION['user_id']) {
            throw new Exception('You cannot change your own administrator role.');
        }

        // Prevent removing the final administrator.
        if ($oldRole === 'Administrator' && $newRole !== 'Administrator') {
            $admins = usersApiRequest(
                'profiles?role=eq.Administrator&select=id'
            );

            if (count($admins) <= 1) {
                throw new Exception(
                    'You cannot demote the last administrator account.'
                );
            }
        }

        if ($oldRole === $newRole) {
            throw new Exception('This account already has that role.');
        }

        // Update only the role column.
        usersApiRequest(
            'profiles?id=eq.' . rawurlencode($targetId),
            'PATCH',
            ['role' => $newRole]
        );

        $successMsg = 'User role updated successfully.';
    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
    }
}

// Load user accounts.
$users = [];

try {
    $users = usersApiRequest(
        'profiles?select=id,first_name,last_name,email,role,is_verified,is_active,course,section,created_at,updated_at'
        . '&order=first_name.asc'
    );
} catch (Exception $e) {
    $errorMsg = $errorMsg ?: $e->getMessage();
}

// Search and filter.
$search = trim($_GET['search'] ?? '');
$filterRole = trim($_GET['role'] ?? '');

if ($search !== '' || $filterRole !== '') {
    $users = array_values(array_filter($users, function ($user) use ($search, $filterRole) {
        $name = trim(
            ($user['first_name'] ?? '') . ' ' .
            ($user['last_name'] ?? '')
        );

        $email = $user['email'] ?? '';
        $role = $user['role'] ?? '';

        $matchesSearch = $search === ''
            || stripos($name, $search) !== false
            || stripos($email, $search) !== false;

        $matchesRole = $filterRole === '' || $role === $filterRole;

        return $matchesSearch && $matchesRole;
    }));
}

// Count roles using the complete account list.
$allUsers = [];

try {
    $allUsers = usersApiRequest(
        'profiles?select=id,role'
    );
} catch (Exception $e) {
    // The main user list already displays any database error.
}

$roleCounts = array_fill_keys($allowedRoles, 0);

foreach ($allUsers as $account) {
    $role = $account['role'] ?? '';

    if (isset($roleCounts[$role])) {
        $roleCounts[$role]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - EMIS</title>

    <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">

    <link rel="stylesheet" href="../css/portal-theme.css">

    <style>
        .users-page { max-width: 1450px; margin: auto; }
        .users-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 28px;
        }
        .users-header p { color: #aa9dbd; margin: 5px 0 0; }
        .users-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 24px;
        }
        .users-stat {
            padding: 19px;
            border: 1px solid #382b50;
            border-radius: 15px;
            background: #1a1328;
        }
        .users-stat span {
            display: block;
            color: #aa9dbd;
            font-size: 12px;
            margin-bottom: 8px;
        }
        .users-stat strong { font-size: 27px; color: #fff; }
        .users-toolbar {
            display: flex;
            align-items: end;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
        }
        .users-toolbar .field { flex: 1; min-width: 190px; }
        .users-toolbar label {
            display: block;
            color: #cbb8f2;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 7px;
        }
        .users-toolbar input,
        .users-toolbar select,
        .role-select {
            width: 100%;
            min-height: 42px;
            padding: 9px 12px;
            color: #f4efff;
            background: #120d1d;
            border: 1px solid #423254;
            border-radius: 10px;
        }
        .users-table-wrap {
            overflow-x: auto;
            border: 1px solid #382b50;
            border-radius: 15px;
            background: #1a1328;
        }
        .users-table {
            width: 100%;
            min-width: 760px;
            border-collapse: collapse;
        }
        .users-table th {
            padding: 15px;
            text-align: left;
            color: #cbb8f2;
            background: #211832;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .06em;
        }
        .users-table td {
            padding: 14px 15px;
            border-top: 1px solid #382b50;
            color: #e9e1f4;
            vertical-align: middle;
        }
        .users-table tr:hover td { background: rgba(139,92,246,.06); }
        .user-name { font-weight: 700; color: #fff; }
        .user-email { color: #aa9dbd; font-size: 12px; }
        .role-form {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 230px;
        }
        .role-form button {
            border: 0;
            border-radius: 9px;
            padding: 10px 12px;
            color: white;
            background: #7c3aed;
            cursor: pointer;
            font-weight: 700;
        }
        .role-form button:hover { background: #6d28d9; }
        .role-badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            background: #302244;
            color: #d8c3ff;
            font-size: 11px;
            font-weight: 750;
        }
        .user-status { font-size: 12px; color: #aa9dbd; }
        .users-alert {
            padding: 13px 16px;
            margin-bottom: 18px;
            border-radius: 10px;
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
        .users-empty { padding: 35px; text-align: center; color: #aa9dbd; }
        .users-note {
            margin-top: 17px;
            padding: 14px 16px;
            border: 1px solid #49365e;
            border-radius: 12px;
            color: #cbb8f2;
            background: #211832;
            font-size: 12px;
        }
        @media(max-width: 850px) {
            .users-stats { grid-template-columns: repeat(2, 1fr); }
        }
        @media(max-width: 480px) {
            .users-stats { grid-template-columns: 1fr 1fr; gap: 9px; }
            .users-stat { padding: 13px; }
            .users-stat strong { font-size: 22px; }
        }
    </style>
</head>

<body class="admin-portal">

<?php require_once __DIR__ . '/../includes/admin-nav.php'; ?>

<main class="content-area admin-main-content">
<div class="users-page">

    <header class="users-header">
        <div>
            <h2><i class="bi bi-people me-2"></i>User Management</h2>
            <p>View registered accounts and manage their system roles.</p>
        </div>
    </header>

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

    <section class="users-stats">
        <div class="users-stat">
            <span>Total Users</span>
            <strong><?= count($allUsers) ?></strong>
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
    </section>

    <section class="card">
        <div class="card-body">

            <form method="GET" action="users.php" class="users-toolbar">
                <div class="field">
                    <label for="search">Search accounts</label>
                    <input
                        type="search"
                        id="search"
                        name="search"
                        placeholder="Search name or email..."
                        value="<?= userH($search) ?>">
                </div>

                <div class="field">
                    <label for="role">Filter by role</label>
                    <select id="role" name="role">
                        <option value="">All roles</option>
                        <?php foreach ($allowedRoles as $role): ?>
                            <option value="<?= userH($role) ?>"
                                <?= $filterRole === $role ? 'selected' : '' ?>>
                                <?= userH($role) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search me-1"></i> Search
                </button>

                <a href="users.php" class="btn btn-secondary">Reset</a>
            </form>

            <div class="users-table-wrap">
                <table class="users-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Current Role</th>
                            <th>Account Status</th>
                            <th>Change Role</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="4" class="users-empty">
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
                                $role = $user['role'] ?? 'Participant';
                                $verified = ($user['is_verified'] ?? false) === true
                                    || ($user['is_verified'] ?? '') === 'true'
                                    || ($user['is_verified'] ?? 0) === 1;
                            ?>
                            <tr>
                                <td>
                                    <div class="user-name">
                                        <?= userH($fullName ?: 'Unnamed User') ?>
                                    </div>
                                    <div class="user-email">
                                        <?= userH($user['email'] ?? '') ?>
                                    </div>
                                </td>

                                <td>
                                    <span class="role-badge">
                                        <?= userH($role) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="user-status">
                                        <?= $verified ? 'Verified' : 'Unverified' ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if ($userId === (string)$_SESSION['user_id']): ?>
                                        <span class="user-status">
                                            Your account
                                        </span>
                                    <?php else: ?>
                                        <form method="POST" action="users.php" class="role-form"
                                            onsubmit="return confirm('Are you sure you want to change this user’s role?');">

                                            <input type="hidden" name="csrf_token"
                                                value="<?= userH($_SESSION['users_csrf']) ?>">

                                            <input type="hidden" name="action"
                                                value="update_role">

                                            <input type="hidden" name="user_id"
                                                value="<?= userH($userId) ?>">

                                            <select name="new_role" class="role-select"
                                                aria-label="New role for <?= userH($fullName) ?>">
                                                <?php foreach ($allowedRoles as $option): ?>
                                                    <option value="<?= userH($option) ?>"
                                                        <?= $role === $option ? 'selected' : '' ?>>
                                                        <?= userH($option) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>

                                            <button type="submit" title="Save role">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="users-note">
                <i class="bi bi-shield-lock me-1"></i>
                Only administrators can manage roles. Public registration
                continues to create Participant accounts automatically.
                Changes to another user's role take effect on their next login.
            </div>

        </div>
    </section>

</div>
</main>
</body>
</html>
