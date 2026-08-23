<div>
    <x-page.breadcrumbs :items="[
        ['label' => 'Global Actions'],
        ['label' => 'Match Vendor'],
    ]" />

    <div class="space-y-6">
    {{-- Transaction Matching --}}
        <div class="max-w-3xl">
        <form wire:submit="{{ $view_text['form_submit'] }}">
            @foreach($merchant_names as $merchant_name => $merchant_transactions)
                <x-island-card
                    class="mt-6"
                    wire:key="txn-card-{{ $loop->index }}"
                    :heading="$merchant_name"
                    :subheading="$merchant_name != $merchant_transactions->first()->plaid_merchant_name ? $merchant_transactions->first()->plaid_merchant_name : null"
                >
                    {{-- Top-right of the header: the card's one action. --}}
                    <x-slot:actions>
                        <flux:button
                            type="button"
                            size="sm"
                            icon="sparkles"
                            wire:click="suggestVendor({{ $loop->index }})"
                            wire:loading.attr="disabled"
                            wire:target="suggestVendor({{ $loop->index }})"
                        >
                            AI Identify
                        </flux:button>
                    </x-slot:actions>

                    <div class="space-y-4">
                        {{-- Transaction list --}}
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>Amount</flux:table.column>
                                <flux:table.column>Date</flux:table.column>
                                <flux:table.column>Location</flux:table.column>
                                <flux:table.column>Bank | Type</flux:table.column>
                                <flux:table.column>Company</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach($merchant_transactions as $transaction)
                                    <flux:table.row wire:key="txn-row-{{ $transaction->id }}">
                                        <flux:table.cell variant="strong">{{ money($transaction->amount) }}</flux:table.cell>
                                        <flux:table.cell>{{ $transaction->transaction_date->format('m/d/Y') }}</flux:table.cell>
                                        <flux:table.cell>{{ $txn_locations[$transaction->id] ?? '' }}</flux:table.cell>
                                        {{-- Null-safe: after a Livewire action, rehydrated models
                                             lazy-load bank_account WITH global scopes, which hides
                                             sibling companies' accounts — must not crash the row. --}}
                                        <flux:table.cell>
                                            {{ $transaction->bank_account?->bank?->name ?? '' }} | {{ $transaction->bank_account?->type ?? '' }}
                                        </flux:table.cell>
                                        <flux:table.cell>{{ $transaction->bank_account?->bank?->vendor?->business_name ?? '' }}</flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>

                        <flux:separator />

                        {{-- Form fields --}}
                        <flux:input
                            wire:model="match_merchant_names.{{ $loop->index }}.match_desc"
                            label="Match As"
                        />

                        <flux:select
                            wire:model="match_merchant_names.{{ $loop->index }}.vendor_id"
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
                                <flux:select.option :value="$vendor->id">{{ $vendor->business_name }}{{ $vendor->city ? ' — '.$vendor->city.($vendor->state ? ', '.$vendor->state : '') : '' }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:input.group label="Options">
                            <flux:input
                                wire:model="match_merchant_names.{{ $loop->index }}.options"
                                placeholder="regex"
                            />
                            <flux:select
                                wire:model="match_merchant_names.{{ $loop->index }}.amount_sign"
                                class="max-w-fit"
                            >
                                <flux:select.option value="">Any</flux:select.option>
                                <flux:select.option value="1">+$ out</flux:select.option>
                                <flux:select.option value="2">-$ in</flux:select.option>
                            </flux:select>
                            <flux:button
                                type="button"
                                x-on:click="$wire.set('match_merchant_names.{{ $loop->index }}.bank_specific', !$wire.get('match_merchant_names.{{ $loop->index }}.bank_specific'))"
                                variant="{{ ($match_merchant_names[$loop->index]['bank_specific'] ?? false) ? 'primary' : 'outline' }}"
                            >
                                Bank Specific
                            </flux:button>
                        </flux:input.group>

                        <div wire:loading wire:target="suggestVendor({{ $loop->index }})" class="text-xs italic text-zinc-500">
                            Searching the web for this merchant…
                        </div>

                        @if(isset($ai_suggestions[$loop->index]))
                            @php($suggestion = $ai_suggestions[$loop->index])
                            <flux:card class="p-3! space-y-2">
                                @if(isset($suggestion['error']))
                                    <flux:subheading>{{ $suggestion['error'] }}</flux:subheading>
                                @else
                                    <div class="flex items-center gap-2">
                                        <flux:heading>{{ $suggestion['vendor_name'] }}</flux:heading>
                                        <flux:badge size="sm" inset="top bottom" color="{{ ['high' => 'green', 'medium' => 'yellow', 'low' => 'zinc'][$suggestion['confidence']] ?? 'zinc' }}">
                                            {{ $suggestion['confidence'] }} confidence
                                        </flux:badge>
                                        @if(!empty($suggestion['existing_vendor_id']))
                                            <flux:badge size="sm" inset="top bottom" color="blue">existing vendor</flux:badge>
                                        @endif
                                    </div>
                                    <flux:subheading>
                                        {{ $suggestion['reasoning'] }}
                                        @if(!empty($suggestion['website']))
                                            <a href="{{ $suggestion['website'] }}" target="_blank" rel="noopener" class="underline">{{ $suggestion['website'] }}</a>
                                        @endif
                                        @if(!empty($suggestion['city']))
                                            &middot; {{ collect([$suggestion['city'], $suggestion['state'] ?? null])->filter()->implode(', ') }}
                                        @endif
                                    </flux:subheading>
                                    <div>
                                        <flux:button
                                            type="button"
                                            size="sm"
                                            variant="primary"
                                            wire:click="applySuggestion({{ $loop->index }})"
                                            wire:loading.attr="disabled"
                                            wire:target="applySuggestion({{ $loop->index }})"
                                        >
                                            {{ empty($suggestion['existing_vendor_id']) ? 'Create "'.$suggestion['vendor_name'].'" + match' : 'Use this vendor' }}
                                        </flux:button>
                                    </div>
                                @endif
                            </flux:card>
                        @endif
                    </div>
                </x-island-card>
            @endforeach

            <div class="flex justify-end mt-6">
                <flux:button type="submit" variant="primary">
                    {{ $view_text['button_text'] }}
                </flux:button>
            </div>
        </form>
        </div>

        <div class="max-w-5xl">
        <livewire:transactions.vendor-transactions-panel defer />
        </div>

        {{-- Expense Matching --}}
            <div class="max-w-3xl">
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
                                wire:model="match_expense_merchant_names.{{ $loop->index }}.match_desc"
                                label="Match As"
                            />

                            <flux:select
                                wire:model="match_expense_merchant_names.{{ $loop->index }}.vendor_id"
                                label="Vendor"
                                variant="listbox"
                                searchable
                                placeholder="Select Vendor"
                            >
                                <flux:select.option value="NEW">NEW Retail Vendor</flux:select.option>
                                @foreach ($vendors as $vendor)
                                    <flux:select.option :value="$vendor->id">{{ $vendor->business_name }}{{ $vendor->city ? ' — '.$vendor->city.($vendor->state ? ', '.$vendor->state : '') : '' }}</flux:select.option>
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
    </div>
</div>
