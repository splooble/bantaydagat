














importScripts('offline-db.js');

const SHELL_CACHE = 'bd-scan-shell-v4';
const PHOTO_CACHE = 'bd-scan-photos-v1';
const SHELL_FILES = [
    'scan.php',
    'offline-db.js',
    'assets/zxing-library.min.js',
    'manifest-scan.json',
    'icons/icon-192.png',
    'icons/icon-512.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL_CACHE)
            .then((cache) => cache.addAll(SHELL_FILES))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== SHELL_CACHE && k !== PHOTO_CACHE).map((k) => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);




    if (event.request.method !== 'GET') return;

    if (url.pathname.indexOf('/uploads/photos/') !== -1) {
        event.respondWith(
            caches.open(PHOTO_CACHE).then((cache) =>
                cache.match(event.request).then((cached) => {
                    const net = fetch(event.request).then((res) => {
                        if (res && res.ok) cache.put(event.request, res.clone());
                        return res;
                    }).catch(() => cached);
                    return cached || net;
                })
            )
        );
        return;
    }
    if (url.pathname.endsWith('scan.php') && !url.search) {
        event.respondWith(
            fetch(event.request)
                .then((res) => {
                    const copy = res.clone();
                    caches.open(SHELL_CACHE).then((c) => c.put(event.request, copy));
                    return res;
                })
                .catch(() => caches.match(event.request))
        );
        return;
    }
    if (SHELL_FILES.some((f) => url.pathname.endsWith(f))) {
        event.respondWith(
            caches.match(event.request).then((cached) => cached || fetch(event.request))
        );
    }
});


self.addEventListener('sync', (event) => {
    if (event.tag === 'bd-sync-violations') {
        event.waitUntil(replayViolationQueue());
    }
});




async function replayViolationQueue() {
    const queued = await bdGetAll('violation_queue');
    for (const item of queued) {
        try {
            const fd = new FormData();
            Object.entries(item.payload).forEach(([k, v]) => fd.append(k, v));
            const res = await fetch('scan.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                await bdDelete('violation_queue', item.local_id);
            }
        } catch (e) {


            break;
        }
    }
    const clientsList = await self.clients.matchAll();
    clientsList.forEach((c) => c.postMessage({ type: 'bd-queue-synced', queue: 'violations' }));
}