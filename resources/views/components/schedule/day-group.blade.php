@props([
    'date',
    'count' => null,
    'isFirst' => false,
    'open' => null,
])

@php
    $carbonDate = $date instanceof \Carbon\Carbon ? $date : \Carbon\Carbon::parse($date);
    $dateKey = $carbonDate->format('Y-m-d');
    $serverDayBadge = $carbonDate->isToday() ? 'today' : ($carbonDate->isTomorrow() ? 'tomorrow' : '');
    // Open everything by default except today
    $defaultOpen = $open ?? ($serverDayBadge !== 'today');
@endphp

<div wire:key="schedule-day-{{ $dateKey }}">
    <div
        x-data="{
            badge: '{{ $serverDayBadge }}',
            isFirst: {{ $isFirst ? 'true' : 'false' }},
            open: {{ $defaultOpen ? 'true' : 'false' }},
            init() {
                let p = '{{ $dateKey }}'.split('-');
                let d = new Date(p[0], p[1]-1, p[2]); d.setHours(0,0,0,0);
                let t = new Date(); t.setHours(0,0,0,0);
                let tm = new Date(t); tm.setDate(tm.getDate()+1);
                this.badge = d.getTime() === t.getTime() ? 'today' : (d.getTime() === tm.getTime() ? 'tomorrow' : '');
                this.open = this.badge !== 'today';
            }
        }"
        x-cloak
        class="space-y-3"
    >
        <button type="button" class="flex w-full items-center justify-between gap-2" x-on:click="open = !open">
            <div class="flex items-center gap-2">
                <flux:heading size="sm" class="text-zinc-700 dark:text-zinc-300">
                    {{ $carbonDate->format('D, M j') }}
                </flux:heading>
                <span class="contents">
                    <template x-if="badge === 'today'">
                        <flux:badge color="green" size="sm">Today</flux:badge>
                    </template>
                    <template x-if="badge === 'tomorrow'">
                        <flux:badge color="sky" size="sm">Tomorrow</flux:badge>
                    </template>
                    <template x-if="isFirst && badge === ''">
                        <flux:badge color="sky" size="sm">Next Up</flux:badge>
                    </template>
                </span>

                @if(! is_null($count))
                    <flux:badge color="zinc" size="sm">{{ $count }}</flux:badge>
                @endif
            </div>

            <flux:icon.chevron-up class="size-4 text-zinc-400 transition-transform" x-bind:class="open ? '' : 'rotate-180'" />
        </button>

        <div x-show="open" x-collapse>
            <div class="space-y-3 mt-2">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
