/**
 * Automatically convert UTC dates to browser's local timezone using Alpine.js
 * 
 * Usage in Blade:
 * <time x-datetime="{{ $date->toIso8601String() }}"></time>
 * <time x-datetime="{{ $date->toIso8601String() }}" x-datetime-format="date"></time>
 * <time x-datetime="{{ $date->toIso8601String() }}" x-datetime-format="relative"></time>
 */

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
        name: Intl.DateTimeFormat().resolvedOptions().timeZone,
        offset: new Date().getTimezoneOffset()
    });
});

function getRelativeTime(date) {
    const now = new Date();
    const diffMs = now - date;
    const diffSecs = Math.floor(diffMs / 1000);
    const diffMins = Math.floor(diffSecs / 60);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);
    
    if (diffSecs < 60) return 'just now';
    if (diffMins < 60) return `${diffMins} minute${diffMins !== 1 ? 's' : ''} ago`;
    if (diffHours < 24) return `${diffHours} hour${diffHours !== 1 ? 's' : ''} ago`;
    if (diffDays < 30) return `${diffDays} day${diffDays !== 1 ? 's' : ''} ago`;
    
    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    });
}
