<?php
$adminRole = strtolower(trim((string)($_SESSION['user_role'] ?? 'administrator')));
$adminCurrentPage = basename($_SERVER['PHP_SELF'] ?? '');

if ($adminRole === 'event organizer') {
    $adminNavItems = [
        ['dashboard.php', 'Dashboard', 'bi-speedometer2'],
        ['events.php', 'Event Management', 'bi-calendar-event']
    ];
    $adminHomePage = 'dashboard.php';
    $adminWorkspaceLabel = 'Event Organizer Workspace';
} elseif ($adminRole === 'staff') {
    $adminNavItems = [
        ['dashboard.php', 'Dashboard', 'bi-speedometer2'],
        ['equipment.php', 'Equipment & Services', 'bi-tools'],
        ['attendance-logs.php', 'Attendance Logs', 'bi-person-bounding-box']
    ];
    $adminHomePage = 'dashboard.php';
    $adminWorkspaceLabel = 'Event Organizer Workspace';
} else {
    $adminNavItems = [
        ['dashboard.php', 'Dashboard', 'bi-speedometer2'],
        ['events.php', 'Event Management', 'bi-calendar-event'],
        ['equipment.php', 'Equipment & Services', 'bi-tools'],
        ['users.php', 'User Management', 'bi-people'],
        ['attendance-logs.php', 'Attendance Logs', 'bi-person-bounding-box']
    ];
    $adminHomePage = 'dashboard.php';
    $adminWorkspaceLabel = 'Admin Command Center';
}
?>
<aside id="emis-admin-sidebar" data-turbo-permanent class="admin-sidebar" aria-label="EMIS workspace navigation">
    <div class="admin-sidebar-inner">
        <a class="admin-brand" href="<?= htmlspecialchars($adminHomePage) ?>" aria-label="EMIS Workspace">
            <span class="admin-brand-mark"><i class="bi bi-shield-check"></i></span>
            <span class="admin-brand-copy"><strong>EMIS</strong><small><?= htmlspecialchars($adminWorkspaceLabel) ?></small></span>
        </a>
        <div class="admin-nav-caption">WORKSPACE</div>
        <nav class="admin-sidebar-nav">
            <?php foreach ($adminNavItems as [$href, $label, $icon]): ?>
                <a href="<?= htmlspecialchars($href) ?>" class="admin-sidebar-link <?= $adminCurrentPage === $href ? 'is-active' : '' ?>" title="<?= htmlspecialchars($label) ?>">
                    <i class="bi <?= htmlspecialchars($icon) ?>"></i><span><?= htmlspecialchars($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="admin-sidebar-bottom">
            <button type="button" class="admin-sidebar-link admin-command-trigger" data-open-admin-command title="Quick switcher">
                <i class="bi bi-command"></i><span>Quick Switcher</span><kbd>Ctrl K</kbd>
            </button>
            <div class="admin-sidebar-user">
                <i class="bi bi-person-badge"></i>
                <span><strong><?= htmlspecialchars($_SESSION['user_name'] ?? 'Administrator') ?></strong><small><?= htmlspecialchars(ucwords($adminRole)) ?></small></span>
            </div>
            <details class="admin-settings">
                <summary class="admin-sidebar-link" title="Settings"><i class="bi bi-gear"></i><span>Settings</span></summary>
                <div class="admin-settings-panel">
                    <div class="admin-settings-title">Appearance</div>
                    <button type="button" class="admin-theme-toggle" data-admin-theme-toggle><i class="bi bi-moon-stars"></i><span>Dark mode</span></button>
                    <a href="../logout.php" class="admin-logout-link"><i class="bi bi-box-arrow-right"></i>Sign out</a>
                </div>
            </details>
        </div>
    </div>
</aside>

<header id="emis-admin-mobile-topbar" data-turbo-permanent class="admin-mobile-topbar">
    <a class="admin-mobile-brand" href="<?= htmlspecialchars($adminHomePage) ?>"><span class="admin-brand-mark"><i class="bi bi-shield-check"></i></span><span><strong>EMIS</strong><small><?= $adminRole === 'event organizer' ? 'Organizer' : 'Admin' ?></small></span></a>
    <div class="admin-mobile-actions">
        <button type="button" class="admin-mobile-icon" data-open-admin-command aria-label="Quick switcher"><i class="bi bi-search"></i></button>
        <details class="admin-mobile-settings">
            <summary aria-label="Settings"><i class="bi bi-gear"></i></summary>
            <div class="admin-settings-panel">
                <div class="admin-settings-title">Appearance</div>
                <button type="button" class="admin-theme-toggle" data-admin-theme-toggle><i class="bi bi-moon-stars"></i><span>Dark mode</span></button>
                <a href="../logout.php" class="admin-logout-link"><i class="bi bi-box-arrow-right"></i>Sign out</a>
            </div>
        </details>
        <details class="admin-mobile-menu">
            <summary aria-label="Open navigation"><i class="bi bi-list"></i></summary>
            <nav class="admin-mobile-menu-panel">
                <?php foreach ($adminNavItems as [$href, $label, $icon]): ?>
                    <a href="<?= htmlspecialchars($href) ?>" class="<?= $adminCurrentPage === $href ? 'is-active' : '' ?>"><i class="bi <?= htmlspecialchars($icon) ?>"></i><?= htmlspecialchars($label) ?></a>
                <?php endforeach; ?>
                <button type="button" data-open-admin-command><i class="bi bi-command"></i>Quick Switcher</button>
            </nav>
        </details>
    </div>
</header>

<div id="adminCommandOverlay" data-turbo-permanent class="admin-command-overlay" aria-hidden="true">
    <section class="admin-command-dialog" role="dialog" aria-modal="true" aria-labelledby="adminCommandTitle">
        <div class="admin-command-search"><i class="bi bi-search"></i><input id="adminCommandInput" type="search" placeholder="Search workspace pages..." autocomplete="off"><kbd>ESC</kbd></div>
        <div class="admin-command-heading" id="adminCommandTitle">QUICK NAVIGATION</div>
        <nav class="admin-command-results" id="adminCommandResults">
            <?php foreach ($adminNavItems as [$href, $label, $icon]): ?>
                <a href="<?= htmlspecialchars($href) ?>" data-command-label="<?= htmlspecialchars(strtolower($label)) ?>"><i class="bi <?= htmlspecialchars($icon) ?>"></i><span><?= htmlspecialchars($label) ?></span><i class="bi bi-arrow-up-right"></i></a>
            <?php endforeach; ?>
        </nav>
        <div class="admin-command-hint">Navigate faster with <kbd>Ctrl</kbd> + <kbd>K</kbd></div>
    </section>
</div>

<script data-turbo-eval="false">
(function () {
    // Warm same-origin workspace pages before a click to reduce navigation delay.
    const prefetchedPages = new Set();
    function prefetchWorkspaceLink(link) {
        if (!link || link.target === '_blank' || link.hasAttribute('download')) return;
        const href = link.getAttribute('href');
        if (!href || href.startsWith('#') || /^(?:https?:|mailto:|tel:|javascript:)/i.test(href)) return;
        let url;
        try { url = new URL(href, window.location.href); } catch (error) { return; }
        if (url.origin !== window.location.origin || prefetchedPages.has(url.href)) return;
        prefetchedPages.add(url.href);
        const hint = document.createElement('link');
        hint.rel = 'prefetch';
        hint.href = url.href;
        hint.as = 'document';
        document.head.appendChild(hint);
    }
    document.addEventListener('pointerover', function (event) {
        const link = event.target.closest('.admin-sidebar-nav a, .admin-mobile-menu-panel a, .admin-command-results a, .admin-brand, .admin-mobile-brand');
        if (link) prefetchWorkspaceLink(link);
    });
    document.addEventListener('focusin', function (event) {
        const link = event.target.closest('.admin-sidebar-nav a, .admin-mobile-menu-panel a, .admin-command-results a, .admin-brand, .admin-mobile-brand');
        if (link) prefetchWorkspaceLink(link);
    });

    const root = document.documentElement;
    const themeKey = 'emis-admin-theme';
    function applyAdminTheme(theme) {
        const selected = theme === 'light' ? 'light' : 'dark';
        root.setAttribute('data-admin-theme', selected);
        document.querySelectorAll('[data-admin-theme-toggle]').forEach(function (button) {
            button.innerHTML = selected === 'light'
                ? '<i class="bi bi-sun"></i><span>Light mode</span>'
                : '<i class="bi bi-moon-stars"></i><span>Dark mode</span>';
            button.setAttribute('aria-pressed', selected === 'light' ? 'true' : 'false');
        });
    }
    let savedTheme = 'dark';
    try { savedTheme = localStorage.getItem(themeKey) || 'dark'; } catch (error) {}
    applyAdminTheme(savedTheme);
    document.querySelectorAll('[data-admin-theme-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            const next = root.getAttribute('data-admin-theme') === 'light' ? 'dark' : 'light';
            applyAdminTheme(next);
            try { localStorage.setItem(themeKey, next); } catch (error) {}
        });
    });

    const overlay = document.getElementById('adminCommandOverlay');
    const input = document.getElementById('adminCommandInput');
    const results = document.getElementById('adminCommandResults');
    function openCommand() {
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        input.value = '';
        filterCommands();
        window.setTimeout(function () { input.focus(); }, 30);
    }
    function closeCommand() {
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
    }
    function filterCommands() {
        const query = input.value.trim().toLowerCase();
        results.querySelectorAll('a').forEach(function (link) {
            link.hidden = !link.dataset.commandLabel.includes(query);
        });
    }
    document.querySelectorAll('[data-open-admin-command]').forEach(function (button) {
        button.addEventListener('click', openCommand);
    });
    input.addEventListener('input', filterCommands);
    overlay.addEventListener('click', function (event) {
        if (event.target === overlay) closeCommand();
    });
    document.addEventListener('keydown', function (event) {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            openCommand();
        }
        if (event.key === 'Escape') closeCommand();
    });
    document.querySelectorAll('.admin-settings, .admin-mobile-settings').forEach(function (settings) {
        settings.addEventListener('toggle', function () {
            if (settings.open) {
                document.querySelectorAll('.admin-settings, .admin-mobile-settings').forEach(function (other) {
                    if (other !== settings) other.open = false;
                });
            }
        });
    });
    document.addEventListener('pointerdown', function (event) {
        document.querySelectorAll('.admin-settings, .admin-mobile-settings').forEach(function (settings) {
            if (settings.open && !settings.contains(event.target)) settings.open = false;
        });
    });
})();
</script>

<script src="https://cdn.jsdelivr.net/npm/@hotwired/turbo@8.0.12/dist/turbo.es2017-umd.js" data-turbo-eval="false"></script>
<script src="../js/admin-turbo-navigation.js" data-turbo-eval="false"></script>
