// BlueMoon Service Worker
// Only caches static assets (icons, manifest). JS and HTML always fetched live.
const CACHE = 'bluemoon-v4';
const STATIC = [
  '/bluemoon/icons/icon-192.png',
  '/bluemoon/icons/icon-512.png',
  '/bluemoon/manifest.webmanifest',
];

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE).then(c => c.addAll(STATIC)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  if (!url.pathname.startsWith('/bluemoon')) return;

  // JS and HTML: network-first, fall back to cache
  const isApp = url.pathname.endsWith('.html') || url.pathname.endsWith('.js')
    || url.pathname === '/bluemoon/' || url.pathname === '/bluemoon';

  if (isApp) {
    e.respondWith(
      fetch(e.request).catch(() => caches.match(e.request))
    );
    return;
  }

  // Static assets: cache-first
  e.respondWith(
    caches.match(e.request).then(cached => {
      if (cached) return cached;
      return fetch(e.request).then(res => {
        if (!res || res.status !== 200) return res;
        const clone = res.clone();
        caches.open(CACHE).then(c => c.put(e.request, clone));
        return res;
      });
    })
  );
});
