@props([
    'layout' => 'stacked', {{-- 'stacked' for mobile, 'inline' for desktop --}}
])

@php($fieldWrap = $layout === 'inline' ? 'flex-1 min-w-0 w-full' : 'min-w-0 w-full')

<div class="{{ $layout === 'inline' ? 'flex flex-col sm:flex-row items-end gap-4' : 'flex flex-col gap-4' }}">
    <div class="{{ $fieldWrap }}">
        <flux:input wire:model.live.debounce.400ms="search" label="Search" icon="magnifying-glass" placeholder="Name, address, email, phone..." clearable />
    </div>

    <div class="{{ $fieldWrap }}">
        <flux:select variant="listbox" label="Status" multiple clearable placeholder="Choose status..." wire:model.live="statuses">
            @foreach(\App\Models\Lead::selectableStatuses() as $statusOption)
                <flux:select.option value="{{ $statusOption['code'] }}">
                    <flux:badge size="md" inset="top bottom" :color="$statusOption['color']">{{ $statusOption['label'] }}</flux:badge>
                </flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="{{ $fieldWrap }}">
        <flux:select wire:model.live="origin" label="Origin" variant="listbox" clearable placeholder="All Origins">
            @foreach($this->origins as $originOption)
                <flux:select.option value="{{ $originOption }}">{{ $originOption }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>
</div>
