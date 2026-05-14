/**
 * Service Worker — Push Notifications
 * Cache version is stamped by `npm run build` so every deploy busts stale assets.
 */

const DEPLOY_VERSION = 'mp4xluyl';
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

    // Build / static assets → cache-first
    if (/^\/(build|js|css|fonts|favicons)\//.test(url.pathname)) {
        event.respondWith(cacheFirst(event.request, ASSET_CACHE));
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
