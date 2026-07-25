@props([
    'layout' => 'stacked', {{-- 'stacked' for mobile, 'inline' for desktop --}}
])

@php($fieldWrap = $layout === 'inline' ? 'flex-1 min-w-0 w-full' : 'min-w-0 w-full')

<div class="{{ $layout === 'inline' ? 'flex flex-col sm:flex-row items-end gap-4' : 'flex flex-col gap-4' }}">
    <div class="{{ $fieldWrap }}">
        <flux:input
            wire:model.live.debounce.400ms="project_name_search"
            label="Project"
            icon="magnifying-glass"
            placeholder="Search projects..."
        />
    </div>

    <div class="{{ $fieldWrap }}">
        <flux:select wire:model.live="client_id" label="Client" variant="listbox" searchable clearable placeholder="All Clients...">
            <x-slot name="search">
                <flux:select.search placeholder="Search..." />
            </x-slot>
            @foreach ($clients as $client)
                <flux:select.option value="{{$client->id}}">{{ $client->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="{{ $fieldWrap }}">
        <flux:select variant="listbox" label="Status" clearable placeholder="All statuses..." wire:model.live="project_status_title">
            @foreach(\App\Models\ProjectStatus::selectableStatuses() as $status)
                <flux:select.option :value="$status['code']">
                    <flux:badge size="md" inset="top bottom" :color="$status['color']">
                        {{ $status['label'] }}
                    </flux:badge>
                </flux:select.option>
            @endforeach
        </flux:select>
    </div>
</div>
