const CACHE_NAME = 'oryzatix-pwa-v6';
const PRECACHE_ASSETS = [
    './manifest.json',
    './css/rice-detector.css',
    './js/rice-detector.js',
    './images/logo.png',
    './images/icons/icon-192.png',
    './images/icons/icon-512.png'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(async (cache) => {
            // Precache with error resilience so a single 404 does not fail installation
            await Promise.allSettled(
                PRECACHE_ASSETS.map((asset) => cache.add(asset).catch(() => {}))
            );
        }).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') return;

    const url = new URL(event.request.url);

    // Bypass dynamic APIs, auth tokens, Google OAuth, and storage uploads
    if (
        url.pathname.includes('/api/') ||
        url.pathname.includes('/sanctum/') ||
        url.pathname.includes('/auth/google') ||
        url.pathname.includes('/build/')
    ) {
        return;
    }

    // Navigation requests (HTML pages): Network-first with offline cache fallback
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request)
                .then((networkResponse) => {
                    if (networkResponse && networkResponse.ok) {
                        const copy = networkResponse.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
                    }
                    return networkResponse;
                })
                .catch(async () => {
                    const cachedResponse = await caches.match(event.request);
                    if (cachedResponse) return cachedResponse;
                    // Try root page match
                    return (await caches.match('./')) || (await caches.match('/'));
                })
        );
        return;
    }

    // Static assets (CSS, JS, Fonts, Images): Cache-first with network fallback
    event.respondWith(
        caches.match(event.request).then((cached) => {
            if (cached) {
                // Fetch fresh copy in background to update cache (stale-while-revalidate)
                fetch(event.request).then((res) => {
                    if (res && res.ok && event.request.url.startsWith(self.location.origin)) {
                        caches.open(CACHE_NAME).then((cache) => cache.put(event.request, res));
                    }
                }).catch(() => {});
                return cached;
            }

            return fetch(event.request).then((response) => {
                if (response && response.ok && event.request.url.startsWith(self.location.origin)) {
                    const copy = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
                }
                return response;
            }).catch(() => cached);
        })
    );
});
