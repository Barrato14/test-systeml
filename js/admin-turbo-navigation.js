(function () {
    if (window.__emisAdminTurboNavigationLoaded) return;
    window.__emisAdminTurboNavigationLoaded = true;

    if (!window.Turbo) return;

    // Keep the existing PHP POST handlers untouched. Only portal links use Turbo.
    window.Turbo.config.forms.mode = 'off';
    window.Turbo.config.drive.progressBarDelay = 1000000;

    document.addEventListener('turbo:load', function () {
        const currentFile = window.location.pathname.split('/').pop() || 'dashboard.php';
        document.querySelectorAll('.admin-sidebar-nav a, .admin-mobile-menu-panel a, .admin-command-results a').forEach(function (link) {
            const href = link.getAttribute('href');
            if (!href || /^(?:https?:|mailto:|tel:|javascript:)/i.test(href)) return;
            const targetFile = new URL(href, window.location.href).pathname.split('/').pop();
            const active = targetFile === currentFile;
            link.classList.toggle('is-active', active);
            if (active) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });

        document.querySelectorAll('a[href*="logout.php"]').forEach(function (link) {
            link.setAttribute('data-turbo', 'false');
        });
    });

    // Explicitly route workspace clicks through Turbo. This avoids intermittent
    // missed clicks caused by competing delegated handlers in the shared sidebar.
    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 ||
            event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        const link = event.target.closest(
            '.admin-sidebar-nav a, .admin-mobile-menu-panel a, .admin-command-results a, .admin-brand, .admin-mobile-brand'
        );
        if (!link || link.hasAttribute('download') || link.target === '_blank' ||
            link.getAttribute('data-turbo') === 'false') return;

        const href = link.getAttribute('href');
        if (!href || href.startsWith('#')) return;

        let url;
        try { url = new URL(href, window.location.href); } catch (error) { return; }
        if (url.origin !== window.location.origin ||
            /logout\.php(?:$|\?)/i.test(url.pathname + url.search)) return;

        if (url.href === window.location.href) {
            event.preventDefault();
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        window.Turbo.visit(url.href);
    }, true);
})();
