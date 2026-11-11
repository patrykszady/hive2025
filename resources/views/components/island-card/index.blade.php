@props([
    'heading' => null,
    'href' => null,
    // Internal heading links navigate in place; :navigate="false" opens a tab.
    'navigate' => true,
    'subheading' => null,
    'separator' => false,
    // Collapsible cards: the whole heading row toggles Alpine's `open`, not
    // just the chevron (x-island-card.header owns the behavior; this wrapper
    // only needs to pass it down). Callers supply x-data="{ open: false }".
    'clickable' => false,
    {{-- Fade in when this element is inserted. TRUE for skeletons (the card
         appearing for the first time reads as smooth), FALSE for loaded
         content: the swap replaces the skeleton element, so animating it too
         fires a SECOND fade over an identical-looking card — that double fade
         is what reads as a flash. --}}
    'enter' => false,
])

{{-- wire:transition — cards that appear/disappear from server renders fade
     smoothly. Skipped when the caller passes its own wire:transition.
     (Directives don't compile inside component tags, so use the attribute bag.) --}}
@php
    // An @if-guarded slot still arrives as Blade's <!--[if BLOCK]--> markers, so
    // isset() alone would paint a header for a card that has none.
    $hasBadge = isset($badge) && trim(preg_replace('/<!--.*?-->/s', '', (string) $badge)) !== '';
    $hasActions = isset($actions) && trim(preg_replace('/<!--.*?-->/s', '', (string) $actions)) !== '';
    $hasSubheadingActions = isset($subheading_actions) && trim(preg_replace('/<!--.*?-->/s', '', (string) $subheading_actions)) !== '';

    if ($attributes->whereStartsWith('wire:transition')->isEmpty()) {
        $attributes = $attributes->merge(['wire:transition.opacity.duration.200ms' => '']);
    }
@endphp
{{-- No enter animation: an opacity-0 start keeps a skeleton invisible until
     Alpine boots (~250ms), which reads as the card "not showing right away"
     and makes cards appear one by one. Skeletons paint instantly at full
     opacity, and loaded content replaces them with no animation — identical
     size means the swap is invisible, which is what killed the flash. --}}
{{-- Raw PHP, not @if: a Livewire component rooted on this card would
     otherwise start with Livewire's <!--[if BLOCK]> marker instead of the
     card element, and the morph then nests the loaded card inside its own
     placeholder (the Projects table skeleton stayed under the loaded table,
     2026-11-06). --}}
<?php if (\App\Support\Ui\CardsV2::active()): ?>
{{-- Cards v2 (config/ui.php): Flux 2.21's card parts. `separator` becomes
     the card's own divider between header and body; --flux-card-px follows
     Flux's padding so hive's table/list bleed rules still line up. --}}
<flux:card size="sm" :body="$separator ? 'divided' : 'seamless'" divider="inset" {{ $attributes }}>
    @if($heading || $hasBadge || $hasActions || $hasSubheadingActions)
    <x-island-card.header
        :heading="$heading"
        :href="$href"
        :navigate="$navigate"
        :subheading="$subheading"
        :clickable="$clickable"
    >
        @if($hasBadge)
            <x-slot:badge>{{ $badge }}</x-slot:badge>
        @endif
        @if($hasActions)
            <x-slot:actions>{{ $actions }}</x-slot:actions>
        @endif
        @if($hasSubheadingActions)
            <x-slot:subheading_actions>{{ $subheading_actions }}</x-slot:subheading_actions>
        @endif
    </x-island-card.header>
    @endif

    <flux:card.body>
        {{ $slot }}
    </flux:card.body>

    @if(isset($footer) && trim(preg_replace('/<!--.*?-->/s', '', (string) $footer)) !== '')
        <flux:card.footer>{{ $footer }}</flux:card.footer>
    @endif
</flux:card>
<?php else: ?>
<flux:card {{ $attributes->class('space-y-1 !px-5 !py-2') }} style="--flux-card-px: calc(var(--spacing) * 5);">
    @if($heading || $hasBadge || $hasActions || $hasSubheadingActions)
    <x-island-card.header
        :heading="$heading"
        :href="$href"
        :navigate="$navigate"
        :subheading="$subheading"
        :separator="$separator"
        :clickable="$clickable"
    >
        @if($hasBadge)
            <x-slot:badge>{{ $badge }}</x-slot:badge>
        @endif
        @if($hasActions)
            <x-slot:actions>{{ $actions }}</x-slot:actions>
        @endif
        @if($hasSubheadingActions)
            <x-slot:subheading_actions>{{ $subheading_actions }}</x-slot:subheading_actions>
        @endif
    </x-island-card.header>
    @endif

    {{ $slot }}
</flux:card>
<?php endif; ?>
