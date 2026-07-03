/*
 * BEC PMO — service worker (technician portal PWA).
 * Strategy:
 *   - Pages (navigations): network-first, falling back to the last cached copy
 *     so an installed app still opens when the connection drops.
 *   - Static assets (css/js/img/fonts): cache-first with background refill.
 *   - POSTs / API calls are never intercepted.
 */
const VERSION = 'bec-pmo-v2';
const STATIC_CACHE = VERSION + '-static';
const PAGE_CACHE = VERSION + '-pages';

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE).then((c) =>
      c.addAll([
        'assets/logs.png',
        'assets/pwa-icon-192.png',
        'assets/pwa-icon-512.png',
        'assets/page_loader.js'
      ]).catch(() => {})
    ).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;                      // never touch POSTs
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;            // same-origin only

  if (req.mode === 'navigate') {
    // network-first for pages
    event.respondWith(
      fetch(req)
        .then((res) => {
          const copy = res.clone();
          caches.open(PAGE_CACHE).then((c) => c.put(req, copy)).catch(() => {});
          return res;
        })
        .catch(() => caches.match(req, { ignoreSearch: false })
          .then((hit) => hit || caches.match('technician_dashboard.php?tab=my_tasks&source=pwa')))
    );
    return;
  }

  if (/\.(css|js|png|jpe?g|webp|svg|woff2?|ico)(\?.*)?$/i.test(url.pathname + url.search)) {
    // cache-first for static assets
    event.respondWith(
      caches.match(req).then((hit) => {
        const refill = fetch(req).then((res) => {
          const copy = res.clone();
          caches.open(STATIC_CACHE).then((c) => c.put(req, copy)).catch(() => {});
          return res;
        }).catch(() => hit);
        return hit || refill;
      })
    );
  }
});
