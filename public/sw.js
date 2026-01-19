/**
 * Service Worker for Web Push Notifications
 */

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(clients.claim());
});

self.addEventListener('push', (event) => {
    if (!event.data) {
        return;
    }

    let payload;
    try {
        payload = event.data.json();
    } catch (e) {
        payload = { title: 'Notification', body: event.data.text() };
    }

    const title = payload.title || 'Task Reminder';
    const options = {
        body: payload.body || '',
        icon: payload.icon || '/images/logo-192.png',
        badge: payload.badge || '/images/logo-72.png',
        tag: payload.tag || 'task-notification',
        data: payload.data || {},
        requireInteraction: payload.requireInteraction || false,
    };

    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = event.notification.data?.url || '/dashboard';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            for (const client of clientList) {
                if (client.url.includes(self.location.origin) && 'focus' in client) {
                    client.focus();
                    if (url) {
                        client.navigate(url);
                    }
                    return;
                }
            }
            return clients.openWindow(url);
        })
    );
});
