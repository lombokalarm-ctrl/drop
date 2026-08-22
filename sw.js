const CACHE_NAME = 'nota-dropping-pwa-v3';
const APP_SHELL = [
    './',
    './index.php',
    './manifest.webmanifest',
    './assets/style.css',
    './assets/icon.svg',
    './assets/icon-192.png',
    './assets/icon-512.png',
    './assets/icon-maskable-512.png',
    './assets/icon-180.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys
                    .filter((key) => key !== CACHE_NAME)
                    .map((key) => caches.delete(key))
            )
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') {
        return;
    }

    const requestUrl = new URL(event.request.url);

    if (requestUrl.origin !== self.location.origin) {
        return;
    }

    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request)
                .then((response) => {
                    const clonedResponse = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put('./index.php', clonedResponse));
                    return response;
                })
                .catch(() => caches.match('./index.php'))
        );
        return;
    }

    event.respondWith(
        fetch(event.request)
            .then((response) => {
                const clonedResponse = response.clone();
                caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clonedResponse));
                return response;
            })
            .catch(() =>
                caches.match(event.request).then((cachedResponse) => cachedResponse || Response.error())
            )
    );
});
