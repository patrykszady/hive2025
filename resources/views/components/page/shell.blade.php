{{-- Single source of truth for SHOW-PAGE layout: width, gutter, breadcrumb row,
     column grid, and an offstage region for modal hosts. Cards stay owned by
     x-island-card / x-details.card / x-index-table — this owns only the page
     around them, so every show page has the same margins and rhythm. --}}
@props([
    {{-- lg track count, preserving each page's existing ratio exactly:
         1 = single column, 2 = 50/50, 4 = 50/50 + full-width rows,
         5 = 40/60. --}}
    'cols' => 2,
    {{-- Enumerated so every class string is a literal Tailwind can scan. --}}
    'width' => 'default',
    'gutter' => true,
    'breadcrumbs' => [],
])
@php
    $widthClass = match ($width) {
        'flat' => 'max-w-5xl',
        'wide' => 'lg:max-w-7xl',
        'narrow' => 'max-w-4xl',
        'full' => '',
        default => 'max-w-xl lg:max-w-5xl',
    };
@endphp

<div {{ $attributes->class(['w-full', $widthClass, 'sm:px-6' => $gutter]) }}>
    <x-page.breadcrumbs :items="$breadcrumbs" />

    {{-- Blade emits <!--[if BLOCK]--> markers for @if-wrapped slots, so isset()
         alone would paint an empty 16px box (same idiom as x-index-table). --}}
    @if(isset($header) && trim(preg_replace('/<!--.*?-->/s', '', (string) $header)) !== '')
        <div class="mb-4">{{ $header }}</div>
    @endif

    {{-- Literal class strings per track count: Tailwind v4 scans source text,
         so a computed `lg:grid-cols-{{ $n }}` would never be generated. One
         column on mobile; the page's own ratio from lg up. --}}
    @php
        $gridClass = match ((int) $cols) {
            1 => 'grid grid-cols-1 gap-4 lg:items-start',
            4 => 'grid grid-cols-1 gap-4 lg:grid-cols-4 lg:items-start',
            5 => 'grid grid-cols-1 gap-4 lg:grid-cols-5 lg:items-start',
            default => 'grid grid-cols-1 gap-4 lg:grid-cols-2 lg:items-start',
        };
    @endphp
    <div class="{{ $gridClass }}">
        {{ $slot }}
    </div>

    {{-- Modal hosts and other zero-height Livewire components live outside the
         grid so they can never contribute a phantom gap. --}}
    {{ $offstage ?? '' }}
</div>
