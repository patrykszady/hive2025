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

    if ($attributes->whereStartsWith('wire:transition')->isEmpty()) {
        $attributes = $attributes->merge(['wire:transition.opacity.duration.200ms' => '']);
    }
@endphp
{{-- No enter animation: an opacity-0 start keeps a skeleton invisible until
     Alpine boots (~250ms), which reads as the card "not showing right away"
     and makes cards appear one by one. Skeletons paint instantly at full
     opacity, and loaded content replaces them with no animation — identical
     size means the swap is invisible, which is what killed the flash. --}}
<flux:card {{ $attributes->class('space-y-1 !px-5 !py-2') }} style="--flux-card-px: calc(var(--spacing) * 5);">
    @if($heading || $hasBadge || $hasActions)
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
    </x-island-card.header>
    @endif

    {{ $slot }}
</flux:card>
