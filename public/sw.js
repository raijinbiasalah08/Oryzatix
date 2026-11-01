// Refresh the app shell and precache the shared visual system.
const CACHE_NAME = 'oryzatix-pwa-v14';
const PRECACHE_ASSETS = [
    './manifest.json',
    './css/rice-detector.css',
    './css/ui-refresh.css',
    './images/field-contours.svg',
    './js/rice-detector.js',
    './images/logo.png',
    './images/icons/icon-192.png',
    './images/icons/icon-512.png'
];
const OFFLINE_SCANNER_ASSETS = [
    './models/class_labels.json',
    './models/mobilenetv2_rice_leaf.tflite',
    './models/rice_leaf_validator.tflite',
    './models/scan_validation_thresholds.json',
    './models/severity_experimental_v1.tflite',
    './models/severity_experimental_v1.json',
    './litert/litert_wasm_internal.js',
    './litert/litert_wasm_internal.wasm'
];

async function precacheOfflineScannerEntry(cache) {
    const manifestUrl = new URL('./build/manifest.json', self.location.href);
    const response = await fetch(manifestUrl);
    if (!response.ok) throw new Error('The Vite manifest could not be cached.');
    await cache.put(manifestUrl, response.clone());

    const manifest = await response.json();
    const entry = manifest['resources/js/offline-scanner.js'];
    if (!entry?.file) throw new Error('The offline scanner entry is missing from the Vite manifest.');

    const entryFiles = [entry.file, ...(entry.css || [])];
    await Promise.all(entryFiles.map(file => cache.add(new URL(`./build/${file}`, self.location.href))));
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(async (cache) => {
            await Promise.allSettled(PRECACHE_ASSETS.map(asset => cache.add(asset)));
            await Promise.all(OFFLINE_SCANNER_ASSETS.map(asset => cache.add(asset)));
            await precacheOfflineScannerEntry(cache);
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

    // Static assets (CSS, JS, Fonts, Images): Cache-first with network fallback
    event.respondWith(
        caches.match(event.request, { ignoreSearch: true }).then((cached) => {
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
