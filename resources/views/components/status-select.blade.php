@blaze

{{-- Inline status control for table rows (single source of truth: used by the
     Projects table and the Leads table).

     Flux-native: the status BADGE itself is the trigger of a flux:dropdown —
     no select box around it. The menu lists each status as its badge.

     - value:    current status (int code or string title)
     - options:  [['code' => ..., 'label' => ..., 'color' => ...], ...]
     - method:   Livewire method called as $wire.method(modelId, newValue)
     - modelId:  id passed as the first argument --}}
@props([
    'value',
    'options',
    'method',
    'modelId',
])
@php
    $current = collect($options)->firstWhere('code', $value);
@endphp
{{-- wire:key includes the value so a server-side status change (bulk action,
     modal edit) swaps the node instead of leaving a stale trigger. --}}
<div wire:key="status-select-{{ $method }}-{{ $modelId }}-{{ $value }}" {{ $attributes }}>
    <flux:dropdown position="bottom" align="start">
        <flux:badge
            as="button"
            size="sm"
            :color="$current['color'] ?? 'zinc'"
            icon-trailing="chevron-down"
            class="cursor-pointer"
        >
            {{ $current['label'] ?? 'Set status' }}
        </flux:badge>

        <flux:menu>
            @foreach($options as $option)
                {{-- :disabled bound, not @disabled — directives don't compile
                     inside component tags. --}}
                <flux:menu.item
                    wire:click="{{ $method }}({{ Js::from($modelId) }}, {{ Js::from($option['code']) }})"
                    :disabled="$option['disabled'] ?? false"
                >
                    <flux:badge size="sm" inset="top bottom" :color="$option['color']">
                        {{ $option['label'] }}
                    </flux:badge>
                </flux:menu.item>
            @endforeach
        </flux:menu>
    </flux:dropdown>
</div>
