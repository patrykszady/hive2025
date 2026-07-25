@blaze

{{-- Tooltip that only appears when the wrapped content is actually truncated.
     ui-tooltip supports a reactive `disabled` attribute (Flux Disableable), so
     we measure the inner .truncate element and disable the tooltip whenever
     the text fully fits — no redundant tooltips repeating visible text.
     Re-checks on resize. Slot must contain an element with class="truncate". --}}
@props([
    'content' => '',
])
{{-- :content (bound) — a plain content="{{ ... }}" attribute double-escapes:
     once at this tag, once inside Flux's tooltip stub. --}}
<flux:tooltip
    :content="$content"
    position="top"
    {{ $attributes->merge(['class' => 'block min-w-0']) }}
    x-data="{ clipped: false }"
    x-init="const el = $el.querySelector('.truncate'); if (el) { const check = () => { clipped = el.scrollWidth > el.clientWidth }; check(); new ResizeObserver(check).observe(el) }"
    x-bind:disabled="!clipped"
>
    {{ $slot }}
</flux:tooltip>
