<div>
    <flux:card class="!px-5 !py-2">
        <div class="flex items-center justify-between">
            <flux:heading size="lg">Materials</flux:heading>
            @can('update', $project)
                <flux:button size="sm" icon="plus" wire:click="openUploadModal">
                    Upload
                </flux:button>
            @endcan
        </div>

        <div class="mt-3">
            @if(count($this->materialExpenses))
                @php($itemsByExpense = $this->materialItemsByExpense)
                @php($availabilityByExpense = $this->materialAvailabilityByExpense)

                <div class="-mt-2 space-y-0">
                    @foreach($this->materialExpenses as $expense)
                        @php($itemsForExpense = $itemsByExpense[$expense->id] ?? [])
                        @php($itemCount = count($itemsForExpense))
                        @php($availabilityColor = $availabilityByExpense[$expense->id] ?? 'green')

                        <flux:accordion.item transition wire:key="expense-{{ $expense->id }}">
                            <flux:accordion.heading>
                                <span class="flex items-center gap-2">
                                    <flux:icon.document-text variant="mini" class="text-zinc-400" />
                                    <span class="min-w-0 truncate">
                                        {{ $expense->vendor?->business_name ?? 'Unknown Vendor' }}
                                        @if($expense->invoice)
                                            / #{{ $expense->invoice }}
                                        @endif
                                    </span>
                                    @if($itemCount)
                                        <flux:badge size="sm" :color="$availabilityColor" inset="top bottom">
                                            {{ $itemCount }} items
                                        </flux:badge>
                                    @endif
                                    <a
                                        wire:navigate.hover
                                        href="{{ route('expenses.show', $expense->id) }}"
                                        class="shrink-0 text-zinc-400 transition-colors hover:text-indigo-600 dark:hover:text-indigo-400"
                                        @click.stop
                                        title="View expense"
                                    >
                                        <flux:icon.arrow-top-right-on-square variant="micro" />
                                    </a>

                                </span>
                            </flux:accordion.heading>

                            <flux:accordion.content>
                                @if($itemCount)
                                    <div class="grid grid-cols-1 gap-2">
                                        @foreach($itemsForExpense as $item)
                                            <div
                                                wire:click="selectItem({{ $item['_expense_id'] }}, {{ $item['_item_index'] }})"
                                                class="flex cursor-pointer items-center gap-3 rounded-lg border border-zinc-200 p-2 transition hover:border-zinc-400 dark:border-zinc-700 dark:hover:border-zinc-500"
                                                wire:key="item-{{ $item['_expense_id'] }}-{{ $item['_item_index'] }}"
                                            >
                                                <div class="size-12 shrink-0 overflow-hidden rounded bg-zinc-100 dark:bg-zinc-800">
                                                    @if(!empty($item['image_url']))
                                                        <img
                                                            src="{{ $item['image_url'] }}"
                                                            alt="{{ $item['Description'] ?? '' }}"
                                                            class="size-full object-cover"
                                                            referrerpolicy="no-referrer"
                                                        />
                                                    @else
                                                        <div class="flex size-full items-center justify-center">
                                                            <flux:icon.photo variant="mini" class="text-zinc-300 dark:text-zinc-600" />
                                                        </div>
                                                    @endif
                                                </div>

                                                <div class="min-w-0 flex-1">
                                                    <div class="flex items-center justify-between gap-2">
                                                        <flux:text class="truncate font-medium text-sm">{{ $item['Description'] ?? 'Unknown Item' }}</flux:text>
                                                    </div>

                                                    <div class="flex items-center gap-1.5 text-xs text-zinc-400">
                                                        @if(!empty($item['VendorCode'] ?? $item['ProductCode'] ?? null))
                                                            <span class="shrink-0">{{ $item['VendorCode'] ?? $item['ProductCode'] }}</span>
                                                        @endif
                                                        @if(!empty($item['Quantity']))
                                                            @php($unitType = strtoupper($item['Unit'] ?? 'PC'))
                                                            <span class="shrink-0">| Qty: {{ $item['Quantity'] }} {{ $unitType }}</span>
                                                        @endif
                                                    </div>

                                                    <div class="mt-0.5 flex items-center gap-2 text-xs">
                                                        @if(!empty($item['Area']))
                                                            <span class="min-w-0 truncate italic text-zinc-500 dark:text-zinc-400">
                                                                {{ is_array($item['Area']) ? implode(' / ', $item['Area']) : $item['Area'] }}
                                                            </span>
                                                        @endif

                                                        <span class="ml-auto flex shrink-0 items-center gap-1.5">
                                                            @if(!empty($item['ETA']))
                                                                @php($cardDate = \Carbon\Carbon::parse($item['ETA'])->startOfDay())
                                                                @php($todayDate = now(auth()->user()?->vendor?->timezone ?? config('app.timezone'))->startOfDay())
                                                                <span class="{{ $cardDate->gt($todayDate) ? 'text-red-500 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">{{ $cardDate->format('M j') }}</span>
                                                            @endif
                                                            @if(!empty($item['Status']))
                                                                @php($normalizedStatus = $this->normalizeStatus($item['Status']))
                                                                @if($normalizedStatus === 'back order' && !empty($item['ETA']) && !\Carbon\Carbon::parse($item['ETA'])->startOfDay()->gt(now(auth()->user()?->vendor?->timezone ?? config('app.timezone'))->startOfDay()))
                                                                    @php($normalizedStatus = 'available')
                                                                @endif
                                                                @php($statusColor = match($normalizedStatus) {
                                                                    'back order' => 'red',
                                                                    'available', 'received', 'shipped' => 'green',
                                                                    'open', 'partial' => 'amber',
                                                                    'cancelled' => 'zinc',
                                                                    default => 'zinc',
                                                                })
                                                                <flux:badge size="sm" :color="$statusColor" inset="top bottom">{{ ucfirst($normalizedStatus) }}</flux:badge>
                                                            @endif
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <flux:text class="text-sm text-zinc-400">No line items found for this order.</flux:text>
                                @endif
                            </flux:accordion.content>
                        </flux:accordion.item>
                    @endforeach
                </div>
            @else
                <flux:text class="py-2 text-zinc-400">No material orders yet.</flux:text>
            @endif
        </div>
    </flux:card>

    {{-- Item Detail Modal --}}
    <flux:modal wire:model.self="showItemModal" class="md:min-w-lg">
        <x-expenses.item-detail-modal-content :item="$selectedItem" />
    </flux:modal>

    {{-- Upload Modal --}}
    <flux:modal wire:model.self="showUploadModal" class="min-w-[22rem]">
        <form wire:submit="uploadReceipt">
            <div class="space-y-6">
                <flux:heading size="lg">Upload Material Order</flux:heading>

                <flux:text class="text-zinc-500">
                    Upload a material order receipt (PDF, JPG, PNG). It will be OCR processed and saved as an expense for this project.
                </flux:text>

                <flux:input
                    wire:model="file"
                    type="file"
                    label="Receipt"
                />
                <flux:error name="file" />

                <flux:select wire:model="belongs_to_vendor_id" variant="listbox" placeholder="Belongs to vendor..." searchable label="Belongs To Vendor">
                    @foreach($this->vendors as $vendor)
                        <flux:select.option value="{{ $vendor->id }}">{{ $vendor->business_name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="file, uploadReceipt">
                        <span wire:loading.remove wire:target="uploadReceipt">Process</span>
                        <span wire:loading wire:target="uploadReceipt">Processing...</span>
                    </flux:button>
                </div>
            </div>
        </form>
    </flux:modal>
</div>
