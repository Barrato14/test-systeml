<?php
if (!isset($notifications) && function_exists('supabaseRequest') && !empty($_SESSION['user_id'])) {
    $participantNavNotificationsResponse = supabaseRequest(
        'notifications?participant_id=eq.' . rawurlencode((string)$_SESSION['user_id']) . '&select=id,is_read&order=created_at.desc',
        'GET'
    );
    $participantNavNotifications = (($participantNavNotificationsResponse['code'] ?? 500) < 400 && is_array($participantNavNotificationsResponse['data'] ?? null))
        ? $participantNavNotificationsResponse['data'] : [];
    $unreadNotificationCount = 0;
    foreach ($participantNavNotifications as $participantNavNotification) {
        if (($participantNavNotification['is_read'] ?? false) !== true && ($participantNavNotification['is_read'] ?? '') !== 'true') {
            $unreadNotificationCount++;
        }
    }
}
?>
<?php
$currentParticipantPage = basename($_SERVER['PHP_SELF'] ?? '');
$participantName = $_SESSION['user_name'] ?? $_SESSION['first_name'] ?? 'Participant';
$participantNavItems = [
    ['dashboard.php', 'Dashboard', 'bi-grid-1x2'],
    ['browse-events.php', 'Browse Events', 'bi-calendar2-event'],
    ['my-events.php', 'My Events', 'bi-ticket-perforated'],
    ['equipment.php', 'Equipment', 'bi-box-seam'],
    ['my-borrowings.php', 'My Borrowings', 'bi-clipboard-check']
];
?>
<aside class="participant-sidebar" aria-label="Participant sidebar">
 <div class="participant-sidebar-inner">
  <a class="participant-brand" href="dashboard.php" aria-label="EMIS Participant Dashboard">
   <span class="participant-brand-mark"><i class="bi bi-calendar2-check"></i></span>
   <span class="participant-brand-copy"><strong>EMIS</strong><small>Participant Portal</small></span>
  </a>
  <span class="participant-sidebar-caption">WORKSPACE</span>
  <nav class="participant-sidebar-nav" aria-label="Participant navigation">
   <?php foreach ($participantNavItems as [$href, $label, $icon]): ?>
    <a href="<?= htmlspecialchars($href) ?>" class="participant-sidebar-link <?= $currentParticipantPage === $href ? 'is-active' : '' ?>" <?= $currentParticipantPage === $href ? 'aria-current="page"' : '' ?> title="<?= htmlspecialchars($label) ?>">
     <i class="bi <?= htmlspecialchars($icon) ?>"></i><span><?= htmlspecialchars($label) ?></span>
    </a>
   <?php endforeach; ?>
  </nav>
  <div class="participant-sidebar-bottom">
   <a href="dashboard.php?open_notifications=1" class="participant-sidebar-link participant-sidebar-notifications" title="Notifications">
     <i class="bi bi-bell"></i><span>Notifications</span>
     <?php if (!empty($unreadNotificationCount)): ?><span class="participant-notification-count"><?= (int)$unreadNotificationCount ?></span><?php endif; ?>
    </a>
   <div class="participant-sidebar-profile"><i class="bi bi-person-circle"></i><span><?= htmlspecialchars((string)$participantName) ?></span></div>
   <details class="participant-settings">
    <summary class="participant-sidebar-link" title="Settings"><i class="bi bi-gear"></i><span>Settings</span></summary>
    <div class="participant-settings-panel">
     <div class="participant-settings-heading">Settings</div>
     <div class="participant-settings-appearance"><span><i class="bi bi-circle-half"></i> Appearance</span><button type="button" class="participant-theme-toggle" data-theme-toggle aria-pressed="false"><i class="bi bi-moon-stars"></i><span>Dark mode</span></button></div>
     <a href="../logout.php" class="participant-settings-logout"><i class="bi bi-box-arrow-right"></i>Logout</a>
    </div>
   </details>
  </div>
 </div>
</aside>

<header class="participant-mobile-topbar">
 <a class="participant-brand" href="dashboard.php" aria-label="EMIS Participant Dashboard">
  <span class="participant-brand-mark"><i class="bi bi-calendar2-check"></i></span>
  <span class="participant-brand-copy"><strong>EMIS</strong><small>Participant Portal</small></span>
 </a>
 <div class="participant-topbar-actions">
  <a href="dashboard.php?open_notifications=1" class="participant-icon-button position-relative" aria-label="Open notifications">
    <i class="bi bi-bell"></i>
    <?php if (!empty($unreadNotificationCount)): ?><span class="participant-notification-count"><?= (int)$unreadNotificationCount ?></span><?php endif; ?>
  </a>
  <details class="participant-settings participant-mobile-settings">
   <summary aria-label="Open settings"><i class="bi bi-gear"></i></summary>
   <div class="participant-settings-panel">
    <div class="participant-settings-heading">Settings</div>
    <div class="participant-settings-appearance"><span><i class="bi bi-circle-half"></i> Appearance</span><button type="button" class="participant-theme-toggle" data-theme-toggle aria-pressed="false"><i class="bi bi-moon-stars"></i><span>Dark mode</span></button></div>
    <a href="../logout.php" class="participant-settings-logout"><i class="bi bi-box-arrow-right"></i>Logout</a>
   </div>
  </details>
  <details class="participant-mobile-menu">
   <summary aria-label="Open navigation menu"><i class="bi bi-list"></i></summary>
   <div class="participant-mobile-menu-panel">
    <?php foreach ($participantNavItems as [$href, $label, $icon]): ?>
     <a href="<?= htmlspecialchars($href) ?>" class="<?= $currentParticipantPage === $href ? 'is-active' : '' ?>"><i class="bi <?= htmlspecialchars($icon) ?>"></i><?= htmlspecialchars($label) ?></a>
    <?php endforeach; ?>
    <a href="../logout.php"><i class="bi bi-box-arrow-right"></i>Logout</a>
   </div>
  </details>
 </div>
</header>

<script>
(function () {
    const root = document.documentElement;
    const storageKey = 'emis-participant-theme';

    function applyTheme(theme) {
        const selectedTheme = theme === 'light' ? 'light' : 'dark';
        root.setAttribute('data-participant-theme', selectedTheme);

        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            const isLight = selectedTheme === 'light';
            button.setAttribute('aria-pressed', isLight ? 'true' : 'false');
            button.innerHTML = isLight
                ? '<i class="bi bi-sun"></i><span>Light mode</span>'
                : '<i class="bi bi-moon-stars"></i><span>Dark mode</span>';
        });
    }

    let savedTheme = 'dark';
    try {
        savedTheme = localStorage.getItem(storageKey) || 'dark';
    } catch (error) {
        savedTheme = 'dark';
    }

    applyTheme(savedTheme);

    document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            const nextTheme = root.getAttribute('data-participant-theme') === 'light' ? 'dark' : 'light';
            applyTheme(nextTheme);
            try {
                localStorage.setItem(storageKey, nextTheme);
            } catch (error) {
                // Theme remains active for the current page if storage is unavailable.
            }
        });
    });

    document.querySelectorAll('.participant-settings').forEach(function (settings) {
        settings.addEventListener('toggle', function () {
            if (settings.open) {
                document.querySelectorAll('.participant-settings').forEach(function (other) {
                    if (other !== settings) other.open = false;
                });
            }
        });
    });
})();
</script>