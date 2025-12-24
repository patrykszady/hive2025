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