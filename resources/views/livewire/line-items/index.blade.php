<div class="max-w-3xl">
    <x-island-card heading="Line Items" :separator="true">
        <x-slot:actions>
            <flux:button wire:click="$dispatchTo('line-items.line-item-create', 'addItem')">Create Line Item</flux:button>
        </x-slot:actions>

        <flux:tab.group>
            <flux:tabs wire:model.live="tab">
                <flux:tab name="items">Line Items</flux:tab>
                <flux:tab name="allowances">Allowances</flux:tab>
            </flux:tabs>

            <div class="mt-4">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search ..." />
            </div>

            <flux:tab.panel name="items">
                <flux:table :paginate="$this->line_items->hasPages() ? $this->line_items : null">
                    <flux:table.columns>
                        <flux:table.column>Name</flux:table.column>
                        <flux:table.column>Category</flux:table.column>
                        <flux:table.column>Price</flux:table.column>
                        <flux:table.column>Unit</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse($this->line_items as $line_item)
                            <flux:table.row wire:key="line-item-{{ $line_item->id }}">
                                <flux:table.cell
                                    wire:click="$dispatchTo('line-items.line-item-create', 'editItem', { line_item: {{ $line_item }} })"
                                    variant="strong"
                                    class="cursor-pointer"
                                >
                                    {{ $line_item->name }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" color="blue">{{ $line_item->category }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>{{ money($line_item->cost) }}</flux:table.cell>
                                <flux:table.cell>{{ $line_item->unit_type }}</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="4">
                                    <flux:text class="text-zinc-500">No line items found.</flux:text>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:tab.panel>

            <flux:tab.panel name="allowances">
                <flux:table :paginate="$this->allowances->hasPages() ? $this->allowances : null">
                    <flux:table.columns>
                        <flux:table.column>Line Item</flux:table.column>
                        <flux:table.column>Allowance</flux:table.column>
                        <flux:table.column>Per Unit</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse($this->allowances as $allowance)
                            <flux:table.row wire:key="allowance-{{ $allowance['line_item_id'] }}-{{ \Illuminate\Support\Str::slug($allowance['description']) }}">
                                <flux:table.cell variant="strong">
                                    {{ $allowance['line_item_name'] ?? '—' }}
                                </flux:table.cell>
                                <flux:table.cell>{{ $allowance['description'] }}</flux:table.cell>
                                <flux:table.cell>
                                    @if($allowance['unit_amount'] !== null)
                                        {{ money($allowance['unit_amount']) }}
                                        @if($allowance['unit_type'] && $allowance['unit_type'] !== 'no_unit')
                                            <flux:text class="text-zinc-500" inline>/ {{ $allowance['unit_type'] }}</flux:text>
                                        @endif
                                    @else
                                        <flux:text class="text-zinc-500">—</flux:text>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="3">
                                    <flux:text class="text-zinc-500">No allowances found.</flux:text>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:tab.panel>
        </flux:tab.group>
    </x-island-card>

    {{-- NEW LINE ITEM MODAL --}}
    <livewire:line-items.line-item-create />
</div>
