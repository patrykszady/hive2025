{{-- The ONE collapse chevron for cards — details cards, index-table cards and
     grouped rows all use this, so the affordance can't drift in size or colour
     between card types (it had: a 16px hand-written SVG in x-details.card vs a
     20px flux mini icon elsewhere).

     Expects Alpine `open` in scope; .stop so it doesn't double-toggle when the
     heading row is itself clickable. --}}
@props([
    'label' => 'Toggle',
])
<button
    type="button"
    @click.stop="open = !open"
    aria-label="{{ $label }}"
    {{ $attributes->merge(['class' => 'p-1 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 cursor-pointer']) }}
>
    <flux:icon.chevron-down class="size-4 transition-transform duration-200" ::class="open && 'rotate-180'" />
</button>
