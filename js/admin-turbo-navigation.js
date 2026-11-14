(function () {
    if (window.__emisAdminTurboNavigationLoaded) return;
    window.__emisAdminTurboNavigationLoaded = true;

    if (!window.Turbo) return;

    // Let Turbo Drive handle ordinary link clicks itself. A second custom
    // click handler can race Turbo's own listener and cause intermittent stalls.
    window.Turbo.config.forms.mode = 'off';
    window.Turbo.config.drive.progressBarDelay = 1000000;

    document.addEventListener('turbo:load', function () {
        const currentFile = window.location.pathname.split('/').pop() || 'dashboard.php';

        document.querySelectorAll(
            '.admin-sidebar-nav a, .admin-mobile-menu-panel a, .admin-command-results a'
        ).forEach(function (link) {
            const href = link.getAttribute('href');
            if (!href || /^(?:https?:|mailto:|tel:|javascript:)/i.test(href)) return;

            let targetFile;
            try {
                targetFile = new URL(href, window.location.href).pathname.split('/').pop();
            } catch (error) {
                return;
            }

            const active = targetFile === currentFile;
            link.classList.toggle('is-active', active);
            if (active) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });

        document.querySelectorAll('a[href*="logout.php"]').forEach(function (link) {
            link.setAttribute('data-turbo', 'false');
        });
    });
})();
