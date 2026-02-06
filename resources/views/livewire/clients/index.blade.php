<div class="max-w-4xl space-y-2">
    <x-island-card heading="Client Filters" :separator="true">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <flux:input wire:model.live.debounce.500ms="client_name_search" label="Client or Address" icon="magnifying-glass" placeholder="Search by name or address" />
        </div>
    </x-island-card>

    <livewire:clients.clients-table :client-name-search="$client_name_search" lazy />

    <livewire:users.user-create />
    <livewire:clients.client-create />
</div>
