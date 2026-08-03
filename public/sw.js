/**
 * Service Worker — Push Notifications + offline /messages shell.
 * Cache version is stamped by `npm run build` so every deploy busts stale assets.
 */

const DEPLOY_VERSION = 'msd9n7y1';
const PAGE_CACHE  = 'hive-pages-' + DEPLOY_VERSION;
const ASSET_CACHE = 'hive-assets-' + DEPLOY_VERSION;

/* Offline SMS caches. NOT deploy-versioned: their content is user data, not
   assets, and must survive deploys. Fragment fetching/eviction is owned by
   resources/js/sms-offline.js (page context) — the SW only stores the
   /messages page shell, recently viewed MMS media, and wipes it all on logout. */
const SMS_DATA_CACHE  = 'hive-sms-data';
const SMS_MEDIA_CACHE = 'hive-sms-media';
const SMS_MEDIA_MAX_ENTRIES = 50;
const MESSAGES_SHELL_KEY = '/messages'; // normalized cache key (query stripped)
const SHELL_NETWORK_TIMEOUT_MS = 4000;

self.addEventListener('install', (event) => {
    console.log('[SW] Installing', DEPLOY_VERSION);
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    console.log('[SW] Activating', DEPLOY_VERSION);
    event.waitUntil(
        Promise.all([
            clients.claim(),
            caches.keys().then(keys =>
                Promise.all(
                    // Keeplist: deploy-versioned caches for THIS deploy, plus
                    // caches that must survive deploys — offline SMS data/media
                    // and 'hive-pending-nav' (notification-click handoff; it was
                    // previously wiped on every deploy, dropping pending clicks).
                    keys.filter(k => ![PAGE_CACHE, ASSET_CACHE, SMS_DATA_CACHE, SMS_MEDIA_CACHE, 'hive-pending-nav'].includes(k))
                        .map(k => caches.delete(k))
                )
            ),
        ])
    );
});

/* ─── Fetch: caching strategies ──────────────────────────────────── */

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (url.origin !== self.location.origin) return;

    // Logout → let the request through untouched, but wipe every cache that
    // holds private message data. This runs even if page JS never did (closed
    // tab, crashed script) — the primary privacy wipe.
    if (event.request.method === 'POST' && url.pathname === '/logout') {
        event.respondWith(fetch(event.request));
        event.waitUntil(Promise.all([
            caches.delete(SMS_DATA_CACHE),
            caches.delete(SMS_MEDIA_CACHE),
            caches.open(PAGE_CACHE).then(cache => cache.delete(MESSAGES_SHELL_KEY)),
        ]));
        return;
    }

    if (event.request.method !== 'GET') return;

    // Build / static assets → cache-first
    if (/^\/(build|js|css|fonts|favicons)\//.test(url.pathname)) {
        event.respondWith(cacheFirst(event.request, ASSET_CACHE));
        return;
    }

    // Livewire's runtime script (/livewire/livewire.min.js?id=hash) — without
    // it the offline /messages shell boots dead (Alpine ships inside it). The
    // ?id= hash busts the entry on deploys; the versioned cache purges old ones.
    if (url.pathname.startsWith('/livewire/livewire')) {
        event.respondWith(cacheFirst(event.request, ASSET_CACHE));
        return;
    }

    // /messages page shell → network-first with timeout; the cached copy IS
    // the offline thread list (the list is server-rendered into the page).
    // Accept: text/html covers hard navigations AND wire:navigate fetches.
    if (url.pathname === '/messages' && (event.request.headers.get('accept') || '').includes('text/html')) {
        event.respondWith(messagesShell(event.request));
        return;
    }

    // MMS media → cache-first with a small FIFO cap, so recently viewed
    // attachments render offline. Only images are kept (videos are too big).
    if (url.pathname.startsWith('/files/sms_media/')) {
        event.respondWith(smsMedia(event.request));
        return;
    }
});

async function cacheFirst(request, cacheName) {
    const hit = await caches.match(request);
    if (hit) return hit;
    try {
        const res = await fetch(request);
        if (res.ok) {
            const cache = await caches.open(cacheName);
            cache.put(request, res.clone());
        }
        return res;
    } catch {
        return new Response('', { status: 503, statusText: 'Offline' });
    }
}

/**
 * Network-first for the /messages shell. Healthy networks see zero change;
 * on failure (or >4s stall) the last good copy is served so the page — and
 * with it the server-rendered thread list — still loads offline.
 */
async function messagesShell(request) {
    const network = fetch(request).then((res) => {
        // Never cache redirects (login bounces would poison the offline shell)
        // or errors — only a real, direct 200 render of the page.
        if (res && res.status === 200 && !res.redirected) {
            const copy = res.clone();
            caches.open(PAGE_CACHE).then(cache => cache.put(MESSAGES_SHELL_KEY, copy));
        }
        return res;
    });

    try {
        const timeout = new Promise((resolve) => setTimeout(() => resolve(null), SHELL_NETWORK_TIMEOUT_MS));
        const res = await Promise.race([network, timeout]);
        if (res) return res;
    } catch (e) { /* fall through to cache */ }

    // Swallow the eventual network rejection so it never surfaces as unhandled.
    network.catch(() => {});

    const cached = await caches.open(PAGE_CACHE).then(cache => cache.match(MESSAGES_SHELL_KEY));
    if (cached) return cached;

    return new Response(
        '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Offline</title></head>'
        + '<body style="font-family: system-ui, sans-serif; display: flex; min-height: 100vh; align-items: center; justify-content: center; margin: 0; color: #3f3f46;">'
        + '<div style="text-align: center; padding: 2rem;"><h1 style="font-size: 1.1rem; margin: 0 0 .5rem;">You are offline</h1>'
        + '<p style="font-size: .9rem; margin: 0;">Messages have not been cached on this device yet. Reconnect and open Messages once to enable offline access.</p></div></body></html>',
        { status: 503, statusText: 'Offline', headers: { 'Content-Type': 'text/html; charset=UTF-8' } }
    );
}

/**
 * Cache-first MMS media with a FIFO cap (~LRU: Cache API keys keep insertion
 * order, trimmed on every put). Images only, by response content-type.
 */
async function smsMedia(request) {
    const cache = await caches.open(SMS_MEDIA_CACHE);
    const hit = await cache.match(request);
    if (hit) return hit;

    try {
        const res = await fetch(request);
        if (res.ok && (res.headers.get('content-type') || '').startsWith('image/')) {
            await cache.put(request, res.clone());
            trimCache(cache, SMS_MEDIA_MAX_ENTRIES); // fire-and-forget
        }
        return res;
    } catch {
        return new Response('', { status: 503, statusText: 'Offline' });
    }
}

async function trimCache(cache, maxEntries) {
    try {
        const keys = await cache.keys();
        for (let i = 0; i < keys.length - maxEntries; i++) {
            await cache.delete(keys[i]);
        }
    } catch (e) { /* trimming is best-effort */ }
}

/* ─── Push notifications ─────────────────────────────────────────── */

self.addEventListener('push', (event) => {
    console.log('[SW] Push event received', event);
    console.log('[SW] Push event data present:', !!event.data);

    event.waitUntil((async () => {
        let payload = {
            title: 'New Notification',
            body: 'You have a new alert.',
            tag: 'generic-notification',
            data: { url: '/messages' },
            requireInteraction: false,
        };

        if (!event.data) {
            console.warn('[SW] Push event has no data; showing fallback notification');
        } else {
            try {
                payload = event.data.json();
                console.log('[SW] Parsed payload', payload);
            } catch (error) {
                console.warn('[SW] Failed to parse JSON, using text fallback', error);
                payload = {
                    title: 'Notification',
                    body: event.data.text() || 'You have a new alert.',
                    tag: 'text-fallback-notification',
                    data: { url: '/messages' },
                    requireInteraction: false,
                };
            }
        }

        const title = payload.title || 'Notification';
        const options = {
            body: payload.body || '',
            icon: payload.icon || '/favicons/icon-192x192.png',
            badge: payload.badge || '/favicons/icon-96x96.png',
            tag: payload.tag || 'task-notification',
            data: payload.data || {},
            requireInteraction: payload.requireInteraction || false,
        };

        // Set app badge count on the home-screen icon (iOS 16.4+ PWA)
        if ('setAppBadge' in self.registration) {
            const badgeCount = payload.badgeCount;
            if (typeof badgeCount === 'number' && badgeCount > 0) {
                self.registration.setAppBadge(badgeCount).catch(() => {});
            }
        }

        try {
            await self.registration.showNotification(title, options);
        } catch (error) {
            console.error('[SW] showNotification failed', error);

            const fallbackOptions = {
                body: options.body,
                tag: options.tag,
                data: options.data,
                requireInteraction: options.requireInteraction,
            };

            try {
                await self.registration.showNotification(title, fallbackOptions);
            } catch (fallbackError) {
                console.error('[SW] showNotification fallback failed', fallbackError);
            }
        }
    })());
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    // Clear home-screen badge when user acts on a notification
    if ('clearAppBadge' in self.registration) {
        self.registration.clearAppBadge().catch(() => {});
    }

    const path = event.notification.data?.url || '/dashboard';
    const targetUrl = new URL(path, self.location.origin).href;
    const parsedUrl = new URL(path, self.location.origin);
    const threadId = parsedUrl.searchParams.get('threadId');

    event.waitUntil(
        // Persist the target URL so the page can pick it up even if postMessage is dropped
        caches.open('hive-pending-nav').then((cache) =>
            cache.put('/__pending_nav', new Response(targetUrl))
        ).then(() =>
            clients.matchAll({ type: 'window', includeUncontrolled: true })
        ).then((clientList) => {
            // If targeting a messages thread, post a message to an existing /messages page
            if (threadId) {
                for (const client of clientList) {
                    try {
                        const clientUrl = new URL(client.url);
                        if (clientUrl.origin === self.location.origin && clientUrl.pathname === '/messages') {
                            return client.focus().then((focused) => {
                                focused.postMessage({
                                    type: 'navigate-thread',
                                    threadId: parseInt(threadId, 10),
                                });
                            });
                        }
                    } catch (e) { /* skip invalid client URLs */ }
                }
            }

            // No existing /messages page — try focusing any app window and navigating
            for (const client of clientList) {
                if (client.url.startsWith(self.location.origin) && 'focus' in client) {
                    if ('navigate' in client) {
                        return client.focus().then((focused) => focused.navigate(targetUrl));
                    }
                    // iOS: focus first, then send message so the page is awake to receive it
                    return client.focus().then((focused) => {
                        focused.postMessage({ type: 'navigate-url', url: targetUrl });
                    });
                }
            }

            // No existing window — open a new one
            return clients.openWindow(targetUrl);
        })
    );
});
