/**
 * Mowology Sync Status
 * ══════════════════════════════════════════════════════════════════════
 * Visible, persistent surface for offline state and pending sync work.
 *
 * Two UI elements, both self-mounting on document load:
 *
 *  1. Offline banner (.mw-sync-banner)
 *     Full-width red pill below the top bar that appears whenever
 *     navigator.onLine === false. Tells the user their work is safe
 *     and will sync when they reconnect.
 *
 *  2. Pending sync badge (.mw-sync-badge)
 *     Small counter next to the avatar area showing how many
 *     receipt / photo / action items are queued in IndexedDB and
 *     waiting to upload. Clicking it opens the offline.html page.
 *
 * Queue sources (IndexedDB databases polled every 8 s, driven by a
 * postMessage from the service worker when sync events fire):
 *   - mowology-receipts → pending-receipts
 *   - mowology-photo-queue → uploads (status in [pending, failed])
 *   - mowology-actions → pending-actions
 *
 * Accessibility
 *   - Both elements use role="status" + aria-live="polite" so the
 *     count change is announced by TalkBack without interrupting.
 *   - Banner gets role="alert" (assertive) so the user notices it
 *     immediately when connectivity drops.
 *
 * Safe to include on every CRM page. Does nothing if no queue IDBs
 * exist yet (new install).
 */
(function () {
    'use strict';

    if (typeof window === 'undefined') return;
    if (window.MwSyncStatus) return; // idempotent

    var POLL_INTERVAL = 8000; // 8s — balance freshness vs battery
    var pollTimer = null;
    var banner = null;
    var badge = null;
    var lastCount = -1;
    var lastOnline = true;

    // ── DOM helpers ───────────────────────────────────────
    function createBanner() {
        var el = document.createElement('div');
        el.className = 'mw-sync-banner';
        el.setAttribute('role', 'alert');
        el.setAttribute('aria-live', 'assertive');
        el.innerHTML =
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">' +
            '<line x1="1" y1="1" x2="23" y2="23"/>' +
            '<path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55"/>' +
            '<path d="M5 12.55a10.94 10.94 0 0 1 5.17-2.39"/>' +
            '<path d="M10.71 5.05A16 16 0 0 1 22.56 9"/>' +
            '<path d="M1.42 9a15.91 15.91 0 0 1 4.7-2.88"/>' +
            '<path d="M8.53 16.11a6 6 0 0 1 6.95 0"/>' +
            '<circle cx="12" cy="20" r="1" fill="currentColor" stroke="none"/>' +
            '</svg>' +
            '<span class="mw-sync-banner-text">Offline — your work is safe and will sync when you reconnect</span>';
        document.body.appendChild(el);
        return el;
    }

    function createBadge() {
        var el = document.createElement('a');
        el.className = 'mw-sync-badge';
        el.setAttribute('role', 'status');
        el.setAttribute('aria-live', 'polite');
        el.setAttribute('aria-label', 'Pending sync items');
        el.href = '/crm/offline.html';
        el.innerHTML =
            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">' +
            '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><polyline points="21 3 21 8 16 8"/>' +
            '</svg>' +
            '<span class="mw-sync-badge-count">0</span>';
        el.style.display = 'none';
        document.body.appendChild(el);
        return el;
    }

    // ── IndexedDB helpers ─────────────────────────────────
    function countObjectStore(dbName, storeName, filter) {
        return new Promise(function (resolve) {
            if (!('indexedDB' in window)) return resolve(0);
            var req;
            try { req = indexedDB.open(dbName); }
            catch (e) { return resolve(0); }

            req.onerror = function () { resolve(0); };
            req.onsuccess = function (e) {
                var db = e.target.result;
                if (!db.objectStoreNames.contains(storeName)) {
                    db.close();
                    return resolve(0);
                }
                try {
                    var tx = db.transaction(storeName, 'readonly');
                    var store = tx.objectStore(storeName);
                    if (filter) {
                        // Walk records and apply the filter.
                        var total = 0;
                        var cur = store.openCursor();
                        cur.onsuccess = function (ev) {
                            var c = ev.target.result;
                            if (c) {
                                if (filter(c.value)) total++;
                                c.continue();
                            } else {
                                db.close();
                                resolve(total);
                            }
                        };
                        cur.onerror = function () { db.close(); resolve(0); };
                    } else {
                        var cnt = store.count();
                        cnt.onsuccess = function () { db.close(); resolve(cnt.result || 0); };
                        cnt.onerror = function () { db.close(); resolve(0); };
                    }
                } catch (err) {
                    try { db.close(); } catch (_) {}
                    resolve(0);
                }
            };
        });
    }

    function getPendingCount() {
        return Promise.all([
            countObjectStore('mowology-receipts', 'pending-receipts'),
            countObjectStore('mowology-photo-queue', 'uploads', function (r) {
                return r.status === 'pending' || r.status === 'failed';
            }),
            countObjectStore('mowology-actions', 'pending-actions'),
        ]).then(function (counts) {
            return counts.reduce(function (a, b) { return a + b; }, 0);
        });
    }

    // ── Render ────────────────────────────────────────────
    function renderOnline() {
        var online = navigator.onLine !== false;
        if (online === lastOnline) return;
        lastOnline = online;
        if (!banner) banner = createBanner();
        if (online) {
            banner.classList.remove('mw-sync-banner--visible');
        } else {
            banner.classList.add('mw-sync-banner--visible');
        }
    }

    function renderCount(count) {
        if (count === lastCount) return;
        lastCount = count;
        if (!badge) badge = createBadge();
        var label = badge.querySelector('.mw-sync-badge-count');
        if (label) label.textContent = String(count);
        if (count > 0) {
            badge.style.display = '';
            badge.setAttribute('aria-label', count + ' item' + (count !== 1 ? 's' : '') + ' pending sync');
        } else {
            badge.style.display = 'none';
        }
    }

    function refresh() {
        renderOnline();
        getPendingCount().then(renderCount).catch(function () { renderCount(0); });
    }

    // ── Lifecycle ─────────────────────────────────────────
    function start() {
        renderOnline();
        refresh();
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(refresh, POLL_INTERVAL);
    }

    function stop() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    window.addEventListener('online', renderOnline);
    window.addEventListener('offline', renderOnline);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') refresh();
    });

    // SW can nudge us when a sync completes so the badge drops immediately.
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', function (e) {
            if (!e || !e.data) return;
            var t = e.data.type;
            if (t === 'receipts-synced' || t === 'photo-queue-synced' || t === 'actions-synced') {
                refresh();
            }
        });
    }

    // Defer init until DOM is ready so we have <body> to mount on.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }

    window.MwSyncStatus = {
        refresh: refresh,
        stop: stop,
        getPendingCount: getPendingCount,
    };
}());
