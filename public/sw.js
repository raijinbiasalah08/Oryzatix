// Refresh the app shell and precache the shared visual system.
const CACHE_NAME = 'oryzatix-pwa-v32';
const PRECACHE_ASSETS = [
    './manifest.json',
    './css/rice-detector.css',
    './css/ui-refresh.css',
    './images/field-contours.svg',
    './images/oryzatix-farm-assistant.png',
    './js/rice-detector.js',
    './images/logo.png',
    './images/icons/icon-192.png',
    './images/icons/icon-512.png'
];
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(async (cache) => {
            await Promise.allSettled(PRECACHE_ASSETS.map(asset => cache.add(asset)));
            await self.skipWaiting();
        })
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

    if (url.pathname.includes('/models/') || url.pathname.includes('/litert/')) return;

    // Bypass dynamic APIs, auth tokens, Google OAuth, and storage uploads
    if (
        url.pathname.includes('/api/') ||
        url.pathname.includes('/sanctum/') ||
        url.pathname.includes('/auth/google')
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

    // Static assets (CSS, JS, Fonts, Images): Cache-first with network fallback.
    // Prefer exact URLs so a cache-busting query cannot be served stale content.
    event.respondWith(
        caches.open(CACHE_NAME).then(async (cache) => {
            const exactCached = await cache.match(event.request);
            const unversionedFallback = exactCached
                ? null
                : await cache.match(event.request, { ignoreSearch: true });
            if (exactCached) {
                // Fetch fresh copy in background to update cache (stale-while-revalidate)
                fetch(event.request).then((res) => {
                    if (res && res.ok && event.request.url.startsWith(self.location.origin)) {
                        cache.put(event.request, res);
                    }
                }).catch(() => {});
                return exactCached;
            }

            // Hashed/versioned URLs must go to the network first. Use the
            // unversioned precache only when offline so releases take effect.
            if (url.search) {
                return fetch(event.request).then((response) => {
                    if (response && response.ok && event.request.url.startsWith(self.location.origin)) {
                        cache.put(event.request, response.clone());
                    }
                    return response;
                }).catch(() => unversionedFallback || Response.error());
            }

            if (unversionedFallback) {
                fetch(event.request).then((res) => {
                    if (res && res.ok && event.request.url.startsWith(self.location.origin)) {
                        cache.put(event.request, res);
                    }
                }).catch(() => {});
                return unversionedFallback;
            }

            return fetch(event.request).then((response) => {
                if (response && response.ok && event.request.url.startsWith(self.location.origin)) {
                    const copy = response.clone();
                    cache.put(event.request, copy);
                }
                return response;
            }).catch(async () =>
                (await cache.match(event.request))
                || (await cache.match(event.request, { ignoreSearch: true }))
                || Response.error()
            );
        })
    );
});
