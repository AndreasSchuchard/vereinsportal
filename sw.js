const CACHE = 'kgv461-v4';
const OFFLINE_URL = '/mitglieder.php';

const PRECACHE = [
    '/',
    '/mitglieder.php',
    '/vereinshaus.php',
    '/vereinshaus.css',
    '/vereinshaus.js',
    '/logo_kgv461.png',
    '/icon-192.png',
    '/icon-512.png',
    '/manifest.json',
];

// Install — pre-cache core assets
self.addEventListener('install', e => {
    e.waitUntil(
        caches.open(CACHE)
            .then(c => c.addAll(PRECACHE))
            .then(() => self.skipWaiting())
    );
});

// Activate — clean up old caches
self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

// Fetch — network first, cache fallback
self.addEventListener('fetch', e => {
    // Only handle GET requests
    if (e.request.method !== 'GET') return;

    // Skip cross-origin requests
    if (!e.request.url.startsWith(self.location.origin)) return;

    // Skip admin area — always fresh
    if (e.request.url.includes('/intern/')) return;

    // Skip API calls — always fresh
    if (e.request.url.includes('/member-api/') ||
        e.request.url.includes('/booking.php') ||
        e.request.url.includes('/calendar-data.php') ||
        e.request.url.includes('/contact.php')) return;

    e.respondWith(
        fetch(e.request)
            .then(res => {
                // Cache successful responses for static assets
                if (res.ok && (
                    e.request.url.match(/\.(css|js|png|jpg|webp|svg|woff2?)$/) ||
                    e.request.url.includes('/logo_kgv461.png')
                )) {
                    const clone = res.clone();
                    caches.open(CACHE).then(c => c.put(e.request, clone));
                }
                return res;
            })
            .catch(() => {
                // Offline fallback
                return caches.match(e.request)
                    || caches.match(OFFLINE_URL);
            })
    );
});

// Push notifications
self.addEventListener('push', e => {
    if (!e.data) return;
    let data = {};
    try { data = e.data.json(); } catch { data = { title: 'KGV Musterstadt', body: e.data.text() }; }

    e.waitUntil(
        self.registration.showNotification(data.title || 'KGV Musterstadt e.V.', {
            body:    data.body    || '',
            icon:    data.icon    || '/icon-192.png',
            badge:   '/icon-192.png',
            tag:     data.tag     || 'kgv461',
            data:    data.url     ? { url: data.url } : {},
            vibrate: [200, 100, 200],
        })
    );
});

// Notification click → open app
self.addEventListener('notificationclick', e => {
    e.notification.close();
    const url = (e.notification.data && e.notification.data.url) || '/mitglieder.php';
    e.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(all => {
            const match = all.find(c => c.url.includes('kgv461') && 'focus' in c);
            if (match) return match.focus();
            return clients.openWindow(url);
        })
    );
});
