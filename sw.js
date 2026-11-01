/**
 * =============================================================================
 * ILMUNA LEARN - Service Worker (PWA)
 * =============================================================================
 * Strategi:
 *  - Halaman (navigasi)  : SELALU dari jaringan (data login/nilai tidak di-cache).
 *                          Jika offline -> tampilkan offline.html.
 *  - Aset statis /assets : stale-while-revalidate (cepat + tetap ter-update).
 *  - API, upload, POST   : tidak disentuh sama sekali.
 *
 * Naikkan CACHE_VERSION setiap kali ingin memaksa semua cache lama dibuang.
 * =============================================================================
 */
var CACHE_VERSION = 'v1';
var STATIC_CACHE  = 'ilmuna-static-' + CACHE_VERSION;
var SCOPE         = self.registration.scope; // mis. https://learn.ilmuna.my.id/
var OFFLINE_URL   = new URL('offline.html', SCOPE).href;

var PRECACHE_URLS = [
    'offline.html',
    'manifest.json',
    'assets/pwa/icon-192.png',
    'assets/pwa/icon-512.png',
    'assets/img/gunungan.png'
].map(function (p) { return new URL(p, SCOPE).href; });

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then(function (cache) { return cache.addAll(PRECACHE_URLS); })
            .then(function () { return self.skipWaiting(); })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil((async function () {
        var keys = await caches.keys();
        await Promise.all(keys.map(function (key) {
            if (key.indexOf('ilmuna-') === 0 && key !== STATIC_CACHE) return caches.delete(key);
        }));
        if (self.registration.navigationPreload) {
            try { await self.registration.navigationPreload.enable(); } catch (e) { /* abaikan */ }
        }
        await self.clients.claim();
    })());
});

function isStaticAsset(url, request) {
    if (url.origin !== self.location.origin) return false;
    if (!url.href.startsWith(SCOPE)) return false;
    var path = url.href.slice(SCOPE.length);
    if (path.indexOf('assets/') !== 0) return false;
    return ['style', 'script', 'image', 'font'].indexOf(request.destination) !== -1 ||
        /\.(css|js|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|otf)$/i.test(url.pathname);
}

self.addEventListener('fetch', function (event) {
    var request = event.request;
    if (request.method !== 'GET') return;

    var url = new URL(request.url);

    // 1) Navigasi halaman -> network-first, fallback offline.html
    if (request.mode === 'navigate') {
        event.respondWith((async function () {
            try {
                var preload = await event.preloadResponse;
                if (preload) return preload;
                return await fetch(request);
            } catch (err) {
                var cached = await caches.match(OFFLINE_URL);
                return cached || new Response('Anda sedang offline.', {
                    status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' }
                });
            }
        })());
        return;
    }

    // 2) Aset statis -> stale-while-revalidate
    if (isStaticAsset(url, request)) {
        event.respondWith((async function () {
            var cache = await caches.open(STATIC_CACHE);
            var cached = await cache.match(request);
            var network = fetch(request).then(function (response) {
                if (response && response.ok && response.type === 'basic') {
                    cache.put(request, response.clone());
                }
                return response;
            }).catch(function () { return cached; });
            return cached || network;
        })());
    }
    // 3) Lainnya (api/, uploads/, *.php, CDN) -> biarkan browser menangani
});

// Memungkinkan halaman meminta SW baru langsung aktif
self.addEventListener('message', function (event) {
    if (event.data === 'SKIP_WAITING') self.skipWaiting();
});

