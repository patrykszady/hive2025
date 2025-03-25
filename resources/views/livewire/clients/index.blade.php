<div class="max-w-4xl space-y-2">
    <flux:card>
        <div class="flex justify-between">
            <flux:heading size="lg">Client Filters</flux:heading>
            @can('create', App\Models\Client::class)
                <flux:button wire:click="$dispatchTo('users.user-create', 'newMember', { model: 'client', model_id: 'NEW' })" icon="plus">New Client</flux:button>
            @endcan
        </div>

        <flux:separator variant="subtle" />

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <flux:input wire:model.live="client_name_search" label="Client Name" icon="magnifying-glass" placeholder="Search Clients" />
        </div>
    </flux:card>

    <flux:card>
        <flux:heading size="lg">Clients</flux:heading>

        <div>
            <flux:table :paginate="$this->clients" wire:loading.class="opacity-50 text-opacity-50">
                <flux:table.columns>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column>Address</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'created_at'" :direction="$sortDirection" wire:click="sort('created_at')">Created</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->clients as $client)
                        <flux:table.row :key="$client->id">
                            <flux:table.cell variant="strong"><a wire:navigate.hover href="{{route('clients.show', $client->id)}}">{{$client->name}}</a></flux:table.cell>
                            <flux:table.cell>{{$client->one_line_address}}</flux:table.cell>
                            <flux:table.cell>{{$client->created_at->format('m/d/Y')}}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    </flux:card>

    <livewire:users.user-create />
    <livewire:clients.client-create />
</div>
