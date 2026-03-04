/**
 * Mowology CRM — Offline Action Queue
 * ─────────────────────────────────────
 * When the device is offline, intercepts fetch() calls to critical API
 * endpoints and stores them in IndexedDB. Automatically replays them
 * when connectivity is restored — via Background Sync API where supported
 * (Android Chrome), and via online/visibilitychange/focus events on iOS.
 *
 * Database: mowology-actions   (version 1)
 * Store:    pending-actions    (keyPath: id, autoIncrement)
 *
 * Each record:
 *   { id, endpoint, method, body, bodyRaw, headers, label, timestamp, retries }
 *
 * Queued endpoints (POST only — GET/status calls pass through normally):
 *   /crm/api/time-clock.php   — clock in / clock out
 *   /crm/api/pow-actions.php  — start visit / end visit / save data
 *   /crm/api/job-timer.php    — start timer / stop timer
 *
 * Usage (automatic — no manual init required):
 *   window.OfflineActions.syncNow()         — manually trigger replay
 *   window.OfflineActions.getPendingCount() — Promise<number>
 *   window.OfflineActions.updateUI()        — refresh badge + strip
 *
 * iOS PWA note: Background Sync is not supported on Safari. The module
 * compensates via online, visibilitychange, and focus event triggers.
 *
 * Android Capacitor note: navigator.onLine is unreliable in WebViews.
 * MwNative.network.onStatusChange is used instead when available.
 */
(function () {
    'use strict';

    var DB_NAME    = 'mowology-actions';
    var DB_VERSION = 1;
    var STORE_NAME = 'pending-actions';
    var MAX_RETRIES = 5;

    // POST requests to these endpoints are queued when offline.
    // GET requests (status checks) always pass through — they need live data.
    var QUEUED_ENDPOINTS = [
        '/crm/api/time-clock.php',
        '/crm/api/pow-actions.php',
        '/crm/api/job-timer.php'
    ];

    var _db = null;

    // ── IndexedDB helpers ────────────────────────────────────────────────────────

    function openDB() {
        return new Promise(function (resolve, reject) {
            if (_db) { resolve(_db); return; }
            if (!window.indexedDB) { reject(new Error('IndexedDB not supported')); return; }

            var req = indexedDB.open(DB_NAME, DB_VERSION);

            req.onupgradeneeded = function (e) {
                var database = e.target.result;
                if (!database.objectStoreNames.contains(STORE_NAME)) {
                    database.createObjectStore(STORE_NAME, { keyPath: 'id', autoIncrement: true });
                }
            };

            req.onsuccess = function (e) {
                _db = e.target.result;
                resolve(_db);
            };

            req.onerror = function () {
                reject(new Error('Failed to open IndexedDB'));
            };
        });
    }

    function addRecord(record) {
        return openDB().then(function (database) {
            return new Promise(function (resolve, reject) {
                var tx  = database.transaction(STORE_NAME, 'readwrite');
                var req = tx.objectStore(STORE_NAME).add(record);
                req.onsuccess = function () { resolve(req.result); };
                req.onerror   = function () { reject(req.error); };
            });
        });
    }

    function getAllRecords() {
        return openDB().then(function (database) {
            return new Promise(function (resolve) {
                var tx  = database.transaction(STORE_NAME, 'readonly');
                var req = tx.objectStore(STORE_NAME).getAll();
                req.onsuccess = function () { resolve(req.result || []); };
                req.onerror   = function () { resolve([]); };
            });
        });
    }

    function deleteRecord(id) {
        return openDB().then(function (database) {
            return new Promise(function (resolve) {
                var tx = database.transaction(STORE_NAME, 'readwrite');
                tx.objectStore(STORE_NAME).delete(id);
                tx.oncomplete = function () { resolve(); };
                tx.onerror    = function () { resolve(); };
            });
        });
    }

    function getPendingCount() {
        return openDB().then(function (database) {
            return new Promise(function (resolve) {
                var tx  = database.transaction(STORE_NAME, 'readonly');
                var req = tx.objectStore(STORE_NAME).count();
                req.onsuccess = function () { resolve(req.result); };
                req.onerror   = function () { resolve(0); };
            });
        }).catch(function () { return 0; });
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────

    /**
     * Check connectivity — prefers the Capacitor Network plugin over
     * navigator.onLine which is unreliable in Android WebViews.
     */
    function isOnline() {
        if (window.MwNative && typeof window.MwNative.network === 'object') {
            return window.MwNative.network.isOnline !== false;
        }
        return navigator.onLine !== false;
    }

    /** Human-readable label for an action, used in UI indicators. */
    function guessLabel(url, body) {
        if (url.indexOf('time-clock') !== -1) {
            if (body && body.action === 'clock_in')  return 'Clock In';
            if (body && body.action === 'clock_out') return 'Clock Out';
            return 'Time Clock';
        }
        if (url.indexOf('pow-actions') !== -1) {
            if (body && body.action === 'start_visit') return 'Start Visit';
            if (body && body.action === 'end_visit')   return 'Complete Visit';
            if (body && body.action === 'save_notes')  return 'Save Notes';
            return 'Visit Update';
        }
        if (url.indexOf('job-timer') !== -1) {
            if (body && body.action === 'start') return 'Start Timer';
            if (body && body.action === 'stop')  return 'Stop Timer';
            return 'Job Timer';
        }
        return 'Action';
    }

    /**
     * Build a plausible fake success response so the calling page's
     * `.then(function(data) { if (data.success) ... })` branches run
     * and update the UI optimistically while the real action is queued.
     *
     * visit_id / entry_id of 0 signals an offline-originated action.
     * The schedule-pill-workflow.js and time-clock-widget.js store these
     * values but only use entry_id for display — replay overwrites them
     * server-side with real values once connectivity is restored.
     */
    function buildOptimisticResponse(url, body) {
        var base = { success: true, offline: true, queued: true };

        if (url.indexOf('job-timer') !== -1) {
            if (body && body.action === 'start') {
                return Object.assign({}, base, {
                    entry_id:       0,
                    tracking_level: 'standard',
                    require_photos: false
                });
            }
            // stop / complete
            return Object.assign({}, base, { duration_formatted: '—' });
        }

        if (url.indexOf('time-clock') !== -1) {
            if (body && body.action === 'clock_in') {
                return Object.assign({}, base, { entry_id: 0, clocked_in: true });
            }
            // clock_out
            return Object.assign({}, base, { clocked_in: false });
        }

        // pow-actions: start_visit, end_visit, save_notes, etc.
        return base;
    }

    // ── Queue & replay ───────────────────────────────────────────────────────────

    function queueAction(url, options) {
        var bodyStr = (options && options.body) || '';
        var bodyObj = null;
        try {
            bodyObj = typeof bodyStr === 'string' ? JSON.parse(bodyStr) : null;
        } catch (e) { /* non-JSON body */ }

        var record = {
            endpoint:  url,
            method:    (options && options.method) || 'POST',
            body:      bodyObj,     // plain object — IDB-safe
            bodyRaw:   typeof bodyStr === 'string' ? bodyStr : null,
            headers:   (options && options.headers) || {},
            label:     guessLabel(url, bodyObj),
            timestamp: Date.now(),
            retries:   0
        };

        return addRecord(record).then(function () {
            registerSync();
            updateUI();
        });
    }

    /**
     * Replay a single queued action against the server.
     * Returns true on HTTP 2xx, false otherwise.
     * Deletes the record from IDB on success.
     */
    function replayOne(record) {
        var opts = {
            method:      record.method || 'POST',
            credentials: 'include',
            headers:     {}
        };

        // Reconstruct body — prefer the raw string to avoid double-parse
        if (record.bodyRaw) {
            opts.body = record.bodyRaw;
            opts.headers['Content-Type'] = 'application/json';
        } else if (record.body) {
            opts.body = JSON.stringify(record.body);
            opts.headers['Content-Type'] = 'application/json';
        }

        // Merge any original headers (e.g. X-Requested-With), don't override Content-Type
        var origHeaders = record.headers || {};
        Object.keys(origHeaders).forEach(function (k) {
            if (!opts.headers[k]) opts.headers[k] = origHeaders[k];
        });

        // Use the original fetch (not our wrapper) to avoid re-intercepting
        var realFetch = window._mwOrigFetch || window.fetch;

        return realFetch(record.endpoint, opts).then(function (res) {
            if (res.ok) {
                return deleteRecord(record.id).then(function () { return true; });
            }
            return false;
        }).catch(function () {
            return false;
        });
    }

    /**
     * Replay all pending actions sequentially (order matters —
     * e.g., start_visit must come before end_visit for the same job).
     * Returns { replayed, failed }.
     */
    function syncNow() {
        if (!isOnline()) return Promise.resolve({ replayed: 0, failed: 0 });

        return getAllRecords().then(function (records) {
            if (!records.length) return { replayed: 0, failed: 0 };

            var replayed = 0, failed = 0;

            var chain = records.reduce(function (p, record) {
                return p.then(function () {
                    return replayOne(record).then(function (ok) {
                        if (ok) replayed++;
                        else    failed++;
                    });
                });
            }, Promise.resolve());

            return chain.then(function () {
                updateUI();
                return { replayed: replayed, failed: failed };
            });
        });
    }

    // ── UI indicators ────────────────────────────────────────────────────────────

    /**
     * Update all offline UI elements:
     *   - Pong clock icon queue badge (schedule page)
     *   - Offline strip below the day strip (schedule page)
     *   - localStorage (read by offline.html)
     */
    function updateUI() {
        getPendingCount().then(function (count) {
            // Cross-page read by offline.html
            try { localStorage.setItem('mw-action-queue-count', String(count)); } catch (e) {}

            // ── Pong clock badge ─────────────────────────────────────────────────
            var badge = document.getElementById('mwClockQueueBadge');
            if (badge) {
                badge.textContent = count > 0 ? count : '';
                badge.classList.toggle('mw-queue-has-items', count > 0);
            }

            // ── Offline strip ────────────────────────────────────────────────────
            var strip = document.getElementById('mwOfflineStrip');
            var text  = document.getElementById('mwOfflineStripText');
            if (!strip) return;

            if (!isOnline()) {
                // Offline — show amber strip
                strip.className = 'mw-mc-offline-strip mw-offline-state-offline';
                strip.style.display = 'flex';
                if (text) {
                    text.textContent = count > 0
                        ? count + ' action' + (count !== 1 ? 's' : '') + ' queued — will sync when you reconnect'
                        : 'You\'re offline — actions will queue automatically';
                }
            } else if (count > 0) {
                // Online, pending actions — show syncing state
                strip.className = 'mw-mc-offline-strip mw-offline-state-syncing';
                strip.style.display = 'flex';
                if (text) text.textContent = 'Syncing ' + count + ' action' + (count !== 1 ? 's' : '') + '…';
            } else {
                // Online, nothing pending — show "synced" briefly then hide
                strip.className = 'mw-mc-offline-strip mw-offline-state-clear';
                strip.style.display = 'flex';
                if (text) text.textContent = '✓ All synced';
                setTimeout(function () {
                    if (strip.classList.contains('mw-offline-state-clear')) {
                        strip.style.display = 'none';
                        strip.className = 'mw-mc-offline-strip';
                    }
                }, 2500);
            }
        });
    }

    // ── Background Sync ──────────────────────────────────────────────────────────

    function registerSync() {
        if ('serviceWorker' in navigator && 'SyncManager' in window) {
            navigator.serviceWorker.ready.then(function (reg) {
                return reg.sync.register('action-queue-sync');
            }).catch(function () { /* silently fail — fallback events cover iOS */ });
        }
    }

    // ── Fetch interception ───────────────────────────────────────────────────────

    // Store a reference to the original fetch BEFORE we overwrite it.
    // replayOne() uses this to bypass our interception during replay.
    var _origFetch = window.fetch;
    window._mwOrigFetch = _origFetch;

    window.fetch = function (resource, options) {
        var url    = typeof resource === 'string' ? resource : (resource && resource.url) || '';
        var method = ((options && options.method) || 'GET').toUpperCase();

        // Only intercept POST requests to the three queued endpoints when offline
        var shouldQueue = method === 'POST' &&
            QUEUED_ENDPOINTS.some(function (ep) { return url.indexOf(ep) !== -1; }) &&
            !isOnline();

        if (!shouldQueue) {
            return _origFetch.apply(window, arguments);
        }

        // Offline path: queue the action and return an optimistic response
        var bodyStr = (options && options.body) || '';
        var bodyObj = null;
        try { bodyObj = typeof bodyStr === 'string' ? JSON.parse(bodyStr) : null; } catch (e) {}

        return queueAction(url, options).then(function () {
            var fakeData = buildOptimisticResponse(url, bodyObj);
            return new Response(
                JSON.stringify(fakeData),
                { status: 200, headers: { 'Content-Type': 'application/json' } }
            );
        }).catch(function () {
            // IDB write failed — fall back to real fetch (will fail naturally if offline)
            return _origFetch.apply(window, arguments);
        });
    };

    // ── Connectivity event listeners ─────────────────────────────────────────────

    window.addEventListener('online', function () {
        updateUI();
        syncNow().then(function (result) {
            if (result.replayed > 0) updateUI();
        });
    });

    window.addEventListener('offline', function () {
        updateUI();
    });

    // iOS PWA / all platforms: sync when the app is foregrounded
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && isOnline()) {
            getPendingCount().then(function (count) {
                if (count > 0) syncNow().then(updateUI);
            });
        }
    });

    // Desktop + some mobile: sync on window focus
    window.addEventListener('focus', function () {
        if (isOnline()) {
            getPendingCount().then(function (count) {
                if (count > 0) syncNow().then(updateUI);
            });
        }
    });

    // Android Capacitor: MwNative.network is more reliable than navigator.onLine
    if (window.MwNative && window.MwNative.network) {
        window.MwNative.network.onStatusChange(function (connected) {
            if (connected) {
                syncNow().then(updateUI);
            } else {
                updateUI();
            }
        });
    }

    // SW message: Background Sync fired — flush from page context
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', function (e) {
            if (e.data && e.data.type === 'action-queue-flush') {
                syncNow().then(updateUI);
            }
            // existing: receipt sync notification
            if (e.data && e.data.type === 'receipts-synced') {
                if (window.OfflineReceipts) window.OfflineReceipts.updatePendingBadge();
            }
        });
    }

    // ── Initialize ───────────────────────────────────────────────────────────────

    openDB().then(function () {
        // Refresh UI with current pending count on every page load
        updateUI();

        // If online on load, flush any actions queued from a previous offline session
        if (isOnline()) {
            getPendingCount().then(function (count) {
                if (count > 0) syncNow().then(updateUI);
            });
        }
    }).catch(function () {
        // IndexedDB unavailable (Private Browsing on some browsers) — offline queue disabled
    });

    // ── Public API ───────────────────────────────────────────────────────────────

    window.OfflineActions = {
        syncNow:         syncNow,
        getPendingCount: getPendingCount,
        updateUI:        updateUI
    };

})();
