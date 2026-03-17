<div class="w-full px-4 sm:px-6 lg:max-w-xl lg:px-8 pb-5 mb-1 space-y-6">
<x-island-card heading="Vendor Match" subheading="Create automatic receipt and transaction matches per vendor. Matched expenses will be assigned a distribution automatically.">

    <flux:separator text="New Vendor Match" variant="subtle" />

    <flux:input.group>
        <flux:select wire:model.live="vendor_id" variant="listbox" searchable placeholder="New Vendor Match...">
            <x-slot name="search">
                <flux:select.search placeholder="Search..." />
            </x-slot>

            @foreach($new_vendors as $vendor)
                <flux:select.option wire:key="{{$vendor->id}}" value="{{$vendor->id}}">{{$vendor->name}}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:button variant="primary" wire:click="addVendor" icon="plus-circle">Add</flux:button>
    </flux:input.group>

    <flux:separator variant="subtle" class="mt-2" />

    <flux:table>
        <flux:table.columns>
            <flux:table.column>Vendor</flux:table.column>
            <flux:table.column>Matches</flux:table.column>
            <flux:table.column>Receipts</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->vendors as $vendor)
                <flux:table.row :key="$vendor->id">
                    <flux:table.cell
                        wire:click="$dispatchTo('receipt-accounts.receipt-account-vendor-create', 'editReceiptVendor', { vendor: {{ $vendor->id }} })"
                        variant="strong"
                        class="cursor-pointer"
                        >
                        {{ $vendor->name }}
                    </flux:table.cell>
                    <flux:table.cell>
                        @if($vendor->transactions_bulk_match_count)
                            <flux:badge size="sm" color="sky" inset="top bottom">
                                {{ $vendor->transactions_bulk_match_count }} {{ Str::plural('match', $vendor->transactions_bulk_match_count) }}
                            </flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        @if($vendor->receipts_count)
                            <flux:badge size="sm" :color="$vendor->receipt_account ? 'amber' : 'red'" inset="top bottom">
                                {{ $vendor->receipts_count }} {{ Str::plural('receipt', $vendor->receipts_count) }}
                            </flux:badge>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <livewire:receipt-accounts.receipt-account-vendor-create :distributions="$distributions"/>
</x-island-card>
</div>
