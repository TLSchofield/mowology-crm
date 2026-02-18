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
 * Auto cache bust: on activation, fetches /crm/api/cache-version.php to compare
 * deploy SHA — if different, purges old caches.
 *
 * Background Sync: queued receipt uploads retry when connectivity restores.
 * Push: handles approval notification push events.
 */

var CACHE_VERSION = 'mw-v4';
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
  '/crm/js/offline-receipts.js',
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

// ── Activate: clean up old version caches + auto cache bust ──
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

// ── Background Sync: retry failed receipt uploads ──
self.addEventListener('sync', function(event) {
  if (event.tag === 'receipt-upload') {
    event.waitUntil(syncPendingReceipts());
  }
});

/**
 * Process pending receipts from IndexedDB when back online.
 * Reads from the mowology-receipts / pending-receipts store.
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
              // Delete from pending store on success
              var delTx = db.transaction('pending-receipts', 'readwrite');
              delTx.objectStore('pending-receipts').delete(receipt.id);
            }
          }).catch(function() {
            // Will retry on next sync event
          });
        });

        Promise.all(uploads).then(function() {
          // Notify any open clients
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

// ── Push Notifications: approval notifications ──
self.addEventListener('push', function(event) {
  if (!event.data) return;

  var data;
  try {
    data = event.data.json();
  } catch (e) {
    data = { title: 'Mowology', body: event.data.text() };
  }

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

// ── Notification Click: open the relevant page ──
self.addEventListener('notificationclick', function(event) {
  event.notification.close();
  var url = event.notification.data?.url || '/crm/expenses_appstack.php';

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(clients) {
      // Focus existing tab if open
      for (var i = 0; i < clients.length; i++) {
        if (clients[i].url.includes('/crm/') && 'focus' in clients[i]) {
          return clients[i].focus();
        }
      }
      // Open new tab
      return self.clients.openWindow(url);
    })
  );
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
        '<body><div class="box"><div class="icon">&#127793;</div><h1>You\'re offline</h1><p>Check your connection and try again.</p>' +
        '<p id="pending" style="display:none;color:#e85d04;font-weight:600;"></p></div>' +
        '<script>if(indexedDB){var r=indexedDB.open("mowology-receipts",1);r.onsuccess=function(e){var d=e.target.result;if(d.objectStoreNames.contains("pending-receipts")){' +
        'var t=d.transaction("pending-receipts","readonly").objectStore("pending-receipts").count();' +
        't.onsuccess=function(){if(t.result>0){var p=document.getElementById("pending");p.textContent=t.result+" receipt(s) pending upload";p.style.display="block";}};}}}</script>' +
        '</body></html>',
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
