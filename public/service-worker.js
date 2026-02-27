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

var CACHE_VERSION = 'mw-v16';
var SHELL_CACHE  = 'mw-shell-' + CACHE_VERSION;
var PAGE_CACHE   = 'mw-pages-' + CACHE_VERSION;
var IMG_CACHE    = 'mw-images-' + CACHE_VERSION;

/**
 * App shell — pre-cached on SW install for instant load.
 * All static assets the schedule page needs to render, versioned so
 * a CACHE_VERSION bump fetches fresh copies automatically.
 */
var APP_SHELL = [
  /* ── Core AppStack frame ── */
  '/crm/css/classic.css',
  '/crm/css/mowology-brand.css?v=20260225b',
  '/crm/css/mobile-cards.css?v=20260227b',
  '/crm/css/mobile-nav.css?v=20260225a',
  '/crm/js/app.js',
  '/crm/js/feather-helper.js',

  /* ── Schedule page JS ── */
  '/crm/js/time-clock-widget.js?v=20260214h',
  '/crm/js/capacitor-bridge.js?v=20260214',
  '/crm/js/navigation-launcher.js?v=20260225c',
  '/crm/js/schedule-route-map.js?v=20260226b',
  '/crm/js/schedule-pill-workflow.js?v=20260226e',
  '/crm/js/schedule-drag-drop.js',
  '/crm/js/route-engine.js?v=20260219a',
  '/crm/js/offline-receipts.js',

  /* ── Geofence / location ── */
  '/crm/js/geofence/geofence-manager.js',
  '/crm/js/geofence/location-sampler.js',
  '/crm/js/geofence/sync-queue.js',

  /* ── App icons ── */
  '/assets/favicon/apple-touch-icon.png',
  '/assets/favicon/android-chrome-192x192.png',
  '/assets/favicon/android-chrome-512x512.png',
  '/assets/favicon/favicon-32x32.png'
];

/**
 * PHP pages to warm into the page cache on install.
 * These load fast from cache and refresh silently in the background.
 */
var WARM_PAGES = [
  '/crm/jobs/schedule.php',
  '/crm/timeclock/my-schedule.php'
];

// ── Install: pre-cache the app shell + warm key pages ──
self.addEventListener('install', function(event) {
  event.waitUntil(
    Promise.all([
      caches.open(SHELL_CACHE).then(function(cache) {
        return cache.addAll(APP_SHELL);
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
                 name !== IMG_CACHE;
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
  if (!url.protocol.startsWith('http')) return;

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

  // ── Schedule / timeclock pages → stale-while-revalidate ──
  // Serve instantly from cache, update silently in the background.
  // This is the key fix for perceived slowness — the page renders
  // immediately from cache while fresh HTML is fetched and stored
  // for the NEXT load.
  if (isSchedulePage(pathname)) {
    event.respondWith(staleWhileRevalidate(request, PAGE_CACHE));
    return;
  }

  // ── Other PHP/CRM pages → network-first with cache fallback ──
  event.respondWith(networkFirst(request, PAGE_CACHE));
});

// ── Background Sync: retry failed receipt uploads ──
self.addEventListener('sync', function(event) {
  if (event.tag === 'receipt-upload') {
    event.waitUntil(syncPendingReceipts());
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

    return fetch(request).then(function(response) {
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
  var networkFetch = fetch(request).then(function(response) {
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
 */
function networkFirst(request, cacheName) {
  return fetch(request).then(function(response) {
    if (response.ok) {
      var clone = response.clone();
      caches.open(cacheName).then(function(cache) { cache.put(request, clone); });
    }
    return response;
  }).catch(function() {
    return caches.match(request).then(function(cached) {
      return cached || offlinePage();
    });
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
