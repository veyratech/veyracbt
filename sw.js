/**
 * Service Worker — CBT Online Student Portal PWA
 * Cache shell app (HTML/CSS/JS) untuk offline access
 */
const CACHE_NAME = 'cbt-student-v3';
const STATIC_ASSETS = [
    '/cbtonline/assets/css/style.css',
    '/cbtonline/assets/css/student.css',
    '/cbtonline/assets/js/dashboard.js',
    '/cbtonline/assets/js/student-portal.js',
    '/cbtonline/assets/js/exam-engine.js',
    '/cbtonline/pwa/icon-192.png',
    '/cbtonline/pwa/icon-512.png',
];

// ── INSTALL: cache shell assets ───────────────────────────────
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(STATIC_ASSETS).catch(() => { /* ignore missing */ });
        }).then(() => self.skipWaiting())
    );
});

// ── ACTIVATE: clear old caches ────────────────────────────────
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

// ── FETCH: network-first, fall back to cache ─────────────────
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // Skip non-http(s) and non-student paths
    if (!url.protocol.startsWith('http')) return;
    if (!url.pathname.includes('/cbtonline/')) return;

    // API calls: network only
    if (url.pathname.includes('/api/') || url.search.includes('ajax') || url.search.includes('export')) {
        event.respondWith(fetch(event.request));
        return;
    }

    // Page requests: network-only (no cache for dynamic PHP pages)
    if (event.request.destination === 'document' || event.request.url.endsWith('.php')) {
        event.respondWith(fetch(event.request));
        return;
    }

    // Static assets: cache-first
    event.respondWith(
        caches.match(event.request).then((cached) => {
            if (cached) return cached;
            return fetch(event.request).then((response) => {
                if (response && response.status === 200) {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
                }
                return response;
            }).catch(() => {
                // Offline fallback for page loads
                if (event.request.destination === 'document') {
                    return caches.match('/cbtonline/assets/css/style.css');
                }
            });
        })
    );
});

// ── PUSH notifications (future: pengumuman guru) ─────────────
self.addEventListener('push', (event) => {
    const data = event.data ? event.data.json() : {};
    const title = data.title || 'Pengumuman Ujian';
    const options = {
        body: data.body || 'Ada jadwal ujian baru!',
        icon: '/cbtonline/pwa/icon-192.png',
        badge: '/cbtonline/pwa/icon-192.png',
        vibrate: [200, 100, 200],
        tag: 'cbt-notification',
        renotify: true,
    };
    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(
        clients.openWindow('/cbtonline/student/dashboard.php')
    );
});
