<div class="max-w-3xl">
    <flux:card class="space-y-2">
        <div class="flex justify-between">
            <flux:heading size="lg">Line Items</flux:heading>
            <flux:button wire:click="$dispatchTo('line-items.line-item-create', 'addItem')">Create Line Item</flux:button>
        </div>

        <flux:separator variant="subtle" class="my-2" />

        <div class="space-y-2">
            <flux:table :paginate="$this->line_items">
                <flux:columns>
                    <flux:column>
                        <flux:input wire:model.live="search" icon="magnifying-glass" placeholder="Search ..." />
                    </flux:column>
                    <flux:column>Category</flux:column>
                    <flux:column>Price</flux:column>
                    <flux:column>Unit</flux:column>
                </flux:columns>

                <flux:rows>
                    @foreach($this->line_items as $line_item)
                        <flux:row :key="$line_item->id">
                            <flux:cell
                                wire:click="$dispatchTo('line-items.line-item-create', 'editItem', { line_item: {{$line_item}}})"
                                variant="strong"
                                class="cursor-pointer"
                                >
                                {{ $line_item->name }}
                            </flux:cell>
                            <flux:cell>
                                <flux:badge size="sm" :color="'blue'">{{ $line_item->category }}</flux:badge>
                            </flux:cell>
                            <flux:cell>{{ money($line_item->cost) }}</flux:cell>
                            <flux:cell>{{ $line_item->unit_type }}</flux:cell>
                        </flux:row>
                    @endforeach
                </flux:rows>
            </flux:table>
        </div>
    </flux:card>

    {{-- NEW LINE ITEM MODAL --}}
    <livewire:line-items.line-item-create />
</div>
