/**
 * Service Worker registration — shared across AppStack and standalone pages.
 * ───────────────────────────────────────────────────────────────────────────
 * Registers /service-worker.php (the PHP wrapper that injects a dynamic
 * CACHE_VERSION). On first run after the March 2026 migration, unregisters
 * any legacy /service-worker.js registration so the wrapper takes over
 * cleanly — safe to leave in place long term.
 *
 * Included from:
 *   - public/crm/includes/appstack_footer.php (all AppStack pages)
 *   - public/crm/app-launch.php, homebase.php, driver-log.php,
 *     driver-log-post.php (standalone driver pages — no AppStack chrome)
 *
 * Intentionally no-op in environments without serviceWorker support.
 */
(function () {
    'use strict';

    if (!('serviceWorker' in navigator)) return;

    window.addEventListener('load', function () {
        // One-time cleanup of legacy registrations:
        //   1. /service-worker.js  at scope '/'    (pre-March 2026)
        //   2. /crm/sw.js          at scope '/crm/' (legacy duplicate, retired April 2026)
        // Both are replaced by /service-worker.php at scope '/'.
        navigator.serviceWorker.getRegistrations().then(function (regs) {
            regs.forEach(function (r) {
                try {
                    var url = (r.active && r.active.scriptURL) ||
                              (r.installing && r.installing.scriptURL) || '';
                    if (!url) return;
                    var isLegacyRoot = url.indexOf('/service-worker.js') !== -1 &&
                                       url.indexOf('.php') === -1;
                    var isLegacyCrm  = /\/crm\/sw\.js(\?|$)/.test(url);
                    if (isLegacyRoot || isLegacyCrm) {
                        r.unregister();
                    }
                } catch (e) { /* ignore */ }
            });
        }).catch(function () { /* ignore */ });

        navigator.serviceWorker.register('/service-worker.php', { scope: '/' })
            .then(function (reg) {
                // Poll for updates every 30 minutes while the page is open
                setInterval(function () { reg.update(); }, 1800000);
            })
            .catch(function () { /* non-critical — app works without SW */ });

        // When a new SW takes over (skipWaiting + clients.claim), reload so
        // the page picks up fresh CSS/JS from the new cache instead of the
        // stale session.
        navigator.serviceWorker.addEventListener('controllerchange', function () {
            window.location.reload();
        });
    });
}());
