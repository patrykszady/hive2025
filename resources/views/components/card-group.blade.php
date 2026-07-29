{{-- A GROUP inside a card: heading + meta badges on one row, its content under
     them. This is how ONE card holds several tables — a check's weeks, a
     project's draws — because a card nested inside a card reads as a separate
     surface and breaks the page's rhythm.

     collapsible (default) gives the group its own Alpine `open` and a chevron,
     so groups toggle independently of each other and of the card's accordion.
     Pass :collapsible="false" for a plain labelled section: heading + badges,
     content always shown, no second chevron competing with the card's own.

     Slots: default (the body, usually an x-index-table.table), badge, actions
     (a collapsible group shows them only while open — closed, the row is just
     a label). --}}
@props([
    'heading' => null,
    'href' => null,
    'open' => false,
    'collapsible' => true,
])
@php
    // An @if-guarded slot still arrives as Blade's <!--[if BLOCK]--> markers, so
    // isset() alone would paint an empty actions area (same idiom as the cards).
    $hasActions = isset($actions) && trim(preg_replace('/<!--.*?-->/s', '', (string) $actions)) !== '';
@endphp
<div @if($collapsible) x-data="{ open: @js($open) }" @endif {{ $attributes }}>
    <div class="flex items-center justify-between gap-2 py-1 @if($collapsible) cursor-pointer select-none @endif"
        @if($collapsible) role="button" @click="open = !open" @endif
    >
        <div class="flex items-center gap-2 min-w-0">
            <flux:heading size="sm" class="mb-0 truncate text-zinc-700 dark:text-zinc-300">
                @if($href)
                    <flux:link href="{{ $href }}" wire:navigate.hover variant="ghost" :accent="false" class="hover:underline">{{ $heading }}</flux:link>
                @else
                    {{ $heading }}
                @endif
            </flux:heading>
            {{ $badge ?? '' }}
        </div>
        <div class="flex items-center gap-1 shrink-0">
            @if($hasActions && $collapsible)
                {{-- .stop so a button acts without toggling the group. --}}
                <div class="flex items-center gap-1" x-show="open" x-cloak @click.stop>{{ $actions }}</div>
            @elseif($hasActions)
                <div class="flex items-center gap-1">{{ $actions }}</div>
            @endif
            @if($collapsible)
                <flux:icon.chevron-up class="size-4 text-zinc-400 transition-transform" x-bind:class="open ? '' : 'rotate-180'" />
            @endif
        </div>
    </div>
    @if($collapsible)
        <div x-show="open" x-collapse @unless($open) x-cloak @endunless>
            {{ $slot }}
        </div>
    @else
        <div>{{ $slot }}</div>
    @endif
</div>
