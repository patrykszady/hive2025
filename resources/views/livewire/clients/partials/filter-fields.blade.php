@props([
    'layout' => 'stacked', {{-- 'stacked' for mobile, 'inline' for desktop --}}
])

@php($fieldWrap = $layout === 'inline' ? 'flex-1 min-w-0 w-full' : 'min-w-0 w-full')

<div class="{{ $layout === 'inline' ? 'flex flex-col sm:flex-row items-end gap-4' : 'flex flex-col gap-4' }}">
    <div class="{{ $fieldWrap }}">
        <flux:input wire:model.live.debounce.500ms="client_name_search" label="Client or Address" icon="magnifying-glass" placeholder="Search by name or address" />
    </div>
</div>
