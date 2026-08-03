/*
 * BEC PMO — service worker (technician portal PWA).
 * Strategy:
 *   - Pages (navigations): network-first, falling back to the last cached copy
 *     so an installed app still opens when the connection drops.
 *   - Static assets (css/js/img/fonts): cache-first with background refill.
 *   - POSTs / API calls are never intercepted.
 */
// Bump this whenever a cached asset's BYTES change under the same filename.
// Static assets are served cache-first, so a phone that installed the app
// before the August image/font re-encode would keep serving the old 777 KB
// seal and the pre-subset icon font from its cache indefinitely — the
// Cache-Control headers in .htaccess never get consulted for a cache hit.
// Changing VERSION makes `activate` delete every cache that isn't this one.
const VERSION = 'bec-pmo-v6';
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

/* ── Web Push: show a notification when the PMO assigns a task ──
   Payload-less "tickle": we display a fixed message and open the workspace on click. */
self.addEventListener('push', (event) => {
  let title = 'BEC Technician';
  let body  = 'You have a new task update. Tap to open your workspace.';
  try {
    if (event.data) { const d = event.data.json(); if (d.title) title = d.title; if (d.body) body = d.body; }
  } catch (e) { /* payload-less push — use defaults */ }
  event.waitUntil(
    self.registration.showNotification(title, {
      body: body,
      icon: 'assets/pwa-icon-192.png',
      badge: 'assets/pwa-icon-192.png',
      tag: 'bec-task',
      renotify: true,
      data: { url: 'technician_dashboard.php?tab=my_tasks&source=push' }
    })
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = (event.notification.data && event.notification.data.url) || 'technician_dashboard.php?tab=my_tasks';
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      for (const c of list) { if (c.url.includes('technician_dashboard.php') && 'focus' in c) return c.focus(); }
      if (self.clients.openWindow) return self.clients.openWindow(target);
    })
  );
});
