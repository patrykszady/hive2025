@props([
    'layout' => 'stacked', {{-- 'stacked' for mobile, 'inline' for desktop --}}
])

@php($fieldWrap = $layout === 'inline' ? 'flex-1 min-w-0 w-full' : 'min-w-0 w-full')

<div class="{{ $layout === 'inline' ? 'flex flex-col sm:flex-row items-end gap-4' : 'flex flex-col gap-4' }}">
    <div class="{{ $fieldWrap }}">
        <flux:input
            wire:model.live.debounce.500ms="search"
            label="Search"
            placeholder="Search vendor or project..."
            icon="magnifying-glass"
        />
    </div>

    <div class="{{ $fieldWrap }}">
        <flux:select wire:model.live="statusFilter" label="Status" variant="listbox" clearable placeholder="All statuses...">
            @foreach($this->statusOptions as $opt)
                <flux:select.option value="{{ $opt['value'] }}">
                    <flux:badge size="sm" inset="top bottom" :color="\App\Enums\LienWaiverStatus::from($opt['value'])->color()">
                        {{ $opt['label'] }}
                    </flux:badge>
                </flux:select.option>
            @endforeach
        </flux:select>
    </div>
</div>
