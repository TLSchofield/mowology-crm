/**
 * Mowology CRM — Service Worker v3
 * ──────────────────────────────────
 * Scope: /crm/ (covers all CRM pages)
 *
 * Strategies:
 *
 *   Static assets (CSS, JS, fonts, images) → cache-first.
 *     First request fetches from network and caches; every subsequent
 *     request is served from cache instantly.
 *     Cache is busted when CACHE_NAME version changes.
 *
 *   Navigation requests (HTML pages) → network-first with offline fallback.
 *     Tries the network; if offline or unreachable, serves /crm/offline.html
 *     from cache so the user gets a branded message instead of a blank screen.
 *
 *   API endpoints → pass-through (no caching).
 *     Live data must stay fresh. offline-queue.js intercepts critical mutation
 *     endpoints (time-clock, pow-actions, job-timer) at the JS level and
 *     queues them in IndexedDB when offline, replaying on reconnect.
 *
 *   Background Sync ("action-queue-sync") → relay to open page(s).
 *     When Android Chrome fires the sync event, the SW first tries to
 *     postMessage any open CRM pages to flush their queue (page JS has
 *     full session + CSRF context). If no pages are open, the SW replays
 *     the queue directly using session cookies.
 *
 * To bust the asset cache after a deploy: increment CACHE_NAME.
 */
'use strict';

var CACHE_NAME  = 'mwcrm-assets-v5';
var OFFLINE_URL = '/crm/offline.html';

// Must match constants in offline-queue.js
var IDB_NAME  = 'mowology-actions';
var IDB_STORE = 'pending-actions';

/* ── Install: pre-cache offline page, skip waiting ─────────────────────── */

self.addEventListener('install', function (e) {
    e.waitUntil(
        caches.open(CACHE_NAME).then(function (cache) {
            return cache.add(OFFLINE_URL);
        }).then(function () {
            return self.skipWaiting();
        })
    );
});

/* ── Activate: delete old caches, claim all open tabs ──────────────────── */

self.addEventListener('activate', function (e) {
    e.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(
                keys
                    .filter(function (k) { return k !== CACHE_NAME; })
                    .map(function (k) { return caches.delete(k); })
            );
        }).then(function () {
            return self.clients.claim();
        })
    );
});

/* ── Fetch: navigation fallback + cache-first for static assets ─────────── */

self.addEventListener('fetch', function (e) {
    var req = e.request;

    // Only handle same-origin GET requests
    if (req.method !== 'GET') return;
    if (new URL(req.url).origin !== self.location.origin) return;

    // Navigation requests: network-first, serve offline page on failure
    if (req.mode === 'navigate') {
        e.respondWith(
            fetch(req).catch(function () {
                return caches.match(OFFLINE_URL);
            })
        );
        return;
    }

    var path = new URL(req.url).pathname;

    // Static assets: cache-first, populate on miss
    if (/\.(css|js|woff2?|ttf|eot|otf|png|jpg|jpeg|gif|svg|ico|webp)(\?.*)?$/.test(path)) {
        e.respondWith(
            caches.match(req).then(function (cached) {
                if (cached) return cached;

                return fetch(req).then(function (res) {
                    if (res && res.ok) {
                        var clone = res.clone();
                        caches.open(CACHE_NAME).then(function (cache) {
                            cache.put(req, clone);
                        });
                    }
                    return res;
                });
            })
        );
        return;
    }

    // PHP pages / API endpoints — pass through; offline-queue.js handles mutations
});

/* ── Background Sync: replay queued actions ─────────────────────────────── */

self.addEventListener('sync', function (e) {
    if (e.tag === 'action-queue-sync') {
        e.waitUntil(handleActionSync());
    }
    if (e.tag === 'receipt-upload') {
        // existing offline-receipts.js handles this; notify open pages
        e.waitUntil(notifyClients('receipts-synced'));
    }
});

/**
 * Flush strategy:
 * 1. Open CRM page exists → postMessage to flush (has fresh CSRF + session).
 * 2. No pages open → SW replays directly via session cookies.
 */
function handleActionSync() {
    return self.clients.matchAll({ includeUncontrolled: true, type: 'window' }).then(function (clients) {
        var crmClients = clients.filter(function (c) {
            return c.url.indexOf('/crm/') !== -1;
        });

        if (crmClients.length > 0) {
            crmClients.forEach(function (client) {
                client.postMessage({ type: 'action-queue-flush' });
            });
            return;
        }

        // No page open — replay directly from SW
        return swReplayQueue();
    });
}

function notifyClients(type) {
    return self.clients.matchAll({ includeUncontrolled: true, type: 'window' }).then(function (clients) {
        clients.forEach(function (c) {
            if (c.url.indexOf('/crm/') !== -1) {
                c.postMessage({ type: type });
            }
        });
    });
}

/* ── SW direct queue replay (no open page) ──────────────────────────────── */

function swReplayQueue() {
    return swOpenIDB().then(function (db) {
        return swGetAll(db).then(function (records) {
            if (!records.length) return;
            // Sequential — order matters (start before stop, clock_in before clock_out)
            return records.reduce(function (chain, record) {
                return chain.then(function () {
                    return swReplayOne(db, record);
                });
            }, Promise.resolve());
        });
    }).catch(function () {
        // IDB not available in SW context — page will retry on next open
    });
}

function swReplayOne(db, record) {
    var opts = {
        method:      record.method || 'POST',
        credentials: 'include',     // PHP session cookies forwarded
        headers:     { 'Content-Type': 'application/json' }
    };

    opts.body = record.bodyRaw || (record.body ? JSON.stringify(record.body) : null);

    return fetch(record.endpoint, opts).then(function (res) {
        if (res.ok) return swDelete(db, record.id);
        // 403 (CSRF) / 401 (session): leave in queue; page shows pending indicator
    }).catch(function () {
        // Network error: leave in queue for retry
    });
}

/* ── Minimal IDB helpers for SW context ─────────────────────────────────── */

function swOpenIDB() {
    return new Promise(function (resolve, reject) {
        var req = indexedDB.open(IDB_NAME, 1);
        req.onsuccess       = function (e) { resolve(e.target.result); };
        req.onerror         = function ()  { reject(new Error('SW IDB failed')); };
        req.onupgradeneeded = function (e) {
            var d = e.target.result;
            if (!d.objectStoreNames.contains(IDB_STORE)) {
                d.createObjectStore(IDB_STORE, { keyPath: 'id', autoIncrement: true });
            }
        };
    });
}

function swGetAll(db) {
    return new Promise(function (resolve) {
        var tx  = db.transaction(IDB_STORE, 'readonly');
        var req = tx.objectStore(IDB_STORE).getAll();
        req.onsuccess = function () { resolve(req.result || []); };
        req.onerror   = function () { resolve([]); };
    });
}

function swDelete(db, id) {
    return new Promise(function (resolve) {
        var tx = db.transaction(IDB_STORE, 'readwrite');
        tx.objectStore(IDB_STORE).delete(id);
        tx.oncomplete = function () { resolve(); };
        tx.onerror    = function () { resolve(); };
    });
}
