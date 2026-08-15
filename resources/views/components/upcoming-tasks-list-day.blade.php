@php
    $carbonDate = \Carbon\Carbon::parse($date);
    $isWeekend = $carbonDate->isWeekend();
    $hasTasks = $tasks->isNotEmpty();

    // Server-side pre-compute using browser date (session/cookie) for instant render
    $browserToday = browser_date() ?? now()->format('Y-m-d');
    $browserTomorrow = \Carbon\Carbon::parse($browserToday)->addDay()->format('Y-m-d');
    $browserYesterday = \Carbon\Carbon::parse($browserToday)->subDay()->format('Y-m-d');

    $serverBadge = match($date) {
        $browserToday => 'today',
        $browserTomorrow => 'tomorrow',
        $browserYesterday => 'yesterday',
        default => '',
    };
    $serverIsPast = $date < $browserToday;
    $serverOpacity = match(true) {
        $serverIsPast && !$hasTasks && $isWeekend => 'opacity-30',
        $serverIsPast && !$hasTasks => 'opacity-40',
        $serverIsPast && $hasTasks => 'opacity-50',
        $isWeekend && !$hasTasks => 'opacity-50',
        default => '',
    };
    $serverTextColor = match(true) {
        $serverBadge === 'today' => 'text-indigo-600 dark:text-indigo-400',
        $serverIsPast || $isWeekend => 'text-zinc-400 dark:text-zinc-500',
        default => 'text-zinc-700 dark:text-zinc-300',
    };
@endphp

{{-- Server pre-computes badge/opacity/color from the browser-date cookie;
     Alpine only CORRECTS them if the real browser timezone disagrees.

     Deliberately imperative: the previous version kept state in x-data and
     bound children with ::class / x-show, which threw "badge is not defined"
     and "textColorClass is not defined" on every load — during the lazy-load
     morph the children are evaluated before the parent scope exists. Writing
     to the DOM from this element's own x-init has no cross-element scope to
     lose. Same fix as components/truncate-tooltip.blade.php. --}}
<div
    class="space-y-2 {{ $serverOpacity }}"
    data-day-wrapper
    x-init="(() => {
        const parts = '{{ $date }}'.split('-');
        const d = new Date(parts[0], parts[1] - 1, parts[2]);
        d.setHours(0, 0, 0, 0);

        const now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const tomorrow = new Date(today); tomorrow.setDate(tomorrow.getDate() + 1);
        const yesterday = new Date(today); yesterday.setDate(yesterday.getDate() - 1);

        const isWeekend = {{ $isWeekend ? 'true' : 'false' }};
        const hasTasks = {{ $hasTasks ? 'true' : 'false' }};
        const isPast = d.getTime() < today.getTime();
        const badge = d.getTime() === today.getTime() ? 'today'
            : (d.getTime() === tomorrow.getTime() ? 'tomorrow'
            : (d.getTime() === yesterday.getTime() ? 'yesterday' : ''));

        const opacity = (isPast && !hasTasks) ? (isWeekend ? 'opacity-30' : 'opacity-40')
            : ((isPast && hasTasks) || (isWeekend && !hasTasks)) ? 'opacity-50' : '';

        $el.classList.remove('opacity-30', 'opacity-40', 'opacity-50');
        if (opacity) $el.classList.add(opacity);

        const heading = $el.querySelector('[data-day-heading]');
        if (heading) {
            heading.classList.remove(
                'text-indigo-600', 'dark:text-indigo-400',
                'text-zinc-400', 'dark:text-zinc-500',
                'text-zinc-700', 'dark:text-zinc-300',
            );
            const color = badge === 'today' ? ['text-indigo-600', 'dark:text-indigo-400']
                : (isPast || isWeekend) ? ['text-zinc-400', 'dark:text-zinc-500']
                : ['text-zinc-700', 'dark:text-zinc-300'];
            heading.classList.add(...color);
        }

        $el.querySelectorAll('[data-day-badge]').forEach(el => {
            el.style.display = el.dataset.dayBadge === badge ? '' : 'none';
        });
    })()"
>
    {{-- Date Header - min-h-6 reserves space for badge to prevent layout shift --}}
    <div class="flex items-center gap-2 min-h-6">
        <flux:heading size="sm" class="{{ $serverTextColor }}" data-day-heading>
            {{ $carbonDate->format('D, M j') }}
        </flux:heading>

        {{-- Server-rendered visible immediately; x-init above hides the wrong ones. --}}
        <span data-day-badge="today" @if($serverBadge !== 'today') style="display:none" @endif>
            <flux:badge color="indigo" size="sm">Today</flux:badge>
        </span>
        <span data-day-badge="tomorrow" @if($serverBadge !== 'tomorrow') style="display:none" @endif>
            <flux:badge color="sky" size="sm">Tomorrow</flux:badge>
        </span>
        <span data-day-badge="yesterday" @if($serverBadge !== 'yesterday') style="display:none" @endif>
            <flux:badge color="zinc" size="sm">Yesterday</flux:badge>
        </span>
    </div>

    @include('components.upcoming-tasks-list-tasks', [
        'tasks' => $tasks,
        'date' => $date,
        'carbonDate' => $carbonDate,
        'showAvatars' => $showAvatars,
        'clickable' => $clickable,
        'showProjectInfo' => $showProjectInfo,
        'showVendorInfo' => $showVendorInfo,
        'publicView' => $publicView,
    ])
</div>
