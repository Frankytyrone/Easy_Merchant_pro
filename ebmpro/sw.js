const CACHE_NAME = 'ebmpro-v1';

const ASSETS = [
    '/ebmpro/',
    '/ebmpro/index.html',
    '/ebmpro/manifest.json',
    '/ebmpro/css/styles.css',
    '/ebmpro/js/db.js',
    '/ebmpro/js/api.js',
    '/ebmpro/js/invoice.js',
    '/ebmpro/js/app.js',
    '/ebmpro/js/pdf.js',
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => cache.addAll(ASSETS))
    );
    self.skipWaiting();
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // Network-first for API calls
    if (url.pathname.startsWith('/ebmpro_api/') || url.pathname.startsWith('/track/')) {
        event.respondWith(fetch(event.request));
        return;
    }

    // Cache-first for app assets
    event.respondWith(
        caches.match(event.request).then(cached => cached || fetch(event.request))
    );
});
