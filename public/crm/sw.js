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

var CACHE_NAME  = 'mwcrm-assets-v6';
var OFFLINE_URL = '/crm/offline.html';
var TILE_CACHE  = 'mw-tiles-v1'; // satellite tiles — long-lived, separate from asset cache
var MAX_TILES   = 2000;          // evict oldest when exceeded

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
                    .filter(function (k) { return k !== CACHE_NAME && k !== TILE_CACHE; })
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

    if (req.method !== 'GET') return;

    var url = new URL(req.url);

    // ── Satellite map tiles (cross-origin) → cache-first for offline use ──
    if (isMapTile(url.hostname)) {
        e.respondWith(tileCacheFirst(req));
        return;
    }

    // Only handle same-origin requests from here on
    if (url.origin !== self.location.origin) return;

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

/* ── Tile Pre-warming & Cache Management (postMessage API) ─────────────── */

self.addEventListener('message', function (event) {
    if (!event.data) return;

    if (event.data.type === 'prewarm-tiles') {
        event.waitUntil(prewarmTiles(event.data, event.source));
        return;
    }

    if (event.data.type === 'tile-cache-stats') {
        caches.open(TILE_CACHE).then(function (cache) {
            return cache.keys();
        }).then(function (keys) {
            if (event.source) {
                event.source.postMessage({ type: 'tile-cache-stats', count: keys.length });
            }
        });
        return;
    }

    if (event.data.type === 'clear-tile-cache') {
        caches.delete(TILE_CACHE).then(function () {
            if (event.source) {
                event.source.postMessage({ type: 'tile-cache-cleared' });
            }
        });
        return;
    }
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

/* ── Satellite Tile Caching Helpers ────────────────────────────────────── */

/**
 * Detect Esri / OSM tile hostnames for cache-first interception.
 */
function isMapTile(hostname) {
    return hostname.indexOf('arcgisonline.com') !== -1 ||
           hostname.indexOf('tile.openstreetmap.org') !== -1 ||
           hostname.indexOf('services.arcgisonline.com') !== -1;
}

/**
 * Cache-first strategy for satellite tiles.
 * Tiles are cross-origin so responses are opaque (status 0).
 * We cache them anyway — a tile URL is immutable (same z/x/y = same image).
 */
function tileCacheFirst(request) {
    return caches.open(TILE_CACHE).then(function (cache) {
        return cache.match(request).then(function (cached) {
            if (cached) return cached;

            // Cross-origin tiles need no-cors; opaque responses are cacheable
            var fetchReq = new Request(request.url, { mode: 'no-cors' });
            return fetch(fetchReq).then(function (response) {
                if (response.ok || response.type === 'opaque') {
                    cache.put(request, response.clone());
                    trimTileCache(cache);
                }
                return response;
            });
        });
    }).catch(function () {
        // Fully offline and not in cache — return transparent 1x1 PNG
        return new Response(
            Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVQI12NgAAIABQABNjN9GQAAAAlwSFlzAAAWJQAAFiUBSVIk8AAAAA0lEQVQI12P4z8BQDwAEgAF/QualzQAAAABJRU5ErkJggg=='), function (c) { return c.charCodeAt(0); }),
            { status: 200, headers: { 'Content-Type': 'image/png' } }
        );
    });
}

/**
 * Keep tile cache under MAX_TILES entries.
 * Evicts oldest 10% when the limit is exceeded.
 */
function trimTileCache(cache) {
    cache.keys().then(function (keys) {
        if (keys.length <= MAX_TILES) return;
        var evictCount = Math.ceil(keys.length * 0.1);
        for (var i = 0; i < evictCount; i++) {
            cache.delete(keys[i]);
        }
    });
}

/**
 * Pre-warm satellite tiles around a lat/lng for offline use.
 * Called via postMessage from ZoneEditorManager.
 */
function prewarmTiles(data, client) {
    var lat    = data.lat;
    var lng    = data.lng;
    var zooms  = data.zooms || [17, 18, 19, 20];
    var radius = data.radius || 2;
    var tileUrl   = data.tileUrl || 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';
    var labelsUrl = data.labelsUrl || null;

    // Build list of tile URLs to pre-cache
    var urls = [];
    zooms.forEach(function (z) {
        var center = latLngToTile(lat, lng, z);
        for (var dx = -radius; dx <= radius; dx++) {
            for (var dy = -radius; dy <= radius; dy++) {
                var x = center.x + dx;
                var y = center.y + dy;
                if (x < 0 || y < 0) continue;
                urls.push(tileUrl.replace('{z}', z).replace('{x}', x).replace('{y}', y));
                if (labelsUrl) {
                    urls.push(labelsUrl.replace('{z}', z).replace('{x}', x).replace('{y}', y));
                }
            }
        }
    });

    return caches.open(TILE_CACHE).then(function (cache) {
        var fetched = 0;
        var skipped = 0;
        var failed  = 0;

        function fetchBatch(startIdx) {
            var batch = urls.slice(startIdx, startIdx + 6);
            if (batch.length === 0) {
                if (client) {
                    client.postMessage({
                        type: 'prewarm-complete',
                        total: urls.length,
                        fetched: fetched,
                        skipped: skipped,
                        failed: failed,
                    });
                }
                return Promise.resolve();
            }

            return Promise.all(batch.map(function (url) {
                var req = new Request(url, { mode: 'no-cors' });
                return cache.match(req).then(function (existing) {
                    if (existing) { skipped++; return; }

                    return fetch(req).then(function (response) {
                        if (response.ok || response.type === 'opaque') {
                            fetched++;
                            return cache.put(req, response);
                        }
                        failed++;
                    }).catch(function () { failed++; });
                });
            })).then(function () {
                if (client && (startIdx % 30 === 0 || startIdx + 6 >= urls.length)) {
                    client.postMessage({
                        type: 'prewarm-progress',
                        total: urls.length,
                        done: startIdx + batch.length,
                    });
                }
                return fetchBatch(startIdx + 6);
            });
        }

        return fetchBatch(0).then(function () {
            trimTileCache(cache);
        });
    });
}

/**
 * Convert lat/lng to slippy-map tile coordinates at a given zoom level.
 */
function latLngToTile(lat, lng, zoom) {
    var n = Math.pow(2, zoom);
    var x = Math.floor((lng + 180) / 360 * n);
    var latRad = lat * Math.PI / 180;
    var y = Math.floor((1 - Math.log(Math.tan(latRad) + 1 / Math.cos(latRad)) / Math.PI) / 2 * n);
    return { x: x, y: y };
}
