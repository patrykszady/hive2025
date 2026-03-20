<div class="max-w-3xl space-y-6">
    {{-- Transaction Matching --}}
    <form wire:submit="{{ $view_text['form_submit'] }}">
        @foreach($merchant_names as $merchant_name => $merchant_transactions)
            <x-island-card class="mt-6" wire:key="txn-card-{{ $loop->index }}">
                <flux:heading size="lg" class="break-all">
                    {{ $merchant_name }}
                </flux:heading>
                @if($merchant_name != $merchant_transactions->first()->plaid_merchant_name)
                    <flux:subheading>{{ $merchant_transactions->first()->plaid_merchant_name }}</flux:subheading>
                @endif

                <div class="space-y-4">
                    {{-- Transaction list --}}
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Amount</flux:table.column>
                            <flux:table.column>Date</flux:table.column>
                            <flux:table.column>Bank | Type</flux:table.column>
                            <flux:table.column>Company</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach($merchant_transactions as $transaction)
                                <flux:table.row wire:key="txn-row-{{ $transaction->id }}">
                                    <flux:table.cell variant="strong">{{ money($transaction->amount) }}</flux:table.cell>
                                    <flux:table.cell>{{ $transaction->transaction_date->format('m/d/Y') }}</flux:table.cell>
                                    <flux:table.cell>
                                        {{ $transaction->bank_account->bank ? $transaction->bank_account->bank->name : '' }} | {{ $transaction->bank_account->type }}
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $transaction->bank_account->bank->vendor->business_name }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>

                    <flux:separator />

                    {{-- Form fields --}}
                    <flux:input
                        wire:model.live="match_merchant_names.{{ $loop->index }}.match_desc"
                        label="Match As"
                    />

                    <flux:select
                        wire:model.live.debounce.250ms="match_merchant_names.{{ $loop->index }}.vendor_id"
                        label="Vendor"
                        variant="listbox"
                        searchable
                        placeholder="Select Vendor"
                    >
                        <flux:select.option value="NEW">NEW Retail Vendor</flux:select.option>
                        <flux:select.option value="DEPOSIT">Deposit Transaction</flux:select.option>
                        <flux:select.option value="CHECK">Check Paid</flux:select.option>
                        <flux:select.option value="TRANSFER">Transfer/Zelle Out</flux:select.option>
                        <flux:select.option value="CASH">Cash Withdrawal</flux:select.option>
                        <flux:separator />
                        @foreach ($vendors as $vendor)
                            <flux:select.option :value="$vendor->id">{{ $vendor->business_name }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:input.group label="Options">
                        <flux:input
                            wire:model.live="match_merchant_names.{{ $loop->index }}.options"
                            placeholder="regex"
                        />
                        <flux:select
                            wire:model.live="match_merchant_names.{{ $loop->index }}.amount_sign"
                            class="max-w-fit"
                        >
                            <flux:select.option value="">Any</flux:select.option>
                            <flux:select.option value="1">+$ out</flux:select.option>
                            <flux:select.option value="2">-$ in</flux:select.option>
                        </flux:select>
                        <flux:button
                            x-on:click="$wire.set('match_merchant_names.{{ $loop->index }}.bank_specific', !$wire.get('match_merchant_names.{{ $loop->index }}.bank_specific'))"
                            :variant="($match_merchant_names[$loop->index]['bank_specific'] ?? false) ? 'primary' : 'outline'"
                        >
                            Bank Specific
                        </flux:button>
                    </flux:input.group>
                </div>
            </x-island-card>
        @endforeach

        <div class="flex justify-end mt-6">
            <flux:button type="submit" variant="primary">
                {{ $view_text['button_text'] }}
            </flux:button>
        </div>
    </form>

    {{-- Expense Matching --}}
    <form wire:submit="store_expense_vendors">
        @foreach($expense_receipt_merchants as $merchant_name => $merchant_expenses)
            <x-island-card class="mt-6" wire:key="exp-card-{{ $loop->index }}">
                <flux:heading size="lg" class="break-all">{{ $merchant_name }}</flux:heading>

                <div class="space-y-4">
                    {{-- Expense list --}}
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Amount</flux:table.column>
                            <flux:table.column>Date</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach($merchant_expenses as $expense)
                                <flux:table.row wire:key="exp-row-{{ $expense->id }}">
                                    <flux:table.cell variant="strong">{{ money($expense->amount) }}</flux:table.cell>
                                    <flux:table.cell>{{ $expense->date->format('m/d/Y') }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>

                    <flux:separator />

                    {{-- Form fields --}}
                    <flux:input
                        wire:model.live="match_expense_merchant_names.{{ $loop->index }}.match_desc"
                        label="Match As"
                    />

                    <flux:select
                        wire:model.live.debounce.250ms="match_expense_merchant_names.{{ $loop->index }}.vendor_id"
                        label="Vendor"
                        variant="listbox"
                        searchable
                        placeholder="Select Vendor"
                    >
                        <flux:select.option value="NEW">NEW Retail Vendor</flux:select.option>
                        @foreach ($vendors as $vendor)
                            <flux:select.option :value="$vendor->id">{{ $vendor->business_name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            </x-island-card>
        @endforeach

        <div class="flex justify-end mt-6">
            <flux:button type="submit" variant="primary">
                Sync Expenses & Vendors
            </flux:button>
        </div>
    </form>
</div>
