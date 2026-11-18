/* Lightweight dashboard motion and metric count-up. No dependencies. */
(function () {
    'use strict';

    const root = document.body;
    if (!root || !root.classList.contains('admin-dashboard-page')) return;

    const revealTargets = root.querySelectorAll(
        '.admin-page-heading, .admin-metrics-grid, .admin-main-content > .card, .admin-main-content > .row'
    );
    revealTargets.forEach(function (element, index) {
        element.setAttribute('data-dashboard-reveal', '');
        element.style.transitionDelay = Math.min(index * 55, 220) + 'ms';
    });

    const reduceMotion = window.matchMedia &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if ('IntersectionObserver' in window && !reduceMotion) {
        const observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            });
        }, { threshold: 0.08, rootMargin: '0px 0px -24px 0px' });

        revealTargets.forEach(function (element) { observer.observe(element); });
    } else {
        revealTargets.forEach(function (element) { element.classList.add('is-visible'); });
    }

    const counters = root.querySelectorAll('.admin-metric-value');
    counters.forEach(function (counter) {
        const original = counter.textContent.trim();
        if (!/^\d+$/.test(original)) return;

        const target = Number(original);
        if (!Number.isSafeInteger(target) || target === 0 || reduceMotion) return;

        const duration = 620;
        const start = performance.now();
        counter.textContent = '0';

        function tick(now) {
            const progress = Math.min((now - start) / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            counter.textContent = String(Math.round(target * eased));
            if (progress < 1) requestAnimationFrame(tick);
        }

        requestAnimationFrame(tick);
    });
})();