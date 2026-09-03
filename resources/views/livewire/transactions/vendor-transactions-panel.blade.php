<div class="max-w-5xl mt-12 space-y-6">
    <x-island-card heading="New Vendor Transaction">
        <form wire:submit="createVendorTransaction" class="space-y-4">
            <div class="grid gap-4 lg:grid-cols-3 items-end">
                <flux:select
                    wire:model="new_vendor_transaction.vendor_id"
                    label="Vendor"
                    variant="listbox"
                    searchable
                    placeholder="Select vendor"
                >
                    <flux:select.option value="">None</flux:select.option>
                    @foreach($vendors as $vendor)
                        <flux:select.option :value="$vendor->id">{{ $vendor->business_name }}{{ $vendor->city ? ' — '.$vendor->city.($vendor->state ? ', '.$vendor->state : '') : '' }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select
                    wire:model="new_vendor_transaction.deposit_check"
                    label="Deposit/Check"
                    variant="listbox"
                >
                    @foreach($depositCheckOptions as $value => $label)
                        <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select
                    wire:model="new_vendor_transaction.amount_sign"
                    label="Amount Sign"
                    variant="listbox"
                    placeholder="Any"
                >
                    <flux:select.option value="">Any</flux:select.option>
                    <flux:select.option value="1">+$ out</flux:select.option>
                    <flux:select.option value="2">-$ in</flux:select.option>
                </flux:select>

                <flux:select
                    wire:model="new_vendor_transaction.plaid_inst_id"
                    label="Bank"
                    variant="listbox"
                    searchable
                    placeholder="No bank"
                >
                    <flux:select.option value="">None</flux:select.option>
                    @foreach($banks as $bank)
                        <flux:select.option :value="$bank->plaid_ins_id">{{ $bank->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input
                    wire:model="new_vendor_transaction.desc"
                    label="Description"
                    placeholder="Transfer description"
                    class="lg:col-span-1"
                />

                <flux:input
                    wire:model="new_vendor_transaction.options"
                    label="Options"
                    placeholder="regex"
                />
            </div>

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary">Add Vendor Transaction</flux:button>
            </div>
        </form>
    </x-island-card>

    <x-island-card heading="Vendor Transactions">
        <div class="card-flush-bottom overflow-x-hidden">
            <flux:table class="table-fixed w-full">
                <flux:table.columns>
                    <flux:table.column class="w-[16%]">Vendor</flux:table.column>
                    <flux:table.column class="w-[40%]">Description</flux:table.column>
                    <flux:table.column class="w-[12%]">Deposit/Check</flux:table.column>
                    <flux:table.column class="w-[6%]">Sign</flux:table.column>
                    <flux:table.column class="w-[10%]">Bank</flux:table.column>
                    <flux:table.column class="w-[12%]">Options</flux:table.column>
                    <flux:table.column class="w-[4%]"></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($vendor_transactions as $row)
                        @php
                            $amountSignLabel = match($row['amount_sign']) {
                                1 => '+$ out',
                                2 => '-$ in',
                                default => 'Any',
                            };
                        @endphp
                        <flux:table.row wire:key="vendor-txn-{{ $row['id'] }}" class="align-top">
                            <flux:table.cell class="break-words">{{ $row['vendor']['business_name'] ?? '—' }}</flux:table.cell>
                            <flux:table.cell class="break-words">{{ $row['desc'] }}</flux:table.cell>
                            <flux:table.cell class="break-words">{{ $row['deposit_check_label'] }}</flux:table.cell>
                            <flux:table.cell class="break-words">{{ $amountSignLabel }}</flux:table.cell>
                            <flux:table.cell class="break-words">{{ $row['bank']['name'] ?? '—' }}</flux:table.cell>
                            <flux:table.cell class="break-words font-mono text-xs">{{ $row['options'] }}</flux:table.cell>
                            <flux:table.cell class="text-right">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="pencil-square"
                                    wire:click="$dispatchTo('transactions.vendor-transaction-edit-modal', 'editVendorTransaction', { id: {{ $row['id'] }} })"
                                    title="Edit vendor transaction"
                                />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    </x-island-card>

    <livewire:transactions.vendor-transaction-edit-modal />
</div>