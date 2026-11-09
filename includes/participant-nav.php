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
<header class="participant-topbar">
 <div class="participant-topbar-inner">
  <a class="participant-brand" href="dashboard.php" aria-label="EMIS Participant Dashboard">
   <span class="participant-brand-mark"><i class="bi bi-calendar2-check"></i></span>
   <span><strong>EMIS</strong><small>Participant Portal</small></span>
  </a>
  <nav class="participant-desktop-nav" aria-label="Participant navigation">
   <?php foreach ($participantNavItems as [$href, $label, $icon]): ?>
    <a href="<?= htmlspecialchars($href) ?>" class="participant-nav-link <?= $currentParticipantPage === $href ? 'is-active' : '' ?>" <?= $currentParticipantPage === $href ? 'aria-current="page"' : '' ?>>
     <i class="bi <?= htmlspecialchars($icon) ?>"></i><span><?= htmlspecialchars($label) ?></span>
    </a>
   <?php endforeach; ?>
  </nav>
  <div class="participant-topbar-actions">
   <?php if (!empty($showParticipantNotifications)): ?>
    <button type="button" class="participant-icon-button position-relative" data-bs-toggle="modal" data-bs-target="#notificationsModal" aria-label="Open notifications">
     <i class="bi bi-bell"></i>
     <?php if (!empty($unreadNotificationCount)): ?><span class="participant-notification-count"><?= (int)$unreadNotificationCount ?></span><?php endif; ?>
    </button>
   <?php endif; ?>
   <span class="participant-user-name"><i class="bi bi-person-circle"></i><?= htmlspecialchars((string)$participantName) ?></span>
   <a href="../logout.php" class="participant-logout"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
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
 </div>
</header>