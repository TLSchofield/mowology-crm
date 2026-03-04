/**
 * Mowology CRM — Service Worker
 * ──────────────────────────────
 * Scope: /crm/ (covers all CRM pages)
 *
 * Strategy:
 *   Static assets (CSS, JS, fonts, images) → cache-first.
 *     First request goes to network and is cached; every subsequent
 *     request is served from local cache instantly (no network).
 *     Cache is busted automatically when the CACHE_NAME version changes.
 *
 *   PHP pages → pass-through (no caching).
 *     Live data must always be fresh; prefetch links handle
 *     pre-loading adjacent pages in the background.
 *
 * To bust the asset cache after a deploy: increment CACHE_NAME.
 */
'use strict';

var CACHE_NAME = 'mwcrm-assets-v2';

/* ── On install: skip waiting so the new SW activates immediately ── */
self.addEventListener('install', function (e) {
    self.skipWaiting();
});

/* ── On activate: delete any old caches, claim all open tabs ── */
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

/* ── On fetch: cache-first for static assets, pass-through for PHP ── */
self.addEventListener('fetch', function (e) {
    var req = e.request;

    // Only handle same-origin GET requests
    if (req.method !== 'GET') return;
    if (new URL(req.url).origin !== self.location.origin) return;

    var path = new URL(req.url).pathname;

    // Static assets: CSS, JS, fonts, images — serve from cache, populate on miss
    if (/\.(css|js|woff2?|ttf|eot|otf|png|jpg|jpeg|gif|svg|ico|webp)(\?.*)?$/.test(path)) {
        e.respondWith(
            caches.match(req).then(function (cached) {
                if (cached) return cached;

                return fetch(req).then(function (res) {
                    // Only cache successful responses
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

    // PHP pages — pass through; prefetch links handle pre-loading
    // (no caching: crew members must always see live job data)
});
