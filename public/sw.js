/**
 * Service Worker — Push Notifications + Offline Messages Cache
 * Cache version is stamped by `npm run build` so every deploy busts stale assets.
 */

const DEPLOY_VERSION = 'mn6wns94';
const PAGE_CACHE  = 'hive-pages-' + DEPLOY_VERSION;
const ASSET_CACHE = 'hive-assets-' + DEPLOY_VERSION;

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
                    keys.filter(k => ![PAGE_CACHE, ASSET_CACHE].includes(k))
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

    // /messages navigation → network-first, 5 s timeout, cache fallback
    if (event.request.mode === 'navigate' && url.pathname.startsWith('/messages')) {
        event.respondWith(networkFirst(event.request, PAGE_CACHE, 5000));
        return;
    }

    // Build / static assets → cache-first
    if (/^\/(build|js|css|fonts|favicons)\//.test(url.pathname)) {
        event.respondWith(cacheFirst(event.request, ASSET_CACHE));
        return;
    }
});

async function networkFirst(request, cacheName, timeoutMs) {
    const cache = await caches.open(cacheName);
    try {
        const ctrl  = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), timeoutMs);
        const res   = await fetch(request, { signal: ctrl.signal });
        clearTimeout(timer);
        if (res.ok) cache.put(request, res.clone());
        return res;
    } catch {
        return (await cache.match(request)) || offlinePage();
    }
}

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

function offlinePage() {
    return new Response(
        '<!doctype html><html><head><meta charset=utf-8><meta name=viewport content="width=device-width"><title>Offline</title></head>'
      + '<body style="font-family:system-ui;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#18181b;color:#e4e4e7">'
      + '<div style="text-align:center;padding:2rem"><h2>You\u2019re offline</h2><p>Messages will load once you reconnect.</p></div></body></html>',
        { headers: { 'Content-Type': 'text/html' } }
    );
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
