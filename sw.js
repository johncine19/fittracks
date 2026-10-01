/**
 * FitTracks Service Worker - Offline Resilience & PWA Terminal
 * Enables scanner and attendance recording during network brownouts & outages.
 */

const CACHE_NAME = 'fittracks-terminal-v1';

const STATIC_ASSETS = [
    './manifest.json',
    './assets/app.css',
    './assets/dropdown.css',
    './assets/dropdown.js',
    './assets/audio.js',
    './assets/sweetalert2.all.min.js',
    './assets/html5-qrcode.min.js',
    './assets/images/fittracks-icon.svg'
];

// Install: Cache critical static assets
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(STATIC_ASSETS).catch((err) => {
                console.warn('[SW] Some static assets failed to cache:', err);
            });
        }).then(() => self.skipWaiting())
    );
});

// Activate: Clean up old caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k))
            );
        }).then(() => self.clients.claim())
    );
});

// Fetch: Strategy depending on request type
self.addEventListener('fetch', (event) => {
    const req = event.request;
    const url = new URL(req.url);

    // Only handle GET requests (POSTs are handled via client-side IndexedDB queue)
    if (req.method !== 'GET') {
        return;
    }

    // Skip cross-origin or chrome-extension requests
    if (url.origin !== self.location.origin) {
        return;
    }

    // Navigation / HTML requests (e.g. index.php?page=scanner)
    if (req.mode === 'navigate' || req.headers.get('accept')?.includes('text/html')) {
        event.respondWith(
            fetch(req)
                .then((networkRes) => {
                    if (networkRes && networkRes.status === 200) {
                        const copy = networkRes.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(req, copy));
                    }
                    return networkRes;
                })
                .catch(async () => {
                    // Offline fallback: try exact match, then try scanner cached page
                    const cached = await caches.match(req);
                    if (cached) return cached;
                    const fallbackScanner = await caches.match('index.php?page=scanner');
                    if (fallbackScanner) return fallbackScanner;
                    return new Response(
                        '<div style="font-family:sans-serif;text-align:center;padding:50px;background:#05080c;color:#fff;">' +
                        '<h2>⚡ FitTracks Terminal Offline</h2>' +
                        '<p>Please navigate to the Scanner page while online once to enable full offline support.</p>' +
                        '<button onclick="location.reload()" style="background:#22c55e;color:#000;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-weight:bold;">Retry</button>' +
                        '</div>',
                        { headers: { 'Content-Type': 'text/html' } }
                    );
                })
        );
        return;
    }

    // Static assets (CSS, JS, Fonts, Images): Cache First with Background Update (Stale-While-Revalidate)
    if (
        url.pathname.startsWith('/assets/') ||
        url.pathname.endsWith('.css') ||
        url.pathname.endsWith('.js') ||
        url.pathname.endsWith('.svg') ||
        url.pathname.endsWith('.png') ||
        url.pathname.endsWith('.jpg') ||
        url.pathname.endsWith('.woff2')
    ) {
        event.respondWith(
            caches.match(req).then((cached) => {
                const networkFetch = fetch(req).then((networkRes) => {
                    if (networkRes && networkRes.status === 200) {
                        const copy = networkRes.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(req, copy));
                    }
                    return networkRes;
                }).catch(() => null);

                return cached || networkFetch;
            })
        );
        return;
    }
});
