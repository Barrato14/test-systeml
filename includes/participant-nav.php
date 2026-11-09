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
   <?php if (!empty($showParticipantNotifications)): ?>
    <button type="button" class="participant-sidebar-link participant-sidebar-notifications" data-bs-toggle="modal" data-bs-target="#notificationsModal" title="Notifications">
     <i class="bi bi-bell"></i><span>Notifications</span>
     <?php if (!empty($unreadNotificationCount)): ?><span class="participant-notification-count"><?= (int)$unreadNotificationCount ?></span><?php endif; ?>
    </button>
   <?php endif; ?>
   <div class="participant-sidebar-profile"><i class="bi bi-person-circle"></i><span><?= htmlspecialchars((string)$participantName) ?></span></div>
   <a href="../logout.php" class="participant-sidebar-link participant-sidebar-logout" title="Logout"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
  </div>
 </div>
</aside>

<header class="participant-mobile-topbar">
 <a class="participant-brand" href="dashboard.php" aria-label="EMIS Participant Dashboard">
  <span class="participant-brand-mark"><i class="bi bi-calendar2-check"></i></span>
  <span class="participant-brand-copy"><strong>EMIS</strong><small>Participant Portal</small></span>
 </a>
 <div class="participant-topbar-actions">
  <?php if (!empty($showParticipantNotifications)): ?>
   <button type="button" class="participant-icon-button position-relative" data-bs-toggle="modal" data-bs-target="#notificationsModal" aria-label="Open notifications">
    <i class="bi bi-bell"></i>
    <?php if (!empty($unreadNotificationCount)): ?><span class="participant-notification-count"><?= (int)$unreadNotificationCount ?></span><?php endif; ?>
   </button>
  <?php endif; ?>
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