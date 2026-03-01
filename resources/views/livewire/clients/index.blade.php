<div class="max-w-4xl space-y-2">
    {{-- Mobile: accordion collapsed by default --}}
    <flux:card class="!px-5 !py-2 sm:hidden">
        <flux:accordion transition>
            <flux:accordion.item>
                <flux:accordion.heading>
                    <flux:heading size="lg">Client Filters</flux:heading>
                </flux:accordion.heading>
                <flux:accordion.content>
                    <div class="flex flex-col gap-4">
                        <div class="min-w-0">
                            <flux:input wire:model.live.debounce.500ms="client_name_search" label="Client or Address" icon="magnifying-glass" placeholder="Search by name or address" />
                        </div>
                    </div>
                </flux:accordion.content>
            </flux:accordion.item>
        </flux:accordion>
    </flux:card>

    {{-- Desktop: always expanded --}}
    <x-island-card heading="Client Filters" :separator="true" class="hidden sm:block">
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
