/**
 * Mowology CRM — Service Worker
 * ──────────────────────────────
 * Enables fast loads for both the PWA and the Capacitor Android app.
 *
 * Strategy:
 *   - App shell (CSS, JS, fonts, icons)  →  Cache-first, network fallback
 *   - PHP pages (schedule, timeclock)    →  Stale-while-revalidate (instant
 *                                           load from cache, fresh in background)
 *   - API calls (/crm/api/*)             →  Network-first (always fresh data)
 *   - Images / uploads                   →  Cache-first, network fallback
 *
 * Cache versioning: bump CACHE_VERSION to bust all caches on next deploy.
 *
 * Background Sync: queued receipt uploads retry when connectivity restores.
 * Push: handles approval notification push events.
 *
 * NOTE: Registered for both PWA and Capacitor (Capacitor uses a live server
 * URL so the WebView can use a service worker just like a browser can).
 */

var CACHE_VERSION = 'mw-v38';
var SHELL_CACHE  = 'mw-shell-' + CACHE_VERSION;
var PAGE_CACHE   = 'mw-pages-' + CACHE_VERSION;
var IMG_CACHE    = 'mw-images-' + CACHE_VERSION;
var TILE_CACHE   = 'mw-tiles-v2'; // satellite tiles — long-lived, NOT versioned with CACHE_VERSION

/**
 * App shell — pre-cached on SW install for instant load.
 * All static assets the schedule page needs to render, versioned so
 * a CACHE_VERSION bump fetches fresh copies automatically.
 */
// Prefer the .min.js / .min.css siblings produced by
// scripts/minify-assets.php — they ship ~30-60% smaller over the wire.
// Full files remain available at their original URLs for debugging.
var APP_SHELL = [
  /* ── Core AppStack frame ── */
  '/crm/css/classic.css',
  '/crm/css/tokens.min.css',
  '/crm/css/mowology-brand.min.css?v=20260419a',
  '/crm/css/mowology-a11y.min.css',
  '/crm/css/mobile-cards.min.css?v=20260419a',
  '/crm/css/mobile-nav.min.css?v=20260318a',
  '/crm/css/mw-sync-status.min.css',
  '/crm/css/mw-skeleton.min.css',
  '/crm/js/app.min.js',
  '/crm/js/feather-helper.min.js',

  /* ── Shared UI primitives ── */
  '/crm/js/mw-toast.min.js',
  '/crm/js/mw-sync-status.min.js',
  '/crm/js/mw-haptics.min.js',
  '/crm/js/mw-api-toast.min.js',
  '/crm/js/sw-register.min.js',

  /* ── Schedule page JS ── */
  '/crm/js/time-clock-widget.min.js?v=20260419a',
  '/crm/js/capacitor-bridge.min.js?v=20260304',
  '/crm/js/navigation-launcher.min.js?v=20260225c',
  '/crm/js/schedule-route-map.min.js?v=20260226b',
  '/crm/js/schedule-pill-workflow.min.js?v=20260310a',
  '/crm/js/schedule-drag-drop.min.js',
  '/crm/js/route-engine.min.js?v=20260219a',
  '/crm/js/offline-receipts.min.js',
  '/crm/js/photo-queue.min.js?v=20260401a',
  '/crm/js/mw-schedule-search.min.js',
  '/crm/js/mw-pull-refresh.min.js',

  /* ── Geofence / location ── */
  '/crm/js/geofence/geofence-manager.min.js',
  '/crm/js/geofence/location-sampler.min.js',
  '/crm/js/geofence/sync-queue.min.js',

  /* ── App icons ── */
  '/assets/favicon/apple-touch-icon.png',
  '/assets/favicon/android-chrome-192x192.png',
  '/assets/favicon/android-chrome-512x512.png',
  '/assets/favicon/favicon-32x32.png',

  /* ── App launch ── */
  '/assets/img/logo/mowology-logo.jpg',

  /* ── Branded offline fallback (served on nav failures) ── */
  '/crm/offline.html'
];

/**
 * PHP pages to warm into the page cache on install.
 * NOTE: Authenticated pages are intentionally NOT pre-cached here.
 * Pre-caching schedule.php before login stores the login-page HTML under
 * the schedule URL key, causing stale-while-revalidate to serve wrong content.
 * Pages are cached on-demand after first successful (logged-in) visit instead.
 */
var WARM_PAGES = [];

// ── Install: pre-cache the app shell + warm key pages ──
self.addEventListener('install', function(event) {
  event.waitUntil(
    Promise.all([
      caches.open(SHELL_CACHE).then(function(cache) {
        // Cache each asset individually — one 404 won't abort the whole install
        return Promise.all(APP_SHELL.map(function(url) {
          return cache.add(url).catch(function() { /* skip, will fetch on demand */ });
        }));
      }),
      caches.open(PAGE_CACHE).then(function(cache) {
        // Warm the schedule pages — best-effort, don't fail install if offline
        return Promise.all(WARM_PAGES.map(function(url) {
          return cache.add(url).catch(function() { /* ignore if server unreachable */ });
        }));
      })
    ]).then(function() {
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
          return name.startsWith('mw-') &&
                 name !== SHELL_CACHE &&
                 name !== PAGE_CACHE &&
                 name !== IMG_CACHE &&
                 name !== TILE_CACHE;
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

  // Only handle GET requests
  if (request.method !== 'GET') return;
  if (!url.protocol.startsWith('http')) return;

  // ── Satellite map tiles (cross-origin) → cache-first for offline use ──
  if (isMapTile(url.hostname)) {
    event.respondWith(tileCacheFirst(request));
    return;
  }

  // Only handle same-origin requests from here on
  if (url.origin !== self.location.origin) return;

  // ── Navigation requests ──
  // Android Chrome/WebView enforces a hard ~5s timeout on SW navigation responses,
  // so when online we bypass the SW entirely — the browser handles the request
  // and shows its normal loading spinner on slow PHP pages. However, when we
  // know we're offline (navigator.onLine === false), we serve the branded
  // /crm/offline.html from the shell cache instead of the browser's default
  // "no internet" error page. This is the common field scenario for crews.
  if (request.mode === 'navigate') {
    if (self.navigator && self.navigator.onLine === false) {
      event.respondWith(
        caches.match('/crm/offline.html').then(function (cached) {
          return cached || fetch('/crm/offline.html').catch(function () {
            return new Response('Offline', { status: 503, statusText: 'Offline' });
          });
        })
      );
      return;
    }
    return;
  }

  var pathname = url.pathname;

  // ── API calls → network-first (always fresh data, cache as fallback) ──
  if (pathname.startsWith('/crm/api/') || pathname.startsWith('/app/Modules/')) {
    event.respondWith(networkFirst(request, PAGE_CACHE));
    return;
  }

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

  // ── Schedule / timeclock pages → network-first with cache fallback ──
  // Must use network-first (not stale-while-revalidate) for authenticated pages.
  // staleWhileRevalidate would serve stale login-page HTML after session expiry,
  // trapping users in a redirect loop. Network-first ensures the user always
  // sees the right page; the cache provides offline fallback only.
  if (isSchedulePage(pathname)) {
    event.respondWith(networkFirst(request, PAGE_CACHE));
    return;
  }

  // ── Other PHP/CRM pages → network-first with cache fallback ──
  event.respondWith(networkFirst(request, PAGE_CACHE));
});

// ── Message: tile pre-warming + cache stats ──
self.addEventListener('message', function(event) {
  if (!event.data) return;

  if (event.data.type === 'prewarm-tiles') {
    event.waitUntil(prewarmTiles(event.data, event.source));
  }

  if (event.data.type === 'tile-cache-stats') {
    caches.open(TILE_CACHE).then(function(cache) {
      return cache.keys();
    }).then(function(keys) {
      if (event.source) {
        event.source.postMessage({ type: 'tile-cache-stats', count: keys.length });
      }
    });
  }

  if (event.data.type === 'clear-tile-cache') {
    caches.delete(TILE_CACHE).then(function() {
      if (event.source) {
        event.source.postMessage({ type: 'tile-cache-cleared' });
      }
    });
  }
});

/**
 * Pre-warm satellite tiles around a coordinate for offline use.
 * Fetches tiles at specified zoom levels in a grid around the center point.
 *
 * Message shape:
 *   { type: 'prewarm-tiles', lat, lng, zooms: [17,18,19,20], tileUrl, radius }
 *
 * radius: how many tiles around center to fetch per zoom level (default 2).
 * At z20 with radius=2, that's a (2*2+1)^2 = 25 tile grid ≈ 250m square.
 */
function prewarmTiles(data, client) {
  var lat = data.lat;
  var lng = data.lng;
  var zooms = data.zooms || [17, 18, 19, 20];
  var tileUrl = data.tileUrl || 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';
  var labelsUrl = data.labelsUrl || 'https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}';
  var radius = data.radius || 2;

  var urls = [];
  zooms.forEach(function(z) {
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

  return caches.open(TILE_CACHE).then(function(cache) {
    var fetched = 0;
    var skipped = 0;
    var failed = 0;

    // Fetch tiles sequentially in small batches to avoid hammering the server
    function fetchBatch(startIdx) {
      var batch = urls.slice(startIdx, startIdx + 6);
      if (batch.length === 0) {
        // Done — notify the client
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

      return Promise.all(batch.map(function(url) {
        var req = new Request(url, { mode: 'no-cors' });
        return cache.match(req).then(function(existing) {
          if (existing) { skipped++; return; }

          return fetch(req).then(function(response) {
            if (response.ok || response.type === 'opaque') {
              fetched++;
              return cache.put(req, response);
            }
            failed++;
          }).catch(function() { failed++; });
        });
      })).then(function() {
        // Send progress updates
        if (client && (startIdx % 30 === 0 || startIdx + 6 >= urls.length)) {
          client.postMessage({
            type: 'prewarm-progress',
            total: urls.length,
            done: fetched + skipped + failed,
            fetched: fetched,
            skipped: skipped,
          });
        }
        return fetchBatch(startIdx + 6);
      });
    }

    return fetchBatch(0);
  });
}

/**
 * Convert lat/lng to tile coordinates at a given zoom level.
 * Standard slippy map tile calculation.
 */
function latLngToTile(lat, lng, zoom) {
  var n = Math.pow(2, zoom);
  var x = Math.floor((lng + 180) / 360 * n);
  var latRad = lat * Math.PI / 180;
  var y = Math.floor((1 - Math.log(Math.tan(latRad) + 1 / Math.cos(latRad)) / Math.PI) / 2 * n);
  return { x: x, y: y };
}

// ── Background Sync: retry failed uploads ──
self.addEventListener('sync', function(event) {
  if (event.tag === 'receipt-upload') {
    event.waitUntil(syncPendingReceipts());
  }
  if (event.tag === 'photo-queue-sync') {
    event.waitUntil(syncPendingPhotos());
  }
});

/**
 * Process pending receipts from IndexedDB when back online.
 */
function syncPendingReceipts() {
  return new Promise(function(resolve) {
    var dbReq = indexedDB.open('mowology-receipts', 1);
    dbReq.onerror = function() { resolve(); };
    dbReq.onsuccess = function(e) {
      var db = e.target.result;
      if (!db.objectStoreNames.contains('pending-receipts')) {
        resolve();
        return;
      }
      var tx = db.transaction('pending-receipts', 'readwrite');
      var store = tx.objectStore('pending-receipts');
      var getAll = store.getAll();
      getAll.onsuccess = function() {
        var receipts = getAll.result || [];
        if (!receipts.length) { resolve(); return; }

        var uploads = receipts.map(function(receipt) {
          var formData = new FormData();
          formData.append('receipt_photo', receipt.blob, 'receipt.jpg');
          formData.append('csrf_token', receipt.csrf || '');
          if (receipt.lat) formData.append('lat', receipt.lat);
          if (receipt.lng) formData.append('lng', receipt.lng);

          return fetch('/crm/api/receipt-intake.php', {
            method: 'POST',
            body: formData,
          }).then(function(r) {
            if (r.ok) {
              var delTx = db.transaction('pending-receipts', 'readwrite');
              delTx.objectStore('pending-receipts').delete(receipt.id);
            }
          }).catch(function() { /* retry on next sync */ });
        });

        Promise.all(uploads).then(function() {
          self.clients.matchAll().then(function(clients) {
            clients.forEach(function(client) {
              client.postMessage({ type: 'receipts-synced', count: receipts.length });
            });
          });
          resolve();
        });
      };
    };
  });
}

/**
 * Process the photo upload queue when the Background Sync tag fires.
 *
 * Strategy (two-tier):
 *   1. If open CRM pages exist, post them a 'process-photo-queue' message.
 *      Pages have full access to the Capacitor Filesystem + MwPhotoQueue engine.
 *   2. If no pages are open (true background), process IDB-stored blobs directly
 *      from the SW context (Capacitor Filesystem is not accessible here).
 *
 * The SW path only handles records with storageType === 'idb'. Filesystem-stored
 * records are left as 'pending' for the app to pick up on next launch.
 */
function syncPendingPhotos() {
  return self.clients.matchAll({ includeUncontrolled: true }).then(function(clients) {
    var crmClients = clients.filter(function(c) { return c.url.indexOf('/crm/') !== -1; });

    if (crmClients.length > 0) {
      // Pages are open — hand off to the full MwPhotoQueue engine
      crmClients.forEach(function(c) {
        c.postMessage({ type: 'process-photo-queue' });
      });
      return;
    }

    // No open pages — process IDB blobs directly from the SW
    return syncPhotosFromSW();
  });
}

/**
 * Direct SW photo upload: reads photo-queue IDB → loads blobs from photo-store
 * IDB → uploads to server. Only processes storageType === 'idb' records.
 */
function syncPhotosFromSW() {
  return new Promise(function(resolve) {
    var qReq = indexedDB.open('mowology-photo-queue', 1);
    qReq.onerror = function() { resolve(); };
    qReq.onsuccess = function(e) {
      var qDb = e.target.result;
      if (!qDb.objectStoreNames.contains('uploads')) { resolve(); return; }

      var tx     = qDb.transaction('uploads', 'readonly');
      var getAll = tx.objectStore('uploads').getAll();
      getAll.onsuccess = function() {
        // Only handle IDB-stored blobs that haven't exhausted retries
        var records = (getAll.result || []).filter(function(r) {
          return (r.status === 'pending' || r.status === 'failed') &&
                 r.storageType === 'idb' &&
                 (r.retries || 0) < 5;
        });

        if (!records.length) { resolve(); return; }

        var bReq = indexedDB.open('mowology-photo-store', 1);
        bReq.onerror = function() { resolve(); };
        bReq.onsuccess = function(be) {
          var bDb   = be.target.result;
          var chain = Promise.resolve();
          records.forEach(function(record) {
            chain = chain.then(function() {
              return uploadPhotoFromSW(record, qDb, bDb);
            });
          });
          chain.then(resolve).catch(resolve);
        };
      };
      getAll.onerror = function() { resolve(); };
    };
  });
}

function uploadPhotoFromSW(record, qDb, bDb) {
  return new Promise(function(resolve) {
    if (!bDb.objectStoreNames.contains('blobs')) { resolve(); return; }

    var blobTx  = bDb.transaction('blobs', 'readonly');
    var blobReq = blobTx.objectStore('blobs').get(record.storageRef);

    blobReq.onsuccess = function() {
      var blobRecord = blobReq.result;

      if (!blobRecord || !blobRecord.blob) {
        // Storage entry gone (OS cleared cache) — remove stale queue record
        var delTx = qDb.transaction('uploads', 'readwrite');
        delTx.objectStore('uploads').delete(record.id);
        resolve(); return;
      }

      var formData = new FormData();
      formData.append('files[]',        blobRecord.blob, record.filename || 'photo.jpg');
      formData.append('csrf_token',     record.csrf        || '');
      formData.append('context_type',   record.contextType || '');
      formData.append('context_id',     String(record.contextId || ''));
      formData.append('category',       record.category    || '');
      formData.append('visibility',     record.visibility  || 'internal');
      if (record.powStamp)     formData.append('pow_stamp',    record.powStamp);
      if (record.gpsLat)      formData.append('gps_lat',      String(record.gpsLat));
      if (record.gpsLng)      formData.append('gps_lng',      String(record.gpsLng));
      if (record.gpsAccuracy) formData.append('gps_accuracy', String(record.gpsAccuracy));

      fetch(record.uploadUrl || '/crm/api/media-upload.php', { method: 'POST', body: formData })
        .then(function(res) { return res.json(); })
        .then(function(resp) {
          var fr = (resp.results && resp.results[0]) ? resp.results[0] : null;
          if (resp.success && fr && fr.success) {
            // Remove from queue and blob store
            var delQ = qDb.transaction('uploads', 'readwrite');
            delQ.objectStore('uploads').delete(record.id);
            var delB = bDb.transaction('blobs', 'readwrite');
            delB.objectStore('blobs').delete(record.storageRef);
            // Notify any pages that open later
            self.clients.matchAll().then(function(clients) {
              clients.forEach(function(c) {
                c.postMessage({ type: 'photo-queue-synced' });
              });
            });
          } else {
            // Server rejection — mark failed, don't retry from SW
            var pTx  = qDb.transaction('uploads', 'readwrite');
            var store = pTx.objectStore('uploads');
            var pGet  = store.get(record.id);
            pGet.onsuccess = function() {
              var rec = pGet.result;
              if (rec) { rec.status = 'failed'; rec.retries = (rec.retries || 0) + 1; store.put(rec); }
            };
          }
          resolve();
        })
        .catch(function() {
          // Network error — leave as pending; Background Sync will retry
          resolve();
        });
    };

    blobReq.onerror = function() { resolve(); };
  });
}

// ── Push Notifications ──
self.addEventListener('push', function(event) {
  if (!event.data) return;

  var data;
  try { data = event.data.json(); }
  catch (e) { data = { title: 'Mowology', body: event.data.text() }; }

  var options = {
    body: data.body || '',
    icon: '/assets/favicon/android-chrome-192x192.png',
    badge: '/assets/favicon/favicon-32x32.png',
    tag: data.tag || 'mowology-notification',
    data: { url: data.url || '/crm/expenses_appstack.php' },
  };

  event.waitUntil(
    self.registration.showNotification(data.title || 'Mowology CRM', options)
  );
});

// ── Notification Click ──
self.addEventListener('notificationclick', function(event) {
  event.notification.close();
  var url = event.notification.data?.url || '/crm/expenses_appstack.php';

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(clients) {
      for (var i = 0; i < clients.length; i++) {
        if (clients[i].url.includes('/crm/') && 'focus' in clients[i]) {
          return clients[i].focus();
        }
      }
      return self.clients.openWindow(url);
    })
  );
});

// ══════════════════════════════════════════════════════
//  CACHING STRATEGIES
// ══════════════════════════════════════════════════════

/**
 * Cache-first: serve from cache immediately, fall back to network.
 * Best for static assets that rarely change (versioned with ?v=).
 */
function cacheFirst(request, cacheName) {
  return caches.match(request).then(function(cached) {
    if (cached) return cached;

    // Use redirect:'follow' — navigation requests have redirect:'manual' by spec,
    // which would return an opaque redirect response and cause ERR_FAILED in Chrome.
    return fetch(request, { redirect: 'follow' }).then(function(response) {
      if (response.ok) {
        var clone = response.clone();
        caches.open(cacheName).then(function(cache) { cache.put(request, clone); });
      }
      return response;
    });
  }).catch(function() {
    return new Response('', { status: 503, statusText: 'Offline' });
  });
}

/**
 * Stale-while-revalidate: serve from cache instantly, then fetch fresh
 * copy in the background and store it for next time.
 * Best for PHP pages — user sees content immediately, next load is fresh.
 */
function staleWhileRevalidate(request, cacheName) {
  // Use redirect:'follow' — navigation requests have redirect:'manual' by spec,
  // which would return an opaque redirect response and cause ERR_FAILED in Chrome.
  var networkFetch = fetch(request, { redirect: 'follow' }).then(function(response) {
    if (response.ok) {
      var clone = response.clone();
      caches.open(cacheName).then(function(cache) { cache.put(request, clone); });
    }
    return response;
  }).catch(function() { return null; });

  return caches.match(request).then(function(cached) {
    // Return cache immediately if available, otherwise wait for network
    return cached || networkFetch.then(function(response) {
      return response || offlinePage();
    });
  });
}

/**
 * Network-first: try network, fall back to cache if offline.
 * Best for API calls where stale data could be misleading.
 * PAGE_CACHE writes also trigger the LRU trimmer below.
 */
function networkFirst(request, cacheName) {
  // Use redirect:'follow' — navigation requests have redirect:'manual' by spec,
  // which would return an opaque redirect response and cause ERR_FAILED in Chrome.
  return fetch(request, { redirect: 'follow' }).then(function(response) {
    if (response.ok) {
      var clone = response.clone();
      caches.open(cacheName).then(function(cache) {
        cache.put(request, clone).then(function () {
          if (cacheName === PAGE_CACHE) trimPageCache(cache);
        });
      });
    }
    return response;
  }).catch(function() {
    return caches.match(request).then(function(cached) {
      return cached || offlinePage();
    });
  });
}

/**
 * Keep PAGE_CACHE bounded to MAX_PAGES entries so a long shift of
 * schedule / homebase / clients navigations doesn't grow the cache
 * past the QuotaExceededError ceiling on older Android devices.
 *
 * Strategy: cache.keys() returns entries in insertion order (per the
 * CacheStorage spec). When over the cap we delete the oldest 20% of
 * entries in a single pass. Runs asynchronously so it never blocks
 * the fetch response.
 */
var MAX_PAGES = 30;
var _pageTrimRunning = false;

function trimPageCache(cache) {
  if (_pageTrimRunning) return;
  _pageTrimRunning = true;

  cache.keys().then(function (keys) {
    if (keys.length <= MAX_PAGES) { _pageTrimRunning = false; return; }
    var deleteCount = Math.ceil(keys.length * 0.2);
    var deletes = keys.slice(0, deleteCount).map(function (key) {
      return cache.delete(key);
    });
    return Promise.all(deletes);
  }).then(function () {
    _pageTrimRunning = false;
  }).catch(function () {
    _pageTrimRunning = false;
  });
}

// ══════════════════════════════════════════════════════
//  HELPERS
// ══════════════════════════════════════════════════════

function isStaticAsset(path) {
  return /\.(css|js|woff|woff2|ttf|eot)(\?|$)/i.test(path);
}

function isImage(path) {
  return /\.(png|jpg|jpeg|gif|svg|ico|webp)(\?|$)/i.test(path);
}

function isSchedulePage(path) {
  return path === '/crm/jobs/schedule.php' ||
         path === '/crm/timeclock/my-schedule.php';
}

/**
 * Detect Esri / OpenStreetMap map tile hosts for offline caching.
 * These are the tile sources used by the Zone Editor (Leaflet).
 */
function isMapTile(hostname) {
  return hostname === 'server.arcgisonline.com' ||
         hostname.endsWith('.arcgisonline.com') ||
         hostname.endsWith('.tile.openstreetmap.org');
}

/**
 * Cache-first for map tiles with opaque response support.
 * Tiles are cross-origin so responses are opaque (status 0).
 * We cache them anyway — a tile URL is immutable (same z/x/y = same image).
 */
function tileCacheFirst(request) {
  return caches.open(TILE_CACHE).then(function(cache) {
    return cache.match(request).then(function(cached) {
      if (cached) return cached;

      // Cross-origin tiles need no-cors; opaque responses are cacheable
      var fetchReq = new Request(request.url, { mode: 'no-cors' });
      return fetch(fetchReq).then(function(response) {
        if (response.ok || response.type === 'opaque') {
          cache.put(request, response.clone());
          trimTileCache(cache);
        }
        return response;
      });
    });
  }).catch(function() {
    // Fully offline and not in cache — return transparent 1x1 PNG
    return new Response(
      Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVQI12NgAAIABQABNjN9GQAAAAlwSFlzAAAWJQAAFiUBSVIk8AAAAA0lEQVQI12P4z8BQDwAEgAF/QualzQAAAABJRU5ErkJggg=='), function(c) { return c.charCodeAt(0); }),
      { status: 200, headers: { 'Content-Type': 'image/png' } }
    );
  });
}

/**
 * Keep tile cache under MAX_TILES entries.
 * Evicts oldest tiles when the limit is exceeded.
 * Runs asynchronously — doesn't block the response.
 */
var MAX_TILES = 2000; // ~2000 tiles ≈ 40-60 MB (enough for ~10 properties at z17-21)
var _trimRunning = false;

function trimTileCache(cache) {
  if (_trimRunning) return;
  _trimRunning = true;

  cache.keys().then(function(keys) {
    if (keys.length <= MAX_TILES) { _trimRunning = false; return; }
    // Delete oldest 10% of tiles
    var deleteCount = Math.ceil(keys.length * 0.1);
    var deletes = keys.slice(0, deleteCount).map(function(key) {
      return cache.delete(key);
    });
    return Promise.all(deletes);
  }).then(function() {
    _trimRunning = false;
  }).catch(function() {
    _trimRunning = false;
  });
}

function offlinePage() {
  return new Response(
    '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Offline</title>' +
    '<style>body{font-family:-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#F6F4EF;color:#333;}' +
    '.box{text-align:center;padding:40px;}.icon{font-size:3rem;margin-bottom:16px;}h1{font-size:1.2rem;margin:0 0 8px;}p{color:#666;font-size:.9rem;}</style></head>' +
    '<body><div class="box"><div class="icon">&#127793;</div><h1>You\'re offline</h1><p>Check your connection and try again.</p>' +
    '<p id="pending" style="display:none;color:#e85d04;font-weight:600;"></p></div>' +
    '<script>if(indexedDB){var r=indexedDB.open("mowology-receipts",1);r.onsuccess=function(e){var d=e.target.result;if(d.objectStoreNames.contains("pending-receipts")){' +
    'var t=d.transaction("pending-receipts","readonly").objectStore("pending-receipts").count();' +
    't.onsuccess=function(){if(t.result>0){var p=document.getElementById("pending");p.textContent=t.result+" receipt(s) pending upload";p.style.display="block";}};}}}</script>' +
    '</body></html>',
    { status: 503, headers: { 'Content-Type': 'text/html' } }
  );
}
