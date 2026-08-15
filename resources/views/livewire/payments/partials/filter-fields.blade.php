@php
    $containerClass = $layout === 'inline'
        ? 'flex flex-row items-end gap-4'
        : 'flex flex-col gap-4';
@endphp

<div class="{{ $containerClass }}">
    <div class="flex-1 min-w-0">
        <flux:field>
            <flux:label>Amount</flux:label>
            <flux:input
                wire:model.live.debounce.500ms="amount"
                icon="magnifying-glass"
                placeholder="123.45"
            />
        </flux:field>
    </div>

    <div class="flex-1 min-w-0">
        <flux:field>
            <flux:label>Project</flux:label>
            <flux:select wire:model.live="project_filter" variant="listbox" searchable clearable placeholder="All Projects...">
                <x-slot name="search">
                    <flux:select.search placeholder="Search..." />
                </x-slot>
                @foreach ($projects as $filterProject)
                    <flux:select.option value="{{ $filterProject->id }}">{{ $filterProject->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </flux:field>
    </div>

    <div class="flex-1 min-w-0">
        <flux:field>
            <flux:label>Client</flux:label>
            <flux:select wire:model.live="client_filter" variant="listbox" searchable clearable placeholder="All Clients...">
                <x-slot name="search">
                    <flux:select.search placeholder="Search..." />
                </x-slot>
                @foreach ($clients as $client)
                    <flux:select.option value="{{ $client['id'] }}">{{ $client['name'] }}</flux:select.option>
                @endforeach
            </flux:select>
        </flux:field>
    </div>

    <div class="flex-1 min-w-0">
        <flux:field>
            <flux:label>Status</flux:label>
            <flux:select wire:model.live="status_filter" variant="listbox" clearable placeholder="All statuses...">
                <flux:select.option value="complete">
                    <flux:badge size="md" inset="top bottom" color="green">Complete</flux:badge>
                </flux:select.option>
                <flux:select.option value="missing">
                    <flux:badge size="md" inset="top bottom" color="red">Missing</flux:badge>
                </flux:select.option>
            </flux:select>
        </flux:field>
    </div>
</div>
