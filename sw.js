/*
 * BEC PMO — service worker for both installable apps: the technician portal
 * (manifest.webmanifest) and the reporter app (manifest-reporter.webmanifest).
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

function offlinePage() {
  const html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
    + '<meta name="viewport" content="width=device-width,initial-scale=1">'
    + '<title>Offline — BEC PMO</title></head>'
    + '<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
    + 'background:#F8F3EA;font-family:system-ui,sans-serif;color:#1C1008;text-align:center;padding:24px">'
    + '<div><img src="assets/pwa-icon-192.png" width="72" height="72" alt="" style="border-radius:18px">'
    + '<h1 style="font-size:20px;margin:16px 0 8px;color:#4A0E0E">You are offline</h1>'
    + '<p style="margin:0 0 20px;font-size:15px;line-height:1.5">Reporting needs an internet connection.<br>'
    + 'Connect to Wi-Fi or mobile data, then try again.</p>'
    + '<button onclick="location.reload()" style="font-size:16px;padding:12px 22px;border:0;'
    + 'border-radius:12px;background:#4A0E0E;color:#fff">Try again</button></div></body></html>';
  return new Response(html, { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
}

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
        // Offline: the page itself if it was cached, else that app's home page,
        // else a plain notice. The fallback used to be the technician dashboard
        // for everyone, which is the wrong app for a reporter.
        .catch(() => caches.match(req, { ignoreSearch: false })
          .then((hit) => hit || caches.match(/technician/.test(url.pathname)
            ? 'technician_dashboard.php?tab=my_tasks&source=pwa'
            : 'student_index.php?source=pwa'))
          .then((hit) => hit || offlinePage()))
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
