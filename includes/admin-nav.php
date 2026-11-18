<?php
$adminRole = strtolower(trim((string)($_SESSION['user_role'] ?? 'administrator')));
$adminCurrentPage = basename($_SERVER['PHP_SELF'] ?? '');

if ($adminRole === 'event organizer') {
    $adminNavItems = [
        ['dashboard.php', 'Dashboard', 'bi-grid-1x2'],
        ['events.php', 'Event Management', 'bi-calendar-event']
    ];
    $adminHomePage = 'dashboard.php';
    $adminWorkspaceLabel = 'Event Organizer Workspace';
} elseif ($adminRole === 'staff') {
    $adminNavItems = [
        ['dashboard.php', 'Dashboard', 'bi-grid-1x2'],
        ['equipment.php', 'Equipment & Services', 'bi-box-seam'],
        ['attendance-logs.php', 'Attendance Logs', 'bi-clipboard2-check']
    ];
    $adminHomePage = 'dashboard.php';
    $adminWorkspaceLabel = 'Operations Workspace';
} else {
    $adminNavItems = [
        ['dashboard.php', 'Dashboard', 'bi-grid-1x2'],
        ['events.php', 'Event Management', 'bi-calendar-event'],
        ['equipment.php', 'Equipment & Services', 'bi-box-seam'],
        ['users.php', 'User Management', 'bi-people'],
        ['attendance-logs.php', 'Attendance Logs', 'bi-clipboard2-check'],
        ['audit-logs.php', 'Audit Log', 'bi-shield-check']
    ];
    $adminHomePage = 'dashboard.php';
    $adminWorkspaceLabel = 'Administration';
}
$adminPageTitles = [
    'dashboard.php' => 'Dashboard',
    'events.php' => 'Event Management',
    'equipment.php' => 'Equipment & Services',
    'users.php' => 'User Management',
    'attendance-logs.php' => 'Attendance Logs',
    'audit-logs.php' => 'Audit Log'
];
$adminCurrentTitle = $adminPageTitles[$adminCurrentPage] ?? 'Workspace';
?>
<aside id="emis-admin-sidebar" class="admin-sidebar" aria-label="Zentrix workspace navigation">
    <div class="admin-sidebar-inner">
        <a class="admin-brand" href="<?= htmlspecialchars($adminHomePage) ?>" aria-label="Zentrix Workspace">
            <span class="admin-brand-mark"><i class="bi bi-shield-check"></i></span>
            <span class="admin-brand-copy"><strong>ZENTRIX</strong><small><?= htmlspecialchars($adminWorkspaceLabel) ?></small></span>
        </a>
        <div class="admin-nav-caption">WORKSPACE</div>
        <nav class="admin-sidebar-nav">
            <?php foreach ($adminNavItems as [$href, $label, $icon]): ?>
                <a href="<?= htmlspecialchars($href) ?>" class="admin-sidebar-link <?= $adminCurrentPage === $href ? 'is-active' : '' ?>" title="<?= htmlspecialchars($label) ?>" <?= $adminCurrentPage === $href ? 'aria-current="page"' : '' ?>>
                    <i class="bi <?= htmlspecialchars($icon) ?>"></i><span><?= htmlspecialchars($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="admin-sidebar-bottom">
            <div class="admin-sidebar-user">
                <span class="admin-user-avatar"><i class="bi bi-person"></i></span>
                <span class="admin-user-copy"><strong><?= htmlspecialchars($_SESSION['user_name'] ?? 'Administrator') ?></strong><small><?= htmlspecialchars(ucwords($adminRole)) ?></small></span>
            </div>
            <details class="admin-settings">
                <summary class="admin-sidebar-link" title="Settings"><i class="bi bi-gear"></i><span>Settings</span><i class="bi bi-chevron-down admin-settings-chevron"></i></summary>
                <div class="admin-settings-panel">
                    <div class="admin-settings-title">Appearance</div>
                    <button type="button" class="admin-theme-toggle" data-admin-theme-toggle><i class="bi bi-moon-stars"></i><span>Dark mode</span></button>
                    <a href="../logout.php" class="admin-logout-link"><i class="bi bi-box-arrow-right"></i>Sign out</a>
                </div>
            </details>
        </div>
    </div>
</aside>

<header id="emis-admin-mobile-topbar" class="admin-mobile-topbar">
    <a class="admin-mobile-brand" href="<?= htmlspecialchars($adminHomePage) ?>"><span class="admin-brand-mark"><i class="bi bi-shield-check"></i></span><span><strong>ZENTRIX</strong><small><?= htmlspecialchars($adminCurrentTitle) ?></small></span></a>
    <div class="admin-mobile-actions">
        <button type="button" class="admin-icon-button" data-admin-command-open aria-label="Open command palette"><i class="bi bi-search"></i></button>
        <button type="button" class="admin-icon-button" data-admin-mobile-nav aria-label="Toggle navigation" aria-expanded="false"><i class="bi bi-list"></i></button>
    </div>
    <nav class="admin-mobile-menu-panel" data-admin-mobile-panel aria-label="Mobile navigation">
        <?php foreach ($adminNavItems as [$href, $label, $icon]): ?>
            <a href="<?= htmlspecialchars($href) ?>" class="<?= $adminCurrentPage === $href ? 'is-active' : '' ?>"><i class="bi <?= htmlspecialchars($icon) ?>"></i><?= htmlspecialchars($label) ?></a>
        <?php endforeach; ?>
        <button type="button" data-admin-theme-toggle><i class="bi bi-moon-stars"></i><span>Dark mode</span></button>
        <a href="../logout.php"><i class="bi bi-box-arrow-right"></i>Sign out</a>
    </nav>
</header>

<div class="admin-topbar" role="navigation" aria-label="Workspace tools">
    <div class="admin-topbar-context">
        <button type="button" class="admin-icon-button admin-sidebar-toggle" data-admin-sidebar-toggle aria-label="Collapse sidebar" title="Collapse sidebar"><i class="bi bi-layout-sidebar-inset"></i></button>
        <span class="admin-breadcrumb-root">Workspace</span><i class="bi bi-chevron-right"></i><strong><?= htmlspecialchars($adminCurrentTitle) ?></strong>
    </div>
    <div class="admin-topbar-actions">
        <button type="button" class="admin-command-trigger" data-admin-command-open><i class="bi bi-search"></i><span>Search pages and actions...</span><kbd>Ctrl K</kbd></button>
        <button type="button" class="admin-icon-button" data-admin-command-open aria-label="Quick navigation" title="Quick navigation"><i class="bi bi-command"></i></button>
        <details class="admin-settings admin-topbar-settings">
            <summary class="admin-icon-button" aria-label="Appearance settings" title="Appearance settings"><i class="bi bi-sun-moon"></i></summary>
            <div class="admin-settings-panel">
                <div class="admin-settings-title">Appearance</div>
                <button type="button" class="admin-theme-toggle" data-admin-theme-toggle><i class="bi bi-moon-stars"></i><span>Dark mode</span></button>
                <a href="../logout.php" class="admin-logout-link"><i class="bi bi-box-arrow-right"></i>Sign out</a>
            </div>
        </details>
        <div class="admin-topbar-profile"><span class="admin-user-avatar"><i class="bi bi-person"></i></span><span><strong><?= htmlspecialchars($_SESSION['user_name'] ?? 'Administrator') ?></strong><small><?= htmlspecialchars(ucwords($adminRole)) ?></small></span></div>
    </div>
</div>

<div class="admin-command-overlay" data-admin-command-overlay aria-hidden="true">
    <section class="admin-command-dialog" role="dialog" aria-modal="true" aria-labelledby="adminCommandTitle">
        <div class="admin-command-search"><i class="bi bi-search"></i><input type="search" data-admin-command-input id="adminCommandTitle" placeholder="Search workspace pages..." autocomplete="off"><kbd>ESC</kbd></div>
        <div class="admin-command-label">NAVIGATION</div>
        <div class="admin-command-results" data-admin-command-results>
            <?php foreach ($adminNavItems as [$href, $label, $icon]): ?>
                <a href="<?= htmlspecialchars($href) ?>" data-command-label="<?= htmlspecialchars(strtolower($label)) ?>"><i class="bi <?= htmlspecialchars($icon) ?>"></i><span><?= htmlspecialchars($label) ?></span><small>Open page</small><i class="bi bi-arrow-up-right"></i></a>
            <?php endforeach; ?>
        </div>
        <div class="admin-command-footer"><span><kbd>↑</kbd><kbd>↓</kbd> Navigate</span><span><kbd>↵</kbd> Open</span><span><kbd>ESC</kbd> Close</span></div>
    </section>
</div>

<script data-turbo-eval="false">
(function () {
    const root = document.documentElement;
    const themeKey = 'emis-admin-theme';
    const sidebarKey = 'zentrix-admin-sidebar-collapsed';
    const sidebar = document.getElementById('emis-admin-sidebar');
    const overlay = document.querySelector('[data-admin-command-overlay]');
    const commandInput = document.querySelector('[data-admin-command-input]');
    const commandResults = document.querySelector('[data-admin-command-results]');
    const mobileButton = document.querySelector('[data-admin-mobile-nav]');
    const mobilePanel = document.querySelector('[data-admin-mobile-panel]');

    function applyAdminTheme(theme) {
        const selected = theme === 'light' ? 'light' : 'dark';
        root.setAttribute('data-admin-theme', selected);
        document.querySelectorAll('[data-admin-theme-toggle]').forEach(button => {
            button.innerHTML = selected === 'light'
                ? '<i class="bi bi-sun"></i><span>Light mode</span>'
                : '<i class="bi bi-moon-stars"></i><span>Dark mode</span>';
            button.setAttribute('aria-pressed', selected === 'light' ? 'true' : 'false');
        });
    }
    let savedTheme = 'dark';
    try { savedTheme = localStorage.getItem(themeKey) || 'dark'; } catch (error) {}
    applyAdminTheme(savedTheme);
    document.querySelectorAll('[data-admin-theme-toggle]').forEach(button => button.addEventListener('click', () => {
        const next = root.getAttribute('data-admin-theme') === 'light' ? 'dark' : 'light';
        applyAdminTheme(next);
        try { localStorage.setItem(themeKey, next); } catch (error) {}
    }));

    function setCollapsed(collapsed) {
        root.setAttribute('data-admin-sidebar-collapsed', collapsed ? 'true' : 'false');
        const toggle = document.querySelector('[data-admin-sidebar-toggle]');
        if (toggle) {
            toggle.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
            toggle.title = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
        }
        try { localStorage.setItem(sidebarKey, collapsed ? 'true' : 'false'); } catch (error) {}
    }
    let isCollapsed = false;
    try { isCollapsed = localStorage.getItem(sidebarKey) === 'true'; } catch (error) {}
    setCollapsed(isCollapsed);
    document.querySelector('[data-admin-sidebar-toggle]')?.addEventListener('click', () => {
        setCollapsed(root.getAttribute('data-admin-sidebar-collapsed') !== 'true');
    });

    function openCommand() {
        if (!overlay) return;
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        if (commandInput) { commandInput.value = ''; filterCommands(''); window.setTimeout(() => commandInput.focus(), 40); }
    }
    function closeCommand() {
        overlay?.classList.remove('is-open');
        overlay?.setAttribute('aria-hidden', 'true');
    }
    function filterCommands(query) {
        const normalized = query.trim().toLowerCase();
        commandResults?.querySelectorAll('a').forEach(link => {
            link.hidden = !link.dataset.commandLabel.includes(normalized);
        });
    }
    document.querySelectorAll('[data-admin-command-open]').forEach(button => button.addEventListener('click', openCommand));
    commandInput?.addEventListener('input', () => filterCommands(commandInput.value));
    overlay?.addEventListener('click', event => { if (event.target === overlay) closeCommand(); });
    document.addEventListener('keydown', event => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); openCommand(); }
        if (event.key === 'Escape') closeCommand();
        if (overlay?.classList.contains('is-open') && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
            const visible = Array.from(commandResults.querySelectorAll('a:not([hidden])'));
            if (!visible.length) return;
            event.preventDefault();
            const current = visible.indexOf(document.activeElement);
            visible[(current + (event.key === 'ArrowDown' ? 1 : -1) + visible.length) % visible.length].focus();
        }
    });

    mobileButton?.addEventListener('click', () => {
        const open = mobilePanel.classList.toggle('is-open');
        mobileButton.setAttribute('aria-expanded', open ? 'true' : 'false');
        mobileButton.innerHTML = open ? '<i class="bi bi-x-lg"></i>' : '<i class="bi bi-list"></i>';
    });
    document.addEventListener('pointerdown', event => {
        document.querySelectorAll('.admin-settings').forEach(settings => {
            if (settings.open && !settings.contains(event.target)) settings.open = false;
        });
        if (mobilePanel?.classList.contains('is-open') && !mobilePanel.contains(event.target) && !mobileButton?.contains(event.target)) {
            mobilePanel.classList.remove('is-open');
            mobileButton?.setAttribute('aria-expanded', 'false');
            if (mobileButton) mobileButton.innerHTML = '<i class="bi bi-list"></i>';
        }
    });

    const prefetchedPages = new Set();
    document.querySelectorAll('.admin-sidebar-nav a, .admin-mobile-menu-panel a, .admin-brand, .admin-mobile-brand').forEach(link => {
        link.addEventListener('pointerenter', () => {
            const url = new URL(link.href, window.location.href);
            if (url.origin !== window.location.origin || prefetchedPages.has(url.href)) return;
            prefetchedPages.add(url.href);
            const hint = document.createElement('link');
            hint.rel = 'prefetch'; hint.href = url.href; hint.as = 'document';
            document.head.appendChild(hint);
        }, { once: true });
    });
})();
</script>
