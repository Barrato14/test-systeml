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

        // Keep sign-out and any non-workspace action as a normal browser navigation.
        document.querySelectorAll('a[href*="logout.php"]').forEach(function (link) {
            link.setAttribute('data-turbo', 'false');
        });
    });

    // Never intercept downloads, external links, anchors, or links explicitly opted out.
    document.addEventListener('click', function (event) {
        const link = event.target.closest('a');
        if (!link) return;
        if (link.hasAttribute('download') || link.target === '_blank' ||
            link.hasAttribute('data-turbo') && link.getAttribute('data-turbo') === 'false') return;
        const href = link.getAttribute('href');
        if (!href || href.startsWith('#')) return;
        let url;
        try { url = new URL(href, window.location.href); } catch (error) { return; }
        if (url.origin !== window.location.origin) return;
        if (/logout\.php(?:$|\?)/i.test(url.pathname + url.search)) {
            link.setAttribute('data-turbo', 'false');
        }
    }, true);
})();