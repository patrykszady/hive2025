/**
 * Service Worker for Web Push Notifications
 * Version: 2026-02-15-v2
 */

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(clients.claim());
});

self.addEventListener('push', (event) => {
    console.log('[SW] Push event received', event);

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

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            // Try to focus an existing tab and navigate it
            for (const client of clientList) {
                if (client.url.startsWith(self.location.origin) && 'focus' in client) {
                    return client.focus().then((focused) => focused.navigate(targetUrl));
                }
            }
            // No existing tab — open a new one
            return clients.openWindow(targetUrl);
        })
    );
});
