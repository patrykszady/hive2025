/**
 * Automatically convert UTC dates to browser's local timezone using Alpine.js
 * 
 * Usage in Blade:
 * <time x-datetime="{{ $date->toIso8601String() }}"></time>
 * <time x-datetime="{{ $date->toIso8601String() }}" x-datetime-format="date"></time>
 * <time x-datetime="{{ $date->toIso8601String() }}" x-datetime-format="relative"></time>
 */

function getBrowserLocalDateString() {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function getCachedBrowserTimezone() {
    try {
        const cached = window.localStorage?.getItem('hive.browser.timezone');
        if (cached) {
            return cached;
        }
    } catch {
        // ignore
    }

    let timezone = 'UTC';
    try {
        timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    } catch {
        timezone = 'UTC';
    }

    try {
        window.localStorage?.setItem('hive.browser.timezone', timezone);
    } catch {
        // ignore
    }

    return timezone;
}

function getLastBrowserSyncPayload() {
    try {
        const raw = window.sessionStorage?.getItem('hive.browser.timezoneSync');
        if (!raw) {
            return null;
        }

        return JSON.parse(raw);
    } catch {
        return null;
    }
}

function setLastBrowserSyncPayload(payload) {
    try {
        window.sessionStorage?.setItem('hive.browser.timezoneSync', JSON.stringify(payload));
    } catch {
        // ignore
    }
}

function shouldThrottleBrowserSync(lastPayload) {
    if (!lastPayload || typeof lastPayload !== 'object') {
        return false;
    }

    const lastSyncAt = Number(lastPayload.syncedAt ?? 0);
    if (!Number.isFinite(lastSyncAt) || lastSyncAt <= 0) {
        return false;
    }

    // "Once every few hours" – 4h default.
    const THROTTLE_MS = 4 * 60 * 60 * 1000;
    return (Date.now() - lastSyncAt) < THROTTLE_MS;
}

function syncBrowserTimezoneToServer() {
    if (!window.Livewire || typeof window.Livewire.dispatchTo !== 'function') {
        return;
    }

    const timezone = getCachedBrowserTimezone();
    const date = getBrowserLocalDateString();

    if (window.Alpine?.store) {
        const store = window.Alpine.store('timezone');
        if (store) {
            store.today = date;
            store.name = timezone;
            store.offset = new Date().getTimezoneOffset();
        }
    }

    const last = window.__hiveBrowserTimezoneSync;
    if (last && last.timezone === timezone && last.date === date) {
        return;
    }

    const lastSession = getLastBrowserSyncPayload();
    if (lastSession && lastSession.timezone === timezone && lastSession.date === date && shouldThrottleBrowserSync(lastSession)) {
        window.__hiveBrowserTimezoneSync = { timezone, date };
        return;
    }

    window.__hiveBrowserTimezoneSync = { timezone, date };
    setLastBrowserSyncPayload({ timezone, date, syncedAt: Date.now() });
    window.Livewire.dispatchTo('browser-timezone', 'browser-timezone-sync', { timezone, date });
}

function scheduleBrowserTimezoneSync({ maxAttempts = 25, delayMs = 100 } = {}) {
    let attempts = 0;

    const attempt = () => {
        attempts += 1;

        try {
            syncBrowserTimezoneToServer();
        } catch (error) {
            console.error('Error syncing browser timezone:', error);
        }

        if ((!window.Livewire || typeof window.Livewire.dispatchTo !== 'function') && attempts < maxAttempts) {
            window.setTimeout(attempt, delayMs);
        }
    };

    attempt();
}

document.addEventListener('alpine:init', () => {
    // Alpine.js directive for automatic timezone conversion
    Alpine.directive('datetime', (el, { expression, modifiers }, { evaluate }) => {
        const utcDate = evaluate(expression);
        if (!utcDate) return;
        
        try {
            const date = new Date(utcDate);
            const format = el.getAttribute('x-datetime-format') || 'default';
            
            let formatted;
            if (format === 'default') {
                // Default format: Nov 26, 2025 3:45 PM
                formatted = date.toLocaleString('en-US', {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric',
                    hour: 'numeric',
                    minute: '2-digit',
                    hour12: true
                });
            } else if (format === 'date') {
                // Date only: Nov 26, 2025
                formatted = date.toLocaleString('en-US', {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric'
                });
            } else if (format === 'time') {
                // Time only: 3:45 PM
                formatted = date.toLocaleString('en-US', {
                    hour: 'numeric',
                    minute: '2-digit',
                    hour12: true
                });
            } else if (format === 'relative') {
                // Relative time: "2 hours ago"
                formatted = getRelativeTime(date);
            } else if (format === 'short') {
                // Short format: 11/26/25
                formatted = date.toLocaleDateString('en-US', {
                    month: 'numeric',
                    day: 'numeric',
                    year: '2-digit'
                });
            } else {
                // Custom format
                formatted = date.toLocaleString('en-US', JSON.parse(format));
            }
            
            el.textContent = formatted;
            el.setAttribute('title', date.toLocaleString('en-US', {
                timeZoneName: 'short'
            }));
        } catch (error) {
            console.error('Error converting date:', error, utcDate);
        }
    });
    
    // Alpine magic helper for inline date conversion
    Alpine.magic('toLocalDate', () => {
        return (utcDate, format = 'default') => {
            if (!utcDate) return '';
            
            try {
                const date = new Date(utcDate);
                
                if (format === 'date') {
                    return date.toLocaleString('en-US', {
                        month: 'short',
                        day: 'numeric',
                        year: 'numeric'
                    });
                } else if (format === 'time') {
                    return date.toLocaleString('en-US', {
                        hour: 'numeric',
                        minute: '2-digit',
                        hour12: true
                    });
                } else if (format === 'relative') {
                    return getRelativeTime(date);
                } else {
                    return date.toLocaleString('en-US', {
                        month: 'short',
                        day: 'numeric',
                        year: 'numeric',
                        hour: 'numeric',
                        minute: '2-digit',
                        hour12: true
                    });
                }
            } catch (error) {
                console.error('Error converting date:', error, utcDate);
                return utcDate;
            }
        };
    });
    
    // Store browser timezone in Alpine store for easy access
    Alpine.store('timezone', {
        name: getCachedBrowserTimezone(),
        offset: new Date().getTimezoneOffset(),
        today: getBrowserLocalDateString(),
    });
});

// Keep server-side session in sync with browser timezone/date.
// livewire:navigated fires on initial page load and after wire:navigate.
document.addEventListener('livewire:navigated', () => {
    scheduleBrowserTimezoneSync();
});

// Some browsers (notably Safari) can be finicky about when Livewire navigation events
// fire on the first page load. Make sure we also attempt a sync during init/load.
document.addEventListener('livewire:init', () => {
    scheduleBrowserTimezoneSync();
});

document.addEventListener('DOMContentLoaded', () => {
    scheduleBrowserTimezoneSync();
});

function getRelativeTime(date) {
    const now = new Date();
    const diffMs = now - date;
    const diffSecs = Math.floor(diffMs / 1000);
    const diffMins = Math.floor(diffSecs / 60);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);
    
    if (diffSecs < 60) return 'just now';
    if (diffMins < 60) return `${diffMins} min${diffMins !== 1 ? 's' : ''} ago`;
    if (diffHours < 24) return `${diffHours} hr${diffHours !== 1 ? 's' : ''} ago`;
    if (diffDays < 30) return `${diffDays} day${diffDays !== 1 ? 's' : ''} ago`;
    
    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    });
}
