<x-island-card heading="Clients">
    <x-slot:actions>
        @can('create', App\Models\Client::class)
            <flux:button size="sm" wire:click="$dispatchTo('users.user-create', 'newMember', { model: 'client', model_id: 'NEW' })" icon="plus">New Client</flux:button>
        @endcan
    </x-slot:actions>

    <div>
        <flux:table :paginate="$this->clients->hasPages() ? $this->clients : null" wire:loading.class="opacity-50 text-opacity-50">
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>Address</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'created_at'" :direction="$sortDirection" wire:click="sort('created_at')">Created</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->clients as $client)
                    <flux:table.row :key="$client->id">
                        <flux:table.cell variant="strong"><a wire:navigate.hover href="{{ route('clients.show', $client->id) }}">{{ $client->name }}</a></flux:table.cell>
                        <flux:table.cell>{{ $client->one_line_address }}</flux:table.cell>
                        <flux:table.cell>{{ $client->created_at->format('m/d/Y') }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</x-island-card>
