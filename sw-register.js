







importScripts('offline-db.js');

const SHELL_CACHE = 'bd-register-shell-v4';
const SHELL_FILES = [
    'register.php',
    'offline-db.js',
    'manifest-register.json',
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
            Promise.all(keys.filter((k) => k !== SHELL_CACHE).map((k) => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') return;
    const url = new URL(event.request.url);
    if (url.pathname.endsWith('register.php') && !url.search) {
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
    if (event.tag === 'bd-sync-registrations') {
        event.waitUntil(replayRegistrationQueue());
    }
});

async function replayRegistrationQueue() {
    const queued = await bdGetAll('registration_queue');
    for (const item of queued) {
        try {
            const res = await fetch('api_register.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(item.payload),
            });
            const data = await res.json();
            if (data.success) {
                await bdDelete('registration_queue', item.local_id);
            }
        } catch (e) {
            break;
        }
    }
    const clientsList = await self.clients.matchAll();
    clientsList.forEach((c) => c.postMessage({ type: 'bd-queue-synced', queue: 'registrations' }));
}