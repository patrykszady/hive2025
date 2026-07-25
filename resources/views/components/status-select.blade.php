@blaze

{{-- Inline status dropdown for table rows (single source of truth: used by the
     Projects table and the Leads table).

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
{{-- wire:key includes the value: morph preserves Alpine x-data across
     re-renders, so a server-side status change (bulk action, modal edit)
     would otherwise leave the dropdown showing the stale old value. A key
     change forces a node swap and Alpine re-initializes fresh. --}}
<div wire:key="status-select-{{ $method }}-{{ $modelId }}-{{ $value }}" x-data="{ status: @js($value) }" x-init="$watch('status', value => $wire.{{ $method }}({{ Js::from($modelId) }}, value))">
    <flux:select
        x-model="status"
        variant="listbox"
        size="sm"
        {{ $attributes->merge(['class' => '!min-w-0']) }}
    >
        @foreach($options as $option)
            <flux:select.option :value="$option['code']">
                <flux:badge size="sm" inset="top bottom" :color="$option['color']">
                    {{ $option['label'] }}
                </flux:badge>
            </flux:select.option>
        @endforeach
    </flux:select>
</div>
