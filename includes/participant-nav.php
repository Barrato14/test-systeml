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
    // My Borrowings is intentionally omitted from the shared navigation.
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
   <button type="button" class="participant-sidebar-link participant-sidebar-notifications" data-open-notifications title="Notifications"><i class="bi bi-bell"></i><span>Notifications</span><span class="participant-notification-count" data-unread-count hidden></span></button>
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
  <button type="button" class="participant-icon-button position-relative" data-open-notifications aria-label="Open notifications"><i class="bi bi-bell"></i><span class="participant-notification-count" data-unread-count hidden></span></button>
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
<div class="participant-notifications-overlay" id="participantNotificationsOverlay" aria-hidden="true">
 <section class="participant-notifications-dialog" role="dialog" aria-modal="true" aria-labelledby="participantNotificationsTitle">
  <header class="participant-notifications-header"><div><span class="portal-page-eyebrow">YOUR UPDATES</span><h2 id="participantNotificationsTitle">Notifications</h2><p><span data-unread-label>0 unread notifications</span></p></div><button type="button" class="participant-notifications-close" data-close-notifications aria-label="Close notifications"><i class="bi bi-x-lg"></i></button></header>
  <div class="participant-notifications-list" id="participantNotificationsList"><div class="participant-notifications-loading">Loading notifications...</div></div>
 </section>
</div>
<script>
(function(){
 const overlay=document.getElementById('participantNotificationsOverlay');
 const list=document.getElementById('participantNotificationsList');
 if(!overlay||!list)return;
 let loaded=false;
 function close(){overlay.classList.remove('is-open');overlay.setAttribute('aria-hidden','true');document.body.classList.remove('notifications-open');}
 function open(){overlay.classList.add('is-open');overlay.setAttribute('aria-hidden','false');document.body.classList.add('notifications-open');loadNotifications();}
 function updateCount(count){
  document.querySelectorAll('[data-unread-count]').forEach(function(el){el.textContent=count>99?'99+':String(count);el.hidden=count<1;});
  document.querySelectorAll('[data-unread-label]').forEach(function(el){el.textContent=count+' unread notification'+(count===1?'':'s');});
 }
 function escapeHtml(value){return String(value??'').replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
 function render(items){
  const unread=items.filter(function(item){return item.is_read!==true&&item.is_read!=='true';}).length;
  updateCount(unread);
  if(!items.length){list.innerHTML='<div class="participant-notifications-empty"><i class="bi bi-inbox"></i><strong>You are all caught up</strong><span>You do not have any notifications yet.</span></div>';return;}
  list.innerHTML=items.map(function(item){
   const isRead=item.is_read===true||item.is_read==='true';
   const date=item.created_at?new Date(item.created_at).toLocaleString('en-PH',{timeZone:'Asia/Manila',month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'}):'Date unavailable';
   return '<article class="participant-notification-item '+(isRead?'is-read':'is-unread')+'" data-notification-id="'+escapeHtml(item.id)+'"><div class="participant-notification-dot"></div><div class="participant-notification-copy"><div class="participant-notification-title">'+escapeHtml(item.title||'Notification')+(isRead?'':' <span>NEW</span>')+'</div><p>'+escapeHtml(item.message||'')+'</p><time>'+escapeHtml(date)+' PHT</time></div>'+(isRead?'':'<button type="button" class="participant-notification-mark" data-mark-read="'+escapeHtml(item.id)+'" aria-label="Mark as read"><i class="bi bi-check2"></i></button>')+'</article>';
  }).join('');
 }
 async function loadNotifications(force){
  if(loaded&&!force)return;
  list.innerHTML='<div class="participant-notifications-loading"><i class="bi bi-arrow-repeat"></i> Loading notifications...</div>';
  try{
   const response=await fetch('notifications-data.php',{headers:{'Accept':'application/json'},cache:'no-store'});
   const payload=await response.json();
   if(!response.ok||!payload.success)throw new Error(payload.message||'Unable to load notifications.');
   render(payload.notifications||[]);loaded=true;
  }catch(error){list.innerHTML='<div class="participant-notifications-error">'+escapeHtml(error.message||'Unable to load notifications.')+' <button type="button" data-retry-notifications>Try again</button></div>';}
 }
 // Warm participant pages when the user points at a navigation link.
 const prefetchedPages=new Set();
 document.querySelectorAll('.participant-sidebar-nav a,.participant-mobile-menu-panel a').forEach(function(link){
  function prefetchPage(){
   const href=link.getAttribute('href');
   if(!href||href.charAt(0)==='#'||prefetchedPages.has(href)||href===window.location.pathname.split('/').pop())return;
   prefetchedPages.add(href);
   const hint=document.createElement('link');
   hint.rel='prefetch';
   hint.href=href;
   hint.as='document';
   document.head.appendChild(hint);
  }
  link.addEventListener('pointerenter',prefetchPage,{once:true});
  link.addEventListener('focus',prefetchPage,{once:true});
 });
 document.querySelectorAll('[data-open-notifications]').forEach(function(button){button.addEventListener('click',open);});
 document.querySelectorAll('[data-close-notifications]').forEach(function(button){button.addEventListener('click',close);});
 overlay.addEventListener('click',function(event){if(event.target===overlay)close();});
 document.addEventListener('keydown',function(event){if(event.key==='Escape'&&overlay.classList.contains('is-open'))close();});
 list.addEventListener('click',async function(event){
  const retry=event.target.closest('[data-retry-notifications]');
  if(retry){loadNotifications(true);return;}
  const button=event.target.closest('[data-mark-read]');
  if(!button)return;
  button.disabled=true;
  try{
   const response=await fetch('notification-action.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},body:new URLSearchParams({notification_id:button.dataset.markRead})});
   const payload=await response.json();
   if(!response.ok||!payload.success)throw new Error(payload.message||'Unable to update notification.');
   loaded=false;loadNotifications(true);
  }catch(error){button.disabled=false;button.title=error.message;}
 });
 document.addEventListener('DOMContentLoaded',function(){fetch('notifications-data.php',{headers:{'Accept':'application/json'},cache:'no-store'}).then(r=>r.json()).then(p=>{if(p.success){updateCount((p.notifications||[]).filter(n=>n.is_read!==true&&n.is_read!=='true').length);}}).catch(function(){});});
})();
</script>
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