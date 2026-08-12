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
{{-- No Alpine state: morph/clone contexts (table rows, x-for) can re-init
     the binding without its x-data ancestor, throwing "clipped is not
     defined" on every render. Toggling the attribute imperatively keeps the
     whole behavior on one element with nothing to inherit. --}}
<flux:tooltip
    :content="$content"
    position="top"
    disabled
    {{ $attributes->merge(['class' => 'block min-w-0']) }}
    x-init="const el = $el.querySelector('.truncate'); if (el) { const check = () => { $el.toggleAttribute('disabled', !(el.scrollWidth > el.clientWidth)) }; check(); new ResizeObserver(check).observe(el) }"
>
    {{ $slot }}
</flux:tooltip>
