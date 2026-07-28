{{-- A column of cards inside <x-page.shell>. Spacing comes from the shared
     .page-col rule, so no page sets its own space-y. --}}
@props([
    {{-- lg tracks this column occupies: with cols=4 use 2; with cols=5 use 2 or
         3; with cols=2 use 1. Pass span == cols for a full-width row. --}}
    'span' => 1,
    {{-- display:contents below lg — only projects/show, whose order-* sequence
         interleaves cards from both columns into one mobile stack. --}}
    'interleave' => false,
])

@php
    // Literal spans (Tailwind can't generate lg:col-span-{{ $n }} from a value).
    $spanClass = match ((int) $span) {
        2 => 'lg:col-span-2',
        3 => 'lg:col-span-3',
        4 => 'lg:col-span-4',
        5 => 'lg:col-span-5',
        default => 'lg:col-span-1',
    };
    // interleave: below lg the wrapper disappears so its cards join one stack
    // and the page's order-* sequence can interleave both columns.
    $layoutClass = $interleave
        ? 'contents lg:flex lg:flex-col lg:gap-4'
        : 'space-y-4 lg:space-y-0 lg:flex lg:flex-col lg:gap-4';
@endphp

{{-- Hide wrappers whose component rendered no card (a conditional card, or a
     modal host): otherwise the empty div still takes a gap. Tailwind arbitrary
     variant, the same idiom projects/show used before this component. --}}
<div {{ $attributes->class([$spanClass, $layoutClass, '[&>*:not(:has([data-flux-card]))]:hidden']) }}>
    {{ $slot }}
</div>
