@blaze

{{-- Truncating link inside a table cell — single source of truth for row
     links (expenses Vendor, leads Client, ...). Plain text, no hover
     color/weight tricks: the row hover background is the affordance.
     Tooltip (full value) appears only when the text is actually truncated.
     Renders static text when href is null. --}}
@props([
    'href' => null,
    'label' => '',
])
<x-truncate-tooltip :content="$label">
    @if($href)
        <a href="{{ $href }}" wire:navigate.hover {{ $attributes->merge(['class' => 'block min-w-0']) }}>
            <div class="truncate whitespace-nowrap overflow-hidden text-ellipsis">{{ $label }}</div>
        </a>
    @else
        <div {{ $attributes->merge(['class' => 'block min-w-0']) }}>
            <div class="truncate whitespace-nowrap overflow-hidden text-ellipsis">{{ $label }}</div>
        </div>
    @endif
</x-truncate-tooltip>
