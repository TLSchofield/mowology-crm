/**
 * Mowology CRM — Service Worker
 * ──────────────────────────────
 * Enables PWA install on iPhone / Android / tablet.
 *
 * Strategy:
 *   - App shell (CSS, JS, fonts, icons)  →  Cache-first, network fallback
 *   - PHP pages & API calls              →  Network-first, cache fallback
 *   - Images / uploads                   →  Cache-first, network fallback
 *
 * Cache versioning: bump CACHE_VERSION to bust all caches on next deploy.
 */

var CACHE_VERSION = 'mw-v2';
var SHELL_CACHE  = 'mw-shell-' + CACHE_VERSION;
var PAGE_CACHE   = 'mw-pages-' + CACHE_VERSION;
var IMG_CACHE    = 'mw-images-' + CACHE_VERSION;

/**
 * App shell — pre-cached on install for instant load.
 * These are the critical resources needed to render the CRM frame.
 */
var APP_SHELL = [
  '/crm/css/classic.css',
  '/crm/css/mowology-brand.css',
  '/crm/css/mobile-cards.css',
  '/crm/js/app.js',
  '/crm/js/feather-helper.js',
  '/crm/js/time-clock-widget.js',
  '/assets/favicon/apple-touch-icon.png',
  '/assets/favicon/android-chrome-192x192.png',
  '/assets/favicon/android-chrome-512x512.png',
  '/assets/favicon/favicon-32x32.png'
];

// ── Install: pre-cache the app shell ──
self.addEventListener('install', function(event) {
  event.waitUntil(
    caches.open(SHELL_CACHE).then(function(cache) {
      return cache.addAll(APP_SHELL);
    }).then(function() {
      return self.skipWaiting();
    })
  );
});

// ── Activate: clean up old version caches ──
self.addEventListener('activate', function(event) {
  event.waitUntil(
    caches.keys().then(function(cacheNames) {
      return Promise.all(
        cacheNames.filter(function(name) {
          // Delete any cache that doesn't match current version
          return name.startsWith('mw-') && name !== SHELL_CACHE && name !== PAGE_CACHE && name !== IMG_CACHE;
        }).map(function(name) {
          return caches.delete(name);
        })
      );
    }).then(function() {
      return self.clients.claim();
    })
  );
});

// ── Fetch: routing strategy ──
self.addEventListener('fetch', function(event) {
  var request = event.request;
  var url = new URL(request.url);

  // Only handle same-origin GET requests
  if (request.method !== 'GET') return;
  if (url.origin !== self.location.origin) return;

  // Skip chrome-extension, data URIs, etc.
  if (!url.protocol.startsWith('http')) return;

  var pathname = url.pathname;

  // ── Static assets (CSS, JS, fonts) → cache-first ──
  if (isStaticAsset(pathname)) {
    event.respondWith(cacheFirst(request, SHELL_CACHE));
    return;
  }

  // ── Images → cache-first ──
  if (isImage(pathname)) {
    event.respondWith(cacheFirst(request, IMG_CACHE));
    return;
  }

  // ── PHP pages → network-first (always get fresh data) ──
  if (pathname.endsWith('.php') || pathname.startsWith('/crm/')) {
    event.respondWith(networkFirst(request, PAGE_CACHE));
    return;
  }

  // ── Everything else → network-first ──
  event.respondWith(networkFirst(request, PAGE_CACHE));
});

/**
 * Cache-first: serve from cache, fall back to network (and update cache).
 */
function cacheFirst(request, cacheName) {
  return caches.match(request).then(function(cached) {
    if (cached) return cached;

    return fetch(request).then(function(response) {
      if (response.ok) {
        var clone = response.clone();
        caches.open(cacheName).then(function(cache) {
          cache.put(request, clone);
        });
      }
      return response;
    });
  }).catch(function() {
    // Offline and not cached — return nothing
    return new Response('', { status: 503, statusText: 'Offline' });
  });
}

/**
 * Network-first: try network, fall back to cache if offline.
 */
function networkFirst(request, cacheName) {
  return fetch(request).then(function(response) {
    if (response.ok) {
      var clone = response.clone();
      caches.open(cacheName).then(function(cache) {
        cache.put(request, clone);
      });
    }
    return response;
  }).catch(function() {
    return caches.match(request).then(function(cached) {
      if (cached) return cached;
      // Return a basic offline page if nothing cached
      return new Response(
        '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Offline</title>' +
        '<style>body{font-family:-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#F6F4EF;color:#333;}' +
        '.box{text-align:center;padding:40px;}.icon{font-size:3rem;margin-bottom:16px;}h1{font-size:1.2rem;margin:0 0 8px;}p{color:#666;font-size:.9rem;}</style></head>' +
        '<body><div class="box"><div class="icon">&#127793;</div><h1>You\'re offline</h1><p>Check your connection and try again.</p></div></body></html>',
        { status: 503, headers: { 'Content-Type': 'text/html' } }
      );
    });
  });
}

/**
 * Helpers: classify request types
 */
function isStaticAsset(path) {
  return /\.(css|js|woff|woff2|ttf|eot)(\?|$)/i.test(path);
}

function isImage(path) {
  return /\.(png|jpg|jpeg|gif|svg|ico|webp)(\?|$)/i.test(path);
}
