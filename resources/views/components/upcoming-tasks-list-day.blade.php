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

{{-- Server pre-computes badge/opacity/color; Alpine confirms using browser timezone --}}
<div
    class="space-y-2 {{ $serverOpacity }}"
    x-data="{
        date: '{{ $date }}',
        isWeekend: {{ $isWeekend ? 'true' : 'false' }},
        hasTasks: {{ $hasTasks ? 'true' : 'false' }},
        badge: '{{ $serverBadge }}',
        isPast: {{ $serverIsPast ? 'true' : 'false' }},
        opacityClass: '{{ $serverOpacity }}',
        textColorClass: '{{ $serverTextColor }}',
        init() {
            const parts = this.date.split('-');
            const d = new Date(parts[0], parts[1] - 1, parts[2]);
            d.setHours(0, 0, 0, 0);

            const now = new Date();
            const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            const tomorrow = new Date(today);
            tomorrow.setDate(tomorrow.getDate() + 1);

            const yesterday = new Date(today);
            yesterday.setDate(yesterday.getDate() - 1);

            this.isPast = d.getTime() < today.getTime();
            this.badge = d.getTime() === today.getTime() ? 'today'
                : (d.getTime() === tomorrow.getTime() ? 'tomorrow'
                : (d.getTime() === yesterday.getTime() ? 'yesterday' : ''));

            if (this.isPast && !this.hasTasks) {
                this.opacityClass = this.isWeekend ? 'opacity-30' : 'opacity-40';
            } else if (this.isPast && this.hasTasks) {
                this.opacityClass = 'opacity-50';
            } else if (this.isWeekend && !this.hasTasks) {
                this.opacityClass = 'opacity-50';
            } else {
                this.opacityClass = '';
            }

            if (this.badge === 'today') {
                this.textColorClass = 'text-indigo-600 dark:text-indigo-400';
            } else if (this.isPast || this.isWeekend) {
                this.textColorClass = 'text-zinc-400 dark:text-zinc-500';
            } else {
                this.textColorClass = 'text-zinc-700 dark:text-zinc-300';
            }
        }
    }"
    :class="opacityClass"
>
    {{-- Date Header - min-h-6 reserves space for badge to prevent layout shift --}}
    <div class="flex items-center gap-2 min-h-6">
        <flux:heading size="sm" class="{{ $serverTextColor }}" ::class="textColorClass">
            {{ $carbonDate->format('D, M j') }}
        </flux:heading>

        {{-- Badges: server-rendered visible immediately, Alpine hides/shows on init --}}
        <span x-show="badge === 'today'" @if($serverBadge !== 'today') style="display:none" @endif>
            <flux:badge color="indigo" size="sm">Today</flux:badge>
        </span>
        <span x-show="badge === 'tomorrow'" @if($serverBadge !== 'tomorrow') style="display:none" @endif>
            <flux:badge color="sky" size="sm">Tomorrow</flux:badge>
        </span>
        <span x-show="badge === 'yesterday'" @if($serverBadge !== 'yesterday') style="display:none" @endif>
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
