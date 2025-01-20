<div class="max-w-2xl">
    <flux:card class="space-y-2">
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

    <flux:card class="mt-4 space-y-2">
        <div>
            <flux:heading size="lg">Clients</flux:heading>
        </div>

        <div class="space-y-2">
            <flux:table :paginate="$this->clients">
                <flux:columns>
                    <flux:column>Name</flux:column>
                    <flux:column>Address</flux:column>
                    <flux:column sortable :sorted="$sortBy === 'created_at'" :direction="$sortDirection" wire:click="sort('created_at')">Created</flux:column>
                </flux:columns>

                <flux:rows>
                    @foreach ($this->clients as $client)
                        <flux:row :key="$client->id">
                            <flux:cell variant="strong"><a wire:navigate.hover href="{{route('clients.show', $client->id)}}">{{$client->name}}</a></flux:cell>
                            <flux:cell>{{$client->one_line_address}}</flux:cell>
                            <flux:cell>{{$client->created_at->format('m/d/Y')}}</flux:cell>
                        </flux:row>
                    @endforeach
                </flux:rows>
            </flux:table>
        </div>
    </flux:card>

    <livewire:users.user-create />
    <livewire:clients.client-create />
</div>
