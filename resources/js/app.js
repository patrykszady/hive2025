import './plaid-link';
import './timezone';
import Echo from 'laravel-echo';

// Alpine global store for SMS page state — survives Livewire morphs and component boundaries.
document.addEventListener('alpine:init', () => {
    window.Alpine.store('sms', {
        tab: 'messages',
        threadId: null,
        setTab(value) {
            this.tab = value;
        },
    });
});

import Pusher from 'pusher-js';
import { Html5Qrcode } from 'html5-qrcode';

window.Html5Qrcode = Html5Qrcode;

// Register/update service worker on every page load to replace stale versions
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js').catch(() => {});

    // Clear home-screen badge when the app is opened / resumed
    if ('clearAppBadge' in navigator) {
        navigator.clearAppBadge().catch(() => {});
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                navigator.clearAppBadge().catch(() => {});
            }
        });
    }

    // Handle navigation requests from notification clicks (iOS fallback)
    navigator.serviceWorker.addEventListener('message', (event) => {
        if (event.data?.type === 'navigate-url' && event.data?.url) {
            window.location.href = event.data.url;
        }
    });

    // Check for pending navigation stored by service worker (iOS notification click fallback)
    caches.open('hive-pending-nav').then((cache) =>
        cache.match('/__pending_nav').then((response) => {
            if (!response) return;
            cache.delete('/__pending_nav');
            return response.text();
        })
    ).then((url) => {
        if (url && !window.location.href.endsWith(new URL(url).pathname + new URL(url).search)) {
            window.location.href = url;
        }
    }).catch(() => {});
}

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});

const FADE_CLASS = 'opacity-0';

function setPageFadeHidden(hidden) {
	document.querySelectorAll('[data-page-fade]').forEach((element) => {
		if (hidden) {
			element.classList.add(FADE_CLASS);
		} else {
			element.classList.remove(FADE_CLASS);
		}
	});
}

// Custom smooth scroll with visible animation (600ms duration)
function smoothScrollTo(targetY, duration = 600) {
	const startY = window.pageYOffset;
	const distance = targetY - startY;
	let startTime = null;

	function easeInOutCubic(t) {
		return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
	}

	function step(currentTime) {
		if (!startTime) startTime = currentTime;
		const elapsed = currentTime - startTime;
		const progress = Math.min(elapsed / duration, 1);
		const eased = easeInOutCubic(progress);

		window.scrollTo(0, startY + distance * eased);

		if (progress < 1) {
			requestAnimationFrame(step);
		}
	}

	requestAnimationFrame(step);
}

// Expose globally for Alpine handlers
window.smoothScrollTo = smoothScrollTo;

// Get hash from inline script or current URL
const initialHash = window.__initialHash || window.location.hash;

// Initial paint: ensure content is visible immediately.
document.addEventListener('DOMContentLoaded', () => {
	setPageFadeHidden(false);

	if (!initialHash) {
		return;
	}

	const target = document.querySelector(initialHash);
	if (!target) {
		return;
	}

	// Small delay to let page settle, then smooth scroll
	setTimeout(() => {
		const top = target.getBoundingClientRect().top + window.pageYOffset - 80;
		smoothScrollTo(top, 800);
	}, 200);
});

// Livewire navigate: hide during navigation, show after
// Skip fade entirely for login <-> registration transitions
const guestAuthPaths = ['/login', '/registration'];

document.addEventListener('livewire:navigating', () => {
	const from = window.location.pathname;
	const toLink = document.activeElement?.closest('a[wire\\:navigate]')
		|| document.activeElement?.closest('[wire\\:navigate]');
	const to = toLink?.getAttribute('href')
		? new URL(toLink.getAttribute('href'), window.location.origin).pathname
		: null;

	if (guestAuthPaths.includes(from) && guestAuthPaths.includes(to)) {
		// No fade at all — Livewire morphs in-place, @persist keeps logo stable
		return;
	}

	setPageFadeHidden(true);
});

document.addEventListener('livewire:navigated', () => {
	setPageFadeHidden(false);

	// Handle hash scrolling after navigation
	const hash = window.location.hash;
	if (!hash) {
		return;
	}

	const target = document.querySelector(hash);
	if (!target) {
		return;
	}

	// Wait for fade-in to complete, then scroll
	setTimeout(() => {
		const top = target.getBoundingClientRect().top + window.pageYOffset - 80;
		smoothScrollTo(top, 800);
	}, 300);
});

// Handle anchor link clicks for smooth scrolling
document.addEventListener('click', (event) => {
	const anchor = event.target?.closest?.('a[href^="#"]');
	if (!anchor) {
		return;
	}

	// Skip if this anchor has wire:navigate (let Livewire handle it)
	if (anchor.hasAttribute('wire:navigate') || anchor.hasAttribute('wire:navigate.hover')) {
		return;
	}

	const href = anchor.getAttribute('href');
	if (!href || href === '#') {
		return;
	}

	const target = document.querySelector(href);
	if (!target) {
		return;
	}

	event.preventDefault();
	event.stopPropagation();
	history.pushState(null, '', href);

	const top = target.getBoundingClientRect().top + window.pageYOffset - 80;
	smoothScrollTo(top, 800);
}, true); // Use capture phase to get event before Flux components

function isNotificationSupported() {
	return typeof window !== 'undefined' && 'Notification' in window;
}

function getSupportedPushContentEncoding() {
	if (typeof PushManager === 'undefined') {
		return 'aes128gcm';
	}

	const encodings = Array.isArray(PushManager.supportedContentEncodings)
		? PushManager.supportedContentEncodings
		: [];

	if (encodings.length > 0) {
		return encodings[0];
	}

	return 'aes128gcm';
}

async function enableUpcomingTaskNotifications() {
	if (!isNotificationSupported()) {
		return { enabled: false, reason: 'unsupported' };
	}

	if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
		return { enabled: false, reason: 'push-unsupported' };
	}

	const permission = await Notification.requestPermission();
	if (permission !== 'granted') {
		return { enabled: false, reason: permission };
	}

	try {
		// Register service worker
		const registration = await navigator.serviceWorker.register('/sw.js');
		await navigator.serviceWorker.ready;

		// Get VAPID public key from server
		const vapidResponse = await fetch('/push/vapid-public-key', {
			credentials: 'same-origin',
			headers: { 'Accept': 'application/json' },
		});

		if (!vapidResponse.ok) {
			return { enabled: false, reason: 'vapid-fetch-failed' };
		}

		const { publicKey } = await vapidResponse.json();
		if (!publicKey) {
			return { enabled: false, reason: 'vapid-missing' };
		}

		// Subscribe to push (reuse existing subscription when present)
		let subscription = await registration.pushManager.getSubscription();
		if (!subscription) {
			subscription = await registration.pushManager.subscribe({
				userVisibleOnly: true,
				applicationServerKey: urlBase64ToUint8Array(publicKey),
			});
		}

		// Send subscription to server
		const subscribeResponse = await fetch('/push/subscribe', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'Accept': 'application/json',
				'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
			},
			body: JSON.stringify({
				...subscription.toJSON(),
				contentEncoding: getSupportedPushContentEncoding(),
			}),
		});

		if (!subscribeResponse.ok) {
			return { enabled: false, reason: 'subscribe-failed' };
		}

	} catch (err) {
		console.error('Push subscription failed:', err);
		return { enabled: false, reason: 'error' };
	}
	return { enabled: true };
}

async function getUpcomingTaskNotificationStatus() {
	if (!isNotificationSupported()) {
		return { supported: false, enabled: false, permission: 'default' };
	}

	const permission = Notification.permission;

	if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
		return { supported: true, enabled: false, permission };
	}

	try {
		const registration = await navigator.serviceWorker.getRegistration();
		if (!registration) {
			return { supported: true, enabled: false, permission };
		}

		const subscription = await registration.pushManager.getSubscription();
		if (!subscription) {
			return { supported: true, enabled: false, permission };
		}

		const statusResponse = await fetch('/push/status', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'Accept': 'application/json',
				'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
			},
			body: JSON.stringify({ endpoint: subscription.endpoint }),
		});

		if (!statusResponse.ok) {
			return { supported: true, enabled: false, permission };
		}

		const data = await statusResponse.json();

		return {
			supported: true,
			enabled: Boolean(data?.enabled),
			permission,
			preferences: data?.preferences || null,
		};
	} catch (err) {
		console.error('Push subscription status failed:', err);
		return { supported: true, enabled: false, permission, reason: 'error' };
	}
}

async function updateUpcomingTaskNotificationPreferences(preferences) {
	if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
		return { updated: false, reason: 'push-unsupported' };
	}

	const wantsAny = Boolean(
		preferences?.realtime_enabled
		|| preferences?.morning_enabled
		|| preferences?.evening_enabled
		|| preferences?.sms_inbound_enabled
	);

	try {
		const registration = await navigator.serviceWorker.getRegistration();
		if (!registration) {
			return { updated: false, reason: 'no-registration' };
		}

		let subscription = await registration.pushManager.getSubscription();
		if (!subscription) {
			if (!wantsAny) {
				return { updated: true, reason: 'no-subscription' };
			}

			const enabled = await enableUpcomingTaskNotifications();
			if (!enabled?.enabled) {
				return { updated: false, reason: 'subscribe-failed' };
			}

			subscription = await registration.pushManager.getSubscription();
			if (!subscription) {
				return { updated: false, reason: 'no-subscription' };
			}
		}

		const response = await fetch('/push/preferences', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'Accept': 'application/json',
				'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
			},
			body: JSON.stringify({
				endpoint: subscription.endpoint,
				preferences,
			}),
		});

		if (!response.ok) {
			return { updated: false, reason: 'preferences-failed' };
		}

		return { updated: true };
	} catch (err) {
		console.error('Push preferences update failed:', err);
		return { updated: false, reason: 'error' };
	}
}

async function getCurrentPushEndpoint() {
	if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
		return null;
	}

	const registration = await navigator.serviceWorker.getRegistration();
	if (!registration) {
		return null;
	}

	const subscription = await registration.pushManager.getSubscription();
	return subscription ? subscription.endpoint : null;
}

async function listUpcomingTaskNotificationSubscriptions() {
	try {
		const endpoint = await getCurrentPushEndpoint();
		const url = new URL('/push/subscriptions', window.location.origin);
		if (endpoint) {
			url.searchParams.set('endpoint', endpoint);
		}

		const response = await fetch(url.toString(), {
			credentials: 'same-origin',
			headers: { 'Accept': 'application/json' },
		});

		if (!response.ok) {
			return { success: false, subscriptions: [] };
		}

		const data = await response.json();
		return { success: true, subscriptions: data?.subscriptions || [] };
	} catch (err) {
		console.error('Push subscriptions fetch failed:', err);
		return { success: false, subscriptions: [] };
	}
}

async function disableUpcomingTaskNotifications() {
	if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
		return { disabled: false, reason: 'push-unsupported' };
	}

	try {
		const registration = await navigator.serviceWorker.getRegistration();
		if (!registration) {
			return { disabled: false, reason: 'no-registration' };
		}

		const subscription = await registration.pushManager.getSubscription();
		if (!subscription) {
			return { disabled: true };
		}

		const unsubscribeResponse = await fetch('/push/unsubscribe', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'Accept': 'application/json',
				'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
			},
			body: JSON.stringify({ endpoint: subscription.endpoint }),
		});

		if (!unsubscribeResponse.ok) {
			return { disabled: false, reason: 'unsubscribe-failed' };
		}

		await subscription.unsubscribe();

		return { disabled: true };
	} catch (err) {
		console.error('Push unsubscribe failed:', err);
		return { disabled: false, reason: 'error' };
	}
}

function urlBase64ToUint8Array(base64String) {
	const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
	const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
	const rawData = window.atob(base64);
	const outputArray = new Uint8Array(rawData.length);
	for (let i = 0; i < rawData.length; ++i) {
		outputArray[i] = rawData.charCodeAt(i);
	}
	return outputArray;
}

window.HiveTaskNotifications = {
	enable: enableUpcomingTaskNotifications,
	status: getUpcomingTaskNotificationStatus,
	disable: disableUpcomingTaskNotifications,
	updatePreferences: updateUpcomingTaskNotificationPreferences,
	listSubscriptions: listUpcomingTaskNotificationSubscriptions,
};