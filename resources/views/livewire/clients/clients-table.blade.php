<x-index-table heading="Clients" :paginator="$this->clients">
    <x-slot:actions>
        @include('livewire.clients.partials.table-actions')
    </x-slot:actions>

    <flux:table wire:loading.class="opacity-50 text-opacity-50" class="index-table [:where(&)]:p-0 [:where(&)]:space-y-0">
        <flux:table.columns>
            <flux:table.column class="w-[35%] min-w-0">Name</flux:table.column>
            <flux:table.column class="w-[45%] min-w-0">Address</flux:table.column>
            <flux:table.column class="w-[20%]" sortable :sorted="$sortBy === 'created_at'" :direction="$sortDirection" wire:click="sort('created_at')">Created</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->clients as $client)
                <flux:table.row :key="$client->id">
                    <flux:table.cell variant="strong" class="w-[35%] min-w-0">
                        <x-table-link :href="route('clients.show', $client->id)" :label="$client->name" />
                    </flux:table.cell>
                    <flux:table.cell class="w-[45%] min-w-0">
                        <x-truncate-tooltip :content="$client->one_line_address">
                            <div class="truncate">{{ $client->one_line_address }}</div>
                        </x-truncate-tooltip>
                    </flux:table.cell>
                    <flux:table.cell class="w-[20%] whitespace-nowrap">{{ $client->created_at->format('m/d/Y') }}</flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</x-index-table>
