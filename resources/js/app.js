import './plaid-link';
import './timezone';
import './planner-infinite-scroll';
import Echo from 'laravel-echo';

// Alpine global store for SMS page state — survives Livewire morphs and component boundaries.
document.addEventListener('alpine:init', () => {
    window.Alpine.store('sms', {
        tab: 'messages',
        threadId: null,
        callId: null,
        // Offline messaging state (owned by sms-offline.js): `offline` gates
        // the thread-list tap handler; `offlineBanner` feeds the banner on
        // /messages ('' = hidden).
        offline: false,
        offlineBanner: '',
        setTab(value) {
            this.tab = value;
        },
        setCallId(value) {
            this.callId = value;
        },
        // ?callId= owns which call is open; this store only mirrors it, and
        // that mirror must be re-taken whenever the URL changes. The store is
        // global and survives wire:navigate while the Livewire panes re-mount
        // empty, so a leftover callId hides the mobile call list in favour of
        // a detail pane with nothing in it — and that pane's back button only
        // renders once a call has loaded, which makes it a dead end.
        syncCallSelectionFromUrl() {
            const raw = new URL(window.location.href).searchParams.get('callId');
            const id = parseInt(raw ?? '', 10);
            this.callId = Number.isNaN(id) ? null : id;
        },
        // Deselect in both places at once. Dropping the URL param matters as
        // much as the store: syncCallSelectionFromUrl() would otherwise hand
        // the same dead id straight back on the next tab switch.
        clearCallSelection() {
            this.callId = null;
            const url = new URL(window.location.href);
            if (url.searchParams.has('callId')) {
                url.searchParams.delete('callId');
                window.history.replaceState({}, '', url);
            }
        },
    });
});

// On the /messages page, never let `threadId` linger on the calls tab and
// never let `callId` linger on the messages tab. Livewire's #[Url] sync can
// re-push stale params after morph; this listener strips them after every
// history change and after every Livewire commit.
(() => {
    const cleanMessagesUrl = () => {
        if (! window.location.pathname.startsWith('/messages')) return;
        const url = new URL(window.location.href);
        const tab = url.searchParams.get('activeTab');
        let changed = false;
        if (tab === 'calls' && url.searchParams.has('threadId')) {
            url.searchParams.delete('threadId'); changed = true;
        }
        if (tab !== 'calls' && url.searchParams.has('callId')) {
            url.searchParams.delete('callId'); changed = true;
        }
        if (changed) window.history.replaceState({}, '', url);
    };

    // Run on initial load.
    document.addEventListener('DOMContentLoaded', cleanMessagesUrl);
    // Run after every Livewire request settles (covers all morph-time URL pushes).
    document.addEventListener('livewire:init', () => {
        if (window.Livewire) {
            window.Livewire.hook('morph.updated', cleanMessagesUrl);
            window.Livewire.hook('commit', ({ succeed }) => {
                succeed(() => queueMicrotask(cleanMessagesUrl));
            });

			// Recover from stale component snapshots (common right after deploy).
			// Reload only once to avoid request loops from an invalid payload.
			let reloadedAfterCorruptSnapshot = false;
			window.Livewire.hook('request', ({ fail }) => {
				fail(({ status, content }) => {
					if (reloadedAfterCorruptSnapshot) {
						return;
					}

					const isCorruptSnapshot = status === 500
						&& typeof content === 'string'
						&& content.includes('Livewire encountered corrupt data when trying to hydrate a component');

					if (isCorruptSnapshot) {
						reloadedAfterCorruptSnapshot = true;
						window.location.reload();
					}
				});
			});
        }
    });
})();

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

// Offline SMS cache — must come after Echo so its background re-warm can
// subscribe to the sms.notifications channel. Site-wide on purpose: app.js
// persists across wire:navigate, so caching runs on every page.
import('./sms-offline');

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

// Preserve the sidebar's scroll position across wire:navigate page loads —
// the nav re-renders on each navigation and would otherwise jump to the top.
let sidebarScrollTop = 0;
document.addEventListener('livewire:navigating', () => {
	sidebarScrollTop = document.querySelector('[data-sidebar-scroll]')?.scrollTop ?? 0;
});
document.addEventListener('livewire:navigated', () => {
	if (!sidebarScrollTop) return;
	const sidebar = document.querySelector('[data-sidebar-scroll]');
	if (sidebar) {
		requestAnimationFrame(() => { sidebar.scrollTop = sidebarScrollTop; });
	}
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

// Hover prefetch prioritisation.
//
// `wire:navigate.hover` fires a prefetch 60ms into a hover and never cancels it,
// so sweeping the mouse down a table queues one request per row and the row you
// actually stopped on ends up behind all of them.
//
// Fix: hand every navigate fetch an AbortController we own — Livewire's
// `navigate.request` hook runs synchronously inside sendNavigateRequest()
// (dist 11232) immediately before `fetch()` — so a fresh prefetch can cancel the
// stale one and take the connection now.
//
// What must never be cancelled is a URI the user committed to. A click on an
// in-flight prefetch is served by that very request (getPretchedHtmlOr, dist
// 12775), so aborting it leaves the navigation with nothing to swap in: no error,
// no progress bar, the page just sits there. Two independent guards prevent it —
// the committed URI is never tracked as abortable, and nothing is aborted at all
// while a navigation is waiting on its html. Aborting a *hover* prefetch is safe
// because prefetchHtml's error path deletes its own cache entry (dist 12764), so
// a cancelled link simply re-fetches if it is later clicked.
//
// After `composer update livewire/livewire`, re-check those three dist spots and
// sanity-test in a browser: hover row A, hover row B, click row A → A must load.
(() => {
	// Older browsers (no AbortController, or abort() without a reason — we need
	// the reason to recognise our own cancellations) keep Livewire's stock
	// behaviour: every prefetch runs to completion.
	if (typeof AbortController === 'undefined' || !('reason' in AbortSignal.prototype)) {
		return;
	}

	// The only two wire:navigate forms this app uses (same pair as the anchor
	// handler above). A bare '[href]' would also match SVG <use> and plain links.
	const NAVIGATE_LINK = '[wire\\:navigate], [wire\\:navigate\\.hover]';

	// One shared reason object, so the rejection our abort causes can be silenced
	// by identity — never by sniffing for 'AbortError', which would also swallow
	// genuine navigation failures.
	const SUPERSEDED = new DOMException('Prefetch superseded by a newer hover', 'AbortError');

	// A navigation that never lands (network error, a listener cancelling
	// alpine:navigate) must not protect a URI from cancellation forever.
	const TTL = 20000;

	let pending = null;         // { uri, controller } — the one abortable prefetch
	let committedUri = null;    // where the user actually decided to go
	let committedAt = 0;
	let navigatingSince = 0;    // a real navigation is waiting on its html right now

	// Must match the key Livewire caches under (getUriStringFromUrlObject, 12736).
	const uriOf = (url) => url.pathname + url.search + url.hash;

	// Mirrors linkShouldBeHandledNatively (dist 12722): the browser handles these
	// itself, so committing one would only park a URI no navigation ever clears.
	const uriOfLink = (el) => {
		const href = el.getAttribute('href');
		if (href === null || el.hasAttribute('download')) return null;

		const target = el.getAttribute('target')?.trim().toLowerCase();
		if (target && target !== '_self') return null;

		try {
			const url = new URL(href, document.baseURI);
			const sameSite = url.origin === window.location.origin
				&& ['http:', 'https:'].includes(url.protocol);

			return sameSite ? uriOf(url) : null;
		} catch {
			return null;
		}
	};

	const fresh = (stamp) => stamp !== 0 && Date.now() - stamp < TTL;
	const isCommitted = (uri) => uri === committedUri && fresh(committedAt);
	const isNavigating = () => fresh(navigatingSince);

	const abortPending = () => {
		// Second guard: even if the committed URI were misread, never cancel
		// anything while a navigation is waiting for its html.
		if (!pending || isNavigating()) return;

		pending.controller.abort(SUPERSEDED);
		pending = null;
	};

	const commit = (uri, { abortStale }) => {
		if (!uri) return;

		committedUri = uri;
		committedAt = Date.now();

		if (!pending) return;

		// This exact request will serve the click — it stops being disposable.
		if (pending.uri === uri) {
			pending = null;
			return;
		}

		if (abortStale) abortPending();
	};

	// Keep Livewire's own PageRequest signal live alongside ours instead of
	// replacing it, in case Livewire ever starts cancelling navigate requests.
	const combineSignals = (a, b) => {
		if (typeof AbortSignal.any === 'function') return AbortSignal.any([a, b]);

		const merged = new AbortController();

		for (const signal of [a, b]) {
			if (signal.aborted) {
				merged.abort(signal.reason);
				return merged.signal;
			}

			signal.addEventListener('abort', () => merged.abort(signal.reason), { once: true });
		}

		return merged.signal;
	};

	// Same predicates Livewire uses to decide a press is a navigation (dist 12662),
	// so ctrl+click, middle-click and modified Enter never commit.
	const isPlainLeftClick = (e) => !(e.which > 1 || e.altKey || e.ctrlKey || e.metaKey || e.shiftKey);
	const isPlainEnter = (e) => e.which === 13 && !(e.altKey || e.ctrlKey || e.metaKey || e.shiftKey);

	const linkFrom = (e) => e.target?.closest?.(NAVIGATE_LINK);

	// Capture on `document` beats Livewire's listeners, which sit on the link
	// itself — intent is recorded before the press becomes a fetch (Livewire
	// prefetches at mousedown, dist 13224). Aborting here is free: the navigation
	// and its progress bar only start on mouseup, a later task.
	document.addEventListener('mousedown', (e) => {
		const link = linkFrom(e);
		if (link && isPlainLeftClick(e)) commit(uriOfLink(link), { abortStale: true });
	}, true);

	// Enter and programmatic clicks start their navigation inside this same task,
	// so an abort here would land its rejection after showAndStartProgressBar()
	// armed the bar but before it painted, silently hiding it. Protect the URI,
	// let the stale prefetch finish.
	document.addEventListener('keydown', (e) => {
		const link = linkFrom(e);
		if (link && isPlainEnter(e)) commit(uriOfLink(link), { abortStale: false });
	}, true);

	document.addEventListener('click', (e) => {
		if (e.isTrusted) return; // real clicks already committed on mousedown
		const link = linkFrom(e);
		if (link) commit(uriOfLink(link), { abortStale: false });
	}, true);

	// Covers Alpine.navigate() and back/forward, which never touch a link. On the
	// click path this fires at mouseup, long after the request — hence mousedown.
	document.addEventListener('livewire:navigate', (e) => {
		if (!e.detail?.url) return;

		commit(uriOf(e.detail.url), { abortStale: false });
		navigatingSince = Date.now();
	});

	document.addEventListener('livewire:navigated', () => {
		pending = null;
		committedUri = null;
		navigatingSince = 0;
	});

	// Our aborts are deliberate, but Livewire re-throws them out of a promise
	// nobody awaits. Silence ours, and only ours.
	window.addEventListener('unhandledrejection', (e) => {
		if (e.reason === SUPERSEDED) e.preventDefault();
	});

	document.addEventListener('livewire:init', () => {
		window.Livewire.hook('navigate.request', ({ uri, options }) => {
			if (isCommitted(uri)) return; // the real navigation — never abortable

			if (pending && pending.uri !== uri) abortPending();

			const controller = new AbortController();
			options.signal = combineSignals(options.signal, controller.signal);
			pending = { uri, controller };
		});
	});
})();

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