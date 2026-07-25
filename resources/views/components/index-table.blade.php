@blaze

{{-- Index-page main-table card — single source of truth for every /index page
     (leads, expenses, checks, payments, vendors, clients, projects, ...).

     Provides: island card shell, flush bottom (last row at the card edge),
     horizontal scroll on small screens (pair the inner flux:table with the
     `index-table` CSS class), and the standard pagination footer.

     Slots / props:
     - default slot — the flux:table (give it class="index-table")
     - heading, subheading — island-card header (no separator: index cards
       never draw a line under the header — chrome is fixed, content varies)
     - badge   — header badge area (e.g. bulk-select controls)
     - actions — header buttons
     - toolbar — controls above the table (e.g. a search input); padded to line
                 up with the table's own text edges
     - before  — content that must precede the flush wrapper (modal hosts:
                 anything after it re-opens the bottom gap via space-y margins)
     - paginator — LengthAwarePaginator; footer renders only when it has pages
     - attributes (incl. x-data) fall through to the card --}}
@props([
    'heading' => null,
    'subheading' => null,
    'paginator' => null,
    // Set by x-index-table.placeholder: gives the skeleton's wrapper a
    // different wire:key so Livewire's morph REPLACES it with the loaded
    // wrapper (rather than patching it in place) — that insertion is what
    // triggers the .index-table-enter fade.
    'skeleton' => false,
])
@php
    // Strip HTML comments first: Blade/Livewire emit <!--[if BLOCK]--> markers
    // around @if blocks, so a suppressed table still leaves a "non-empty" slot.
    $hasTable = trim(preg_replace('/<!--.*?-->/s', '', (string) $slot)) !== '';
@endphp
{{-- Header-only card (no rows): cancel the card's space-y rhythm so a
     zero-height child (a modal host in the `before` slot) can't add a stray
     gap — the card then measures exactly like a collapsed accordion card. --}}
<x-island-card :heading="$heading" :subheading="$subheading" {{ $attributes->merge(['class' => 'overflow-hidden'.($hasTable ? '' : ' !space-y-0')]) }}>
    @isset($badge)
        <x-slot:badge>{{ $badge }}</x-slot:badge>
    @endisset
    @if(isset($actions) && trim((string) $actions) !== '')
        <x-slot:actions>{{ $actions }}</x-slot:actions>
    @endif

    {{ $before ?? '' }}

    @if(isset($toolbar) && trim((string) $toolbar) !== '')
        <div class="index-table-toolbar">{{ $toolbar }}</div>
    @endif

    @if($hasTable)
        {{-- Livewire-native fade for the skeleton→content swap. The differing
             wire:keys make morph treat the loaded wrapper as a NEW element (so
             the enter transition runs once, on insertion) — later sorts/filters
             patch the same element and never re-animate. Opacity only, and the
             browser paints only the viewport during the fade, so offscreen rows
             of long tables cost nothing extra. --}}
        <div class="space-y-4" wire:key="{{ $skeleton ? 'index-table-skeleton' : 'index-table-content' }}"
            wire:transition.opacity.duration.150ms>
            <div class="card-flush-bottom">
                {{ $slot }}

                @if($paginator?->hasPages())
                    <div class="index-table-footer">
                        <flux:pagination :paginator="$paginator" />
                    </div>
                @endif
            </div>
        </div>
    @endif
</x-island-card>
