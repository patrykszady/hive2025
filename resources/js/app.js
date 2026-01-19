import './plaid-link';
import './timezone';

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

// Initial paint: show content on next frame so CSS has applied.
document.addEventListener('DOMContentLoaded', () => {
	requestAnimationFrame(() => setPageFadeHidden(false));
});

// Livewire navigate: hide during swap, show after.
document.addEventListener('livewire:navigating', () => {
	setPageFadeHidden(true);
});

document.addEventListener('livewire:navigated', () => {
	requestAnimationFrame(() => setPageFadeHidden(false));
});

window.addEventListener('vendor-registration:complete', (event) => {
	const detail = event?.detail ?? {};
	const url = typeof detail.url === 'string' ? detail.url : null;
	const delayMs = Number.isFinite(Number(detail.delayMs)) ? Number(detail.delayMs) : 0;
	const fadeMs = Number.isFinite(Number(detail.fadeMs)) ? Number(detail.fadeMs) : 250;

	if (!url) {
		return;
	}

	setTimeout(() => {
		setPageFadeHidden(true);

		setTimeout(() => {
			if (window.Livewire && typeof window.Livewire.navigate === 'function') {
				window.Livewire.navigate(url);
				return;
			}

			window.location.assign(url);
		}, fadeMs);
	}, Math.max(0, delayMs));
});

function isNotificationSupported() {
	return typeof window !== 'undefined' && 'Notification' in window;
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

		// Subscribe to push
		const subscription = await registration.pushManager.subscribe({
			userVisibleOnly: true,
			applicationServerKey: urlBase64ToUint8Array(publicKey),
		});

		// Send subscription to server
		const subscribeResponse = await fetch('/push/subscribe', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'Accept': 'application/json',
				'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
			},
			body: JSON.stringify(subscription.toJSON()),
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
};