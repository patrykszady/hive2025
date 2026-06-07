<div class="max-w-3xl">
    <x-island-card heading="Line Items" :separator="true">
        <x-slot:actions>
            <flux:button wire:click="$dispatchTo('line-items.line-item-create', 'addItem')">Create Line Item</flux:button>
        </x-slot:actions>

        <div class="mt-4">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search ..." />
        </div>

        <flux:table :paginate="$this->line_items->hasPages() ? $this->line_items : null">
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>Category</flux:table.column>
                <flux:table.column>Price</flux:table.column>
                <flux:table.column>Unit</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse($this->line_items as $line_item)
                    <flux:table.row wire:key="line-item-{{ $line_item->id }}" data-line-item-row="{{ $line_item->id }}">
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

                    @php($inlineAllowances = $this->inlineAllowances->get($line_item->id, collect()))

                    @foreach($inlineAllowances as $allowance)
                        <flux:table.row wire:key="line-item-{{ $line_item->id }}-allowance-{{ \Illuminate\Support\Str::slug($allowance['description']) }}-{{ $loop->index }}" class="bg-gray-50 dark:bg-gray-800/50 [&_td]:!py-2" data-line-item-allowance-parent="{{ $line_item->id }}">
                            <flux:table.cell class="!pl-10">
                                <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                                    <span class="inline-block size-1.5 rounded-full bg-gray-300 dark:bg-gray-600"></span>
                                    <span class="italic">Allowance</span>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell class="text-sm text-gray-600 dark:text-gray-400 !whitespace-normal break-words">
                                {{ $allowance['description'] }}
                            </flux:table.cell>
                            <flux:table.cell class="text-sm text-gray-600 dark:text-gray-400 tabular-nums">
                                @if($allowance['amount'] !== null)
                                    {{ money($allowance['amount']) }}
                                @else
                                    <flux:text class="text-zinc-500">—</flux:text>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-sm text-gray-600 dark:text-gray-400">
                                @if($allowance['pricing_mode'] === 'per_unit' && $allowance['unit_amount'] !== null)
                                    {{ money($allowance['unit_amount']) }}
                                    @if($allowance['unit_type'] && $allowance['unit_type'] !== 'no_unit')
                                        <flux:text class="text-zinc-500" inline>/ {{ $allowance['unit_type'] }}</flux:text>
                                    @endif
                                @elseif($allowance['pricing_mode'] === 'lump_sum')
                                    <flux:text class="text-zinc-500">Lump sum</flux:text>
                                @else
                                    <flux:text class="text-zinc-500">—</flux:text>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4">
                            <flux:text class="text-zinc-500">No line items found.</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </x-island-card>

    {{-- NEW LINE ITEM MODAL --}}
    <livewire:line-items.line-item-create />
</div>
