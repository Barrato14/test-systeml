<?php
session_start();
date_default_timezone_set('Asia/Manila');
require_once '../config/database.php';
require_once '../includes/auth.php';

if (!isset($_SESSION['user_id']) || strtolower(trim((string)($_SESSION['user_role'] ?? ''))) !== 'administrator') {
    http_response_code(403);
    exit('Access denied. The Audit Log is available to administrators only.');
}

function auditEscape($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$logs = [];
$profiles = [];
$loadError = '';

$logsResponse = supabaseServiceRequest(
    'audit_logs?select=id,profile_id,action,details,ip_address,created_at&order=created_at.desc&limit=500',
    'GET'
);

if (($logsResponse['code'] ?? 500) >= 200 && ($logsResponse['code'] ?? 500) < 300 && is_array($logsResponse['data'] ?? null)) {
    $logs = $logsResponse['data'];
} else {
    $loadError = 'Audit records could not be loaded. Check the audit_logs table, its columns, and the server service-role configuration.';
    error_log('Zentrix audit log page load failed: ' . json_encode($logsResponse['data'] ?? []));
}

$profileResponse = supabaseServiceRequest(
    'profiles?select=id,first_name,last_name,email,role&limit=2000',
    'GET'
);
if (($profileResponse['code'] ?? 500) >= 200 && ($profileResponse['code'] ?? 500) < 300 && is_array($profileResponse['data'] ?? null)) {
    foreach ($profileResponse['data'] as $profile) {
        $profiles[(string)($profile['id'] ?? '')] = $profile;
    }
}

$filter = trim((string)($_GET['type'] ?? 'all'));
$search = trim((string)($_GET['q'] ?? ''));
$allowedTypes = ['all', 'attendance', 'borrowing', 'equipment', 'event', 'user'];
if (!in_array($filter, $allowedTypes, true)) $filter = 'all';

$filteredLogs = array_values(array_filter($logs, function ($log) use ($filter, $search, $profiles) {
    $action = strtolower((string)($log['action'] ?? ''));
    $details = strtolower((string)($log['details'] ?? ''));
    $actor = $profiles[(string)($log['profile_id'] ?? '')] ?? [];
    $actorName = strtolower(trim((string)($actor['first_name'] ?? '') . ' ' . (string)($actor['last_name'] ?? '')));
    $matchesType = $filter === 'all'
        || ($filter === 'attendance' && str_contains($action, 'attendance'))
        || ($filter === 'borrowing' && str_contains($action, 'borrow'))
        || ($filter === 'equipment' && (str_contains($action, 'equipment') || str_contains($details, 'equipment')))
        || ($filter === 'event' && str_contains($action, 'event'))
        || ($filter === 'user' && (str_contains($action, 'user') || str_contains($action, 'profile')));
    $needle = strtolower($search);
    $matchesSearch = $needle === ''
        || str_contains($action, $needle)
        || str_contains($details, $needle)
        || str_contains($actorName, $needle)
        || str_contains(strtolower((string)($actor['email'] ?? '')), $needle);
    return $matchesType && $matchesSearch;
}));

$totalLogs = count($logs);
$filteredCount = count($filteredLogs);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Audit Log - Zentrix</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
<link rel="stylesheet" href="../css/portal-theme.css?v=20261117-2">
<style>
.audit-page{padding:30px;min-height:100vh;background:var(--admin-bg,#100b18);color:var(--admin-text,#f4efff)}
.audit-intro,.audit-panel{background:var(--admin-surface,#1a1328);border:1px solid var(--admin-border,#49375e);border-radius:16px}
.audit-intro{padding:22px;margin-bottom:22px}.audit-panel{overflow:hidden}
.audit-toolbar{display:flex;flex-wrap:wrap;gap:12px;align-items:center;padding:18px;border-bottom:1px solid var(--admin-border,#49375e)}
.audit-toolbar input,.audit-toolbar select{min-height:42px;border:1px solid var(--admin-border,#49375e);border-radius:9px;background:var(--admin-bg,#100b18);color:var(--admin-text,#f4efff);padding:8px 12px}
.audit-toolbar input{flex:1;min-width:210px}
.audit-table-wrap{overflow-x:auto}.audit-table{width:100%;border-collapse:collapse;min-width:850px}
.audit-table th,.audit-table td{padding:14px 16px;text-align:left;vertical-align:top;border-bottom:1px solid var(--admin-border,#49375e)}
.audit-table th{font-size:11px;letter-spacing:.07em;text-transform:uppercase;color:var(--admin-muted,#b8acc8);white-space:nowrap}
.audit-table td{font-size:13px;color:var(--admin-text,#f4efff)}
.audit-actor{font-weight:700}.audit-meta{display:block;color:var(--admin-muted,#b8acc8);font-size:11px;margin-top:4px}
.audit-action{display:inline-flex;padding:5px 9px;border-radius:20px;background:rgba(155,107,232,.14);color:#c7a7ff;font-size:11px;font-weight:700}
.audit-details{max-width:520px;white-space:pre-wrap;overflow-wrap:anywhere;color:var(--admin-muted,#d0c5df)}
.audit-empty{padding:45px 20px;text-align:center;color:var(--admin-muted,#b8acc8)}
@media(max-width:767px){.audit-page{padding:18px 12px}.audit-intro{padding:18px}}
</style>
</head>
<body class="admin-portal">
<?php require_once __DIR__ . '/../includes/admin-nav.php'; ?>
<main class="content-area admin-main-content audit-page">
    <div class="admin-page-heading admin-section-heading d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div><h2 class="mb-1"><i class="bi bi-shield-exclamation me-2"></i>Audit Log</h2><p class="text-muted mb-0">Administrative accountability and sensitive activity history</p></div>
        <span class="badge bg-secondary fs-6"><?= $filteredCount ?> / <?= $totalLogs ?> records</span>
    </div>
    <section class="audit-intro">
        <div class="d-flex align-items-start gap-3">
            <i class="bi bi-shield-lock-fill" style="font-size:25px;color:#c7a7ff"></i>
            <div><h5 class="mb-1">Administrator-only activity review</h5><p class="mb-0 text-muted small">Review who changed attendance, approved or rejected borrowing requests, handled equipment inventory, or performed other recorded administrative actions. Audit entries are historical records; they do not automatically prove misconduct.</p></div>
        </div>
    </section>
    <?php if ($loadError !== ''): ?>
        <div class="alert alert-warning"><?= auditEscape($loadError) ?></div>
    <?php endif; ?>
    <section class="audit-panel">
        <form method="get" class="audit-toolbar">
            <select name="type" aria-label="Filter audit category">
                <?php foreach (['all'=>'All activity','attendance'=>'Attendance','borrowing'=>'Borrowing','equipment'=>'Equipment','event'=>'Events','user'=>'User management'] as $value=>$label): ?>
                    <option value="<?= auditEscape($value) ?>" <?= $filter === $value ? 'selected' : '' ?>><?= auditEscape($label) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="search" name="q" value="<?= auditEscape($search) ?>" placeholder="Search staff, action, participant, or item...">
            <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Filter</button>
            <a class="btn btn-outline-secondary" href="audit-logs.php">Reset</a>
        </form>
        <?php if (empty($filteredLogs)): ?>
            <div class="audit-empty"><i class="bi bi-journal-x" style="font-size:30px"></i><h5 class="mt-2">No audit records found</h5><p class="mb-0">Recorded actions will appear here when staff or organizers perform tracked operations.</p></div>
        <?php else: ?>
        <div class="audit-table-wrap">
            <table class="audit-table">
                <thead><tr><th>Date &amp; time</th><th>Performed by</th><th>Action</th><th>Details</th><th>IP address</th></tr></thead>
                <tbody>
                <?php foreach ($filteredLogs as $log): ?>
                    <?php
                    $actor = $profiles[(string)($log['profile_id'] ?? '')] ?? [];
                    $actorName = trim((string)($actor['first_name'] ?? '') . ' ' . (string)($actor['last_name'] ?? ''));
                    $timestamp = $log['created_at'] ?? null;
                    try { $formattedTime = $timestamp ? (new DateTimeImmutable($timestamp))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y · g:i:s A') : 'Time unavailable'; }
                    catch (Throwable $e) { $formattedTime = 'Invalid date'; }
                    ?>
                    <tr>
                        <td><?= auditEscape($formattedTime) ?></td>
                        <td><span class="audit-actor"><?= auditEscape($actorName !== '' ? $actorName : 'Deleted / unavailable account') ?></span><span class="audit-meta"><?= auditEscape($actor['email'] ?? 'Account details unavailable') ?><?= !empty($actor['role']) ? ' · ' . auditEscape(ucwords((string)$actor['role'])) : '' ?></span></td>
                        <td><span class="audit-action"><?= auditEscape(str_replace('_', ' ', (string)($log['action'] ?? 'Activity'))) ?></span></td>
                        <td><div class="audit-details"><?= auditEscape($log['details'] ?? '') ?></div></td>
                        <td><?= auditEscape($log['ip_address'] ?? 'Not recorded') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
