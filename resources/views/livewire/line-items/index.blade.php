<div class="max-w-3xl">
    <flux:card class="space-y-2">
        <div class="flex justify-between">
            <flux:heading size="lg">Line Items</flux:heading>
            <flux:button wire:click="$dispatchTo('line-items.line-item-create', 'addItem')">Create Line Item</flux:button>
        </div>

        <flux:separator variant="subtle" class="my-2" />

        <div class="space-y-2">
            <flux:table :paginate="$this->line_items">
                <flux:table.columns>
                    <flux:table.column>
                        <flux:input wire:model.live="search" icon="magnifying-glass" placeholder="Search ..." />
                    </flux:table.column>
                    <flux:table.column>Category</flux:table.column>
                    <flux:table.column>Price</flux:table.column>
                    <flux:table.column>Unit</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach($this->line_items as $line_item)
                        <flux:table.row :key="$line_item->id">
                            <flux:table.cell
                                wire:click="$dispatchTo('line-items.line-item-create', 'editItem', { line_item: {{$line_item}}})"
                                variant="strong"
                                class="cursor-pointer"
                                >
                                {{ $line_item->name }}
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="'blue'">{{ $line_item->category }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>{{ money($line_item->cost) }}</flux:table.cell>
                            <flux:table.cell>{{ $line_item->unit_type }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    </flux:card>

    {{-- NEW LINE ITEM MODAL --}}
    <livewire:line-items.line-item-create />
</div>
