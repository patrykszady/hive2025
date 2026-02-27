<div class="max-w-4xl space-y-2">
    <x-island-card heading="Client Filters" :separator="true">
        <div class="flex flex-col sm:flex-row gap-4">
            <div class="flex-1 min-w-0">
                <flux:input wire:model.live.debounce.500ms="client_name_search" label="Client or Address" icon="magnifying-glass" placeholder="Search by name or address" />
            </div>
        </div>
    </x-island-card>

    <livewire:clients.clients-table :client-name-search="$client_name_search" lazy />

    <livewire:users.user-create />
    <livewire:clients.client-create />
</div>
