<div>
    <x-page.breadcrumbs :items="[
        ['label' => 'Global Actions'],
        ['label' => 'Match Vendor'],
    ]" />

    <div class="space-y-6">
        {{-- Both card lists are lazy islands: the page paints at once and the
             cards follow, instead of the browser waiting on one huge render.
             always: true because saving either form can change the OTHER list
             (matching a transaction also re-matches receipt expenses). --}}
        @island(name: 'transaction-cards', lazy: island_lazy(), always: true)
            @placeholder
                <div class="max-w-3xl">
                    <x-island-card :enter="true" heading="Transactions" class="mt-6">
                        <div class="py-8 text-sm text-zinc-500">Loading unmatched transactions...</div>
                    </x-island-card>
                </div>
            @endplaceholder

            <div class="max-w-3xl">
            <form wire:submit="store">
                @foreach($this->merchantCards as $merchant_name => $merchant_transactions)
                    @php
                        $cardIndex = $loop->index;
                        $pickerKey = 'txn_'.$cardIndex;
                    @endphp
                    <x-island-card
                        class="mt-6"
                        wire:key="txn-card-{{ $cardIndex }}"
                        :heading="$merchant_name"
                        :subheading="$merchant_name != $merchant_transactions->first()->plaid_merchant_name ? $merchant_transactions->first()->plaid_merchant_name : null"
                    >
                        {{-- Top-right of the header: the card's one action. --}}
                        <x-slot:actions>
                            <flux:button
                                type="button"
                                size="sm"
                                icon="sparkles"
                                wire:click="suggestVendor({{ $cardIndex }})"
                                wire:loading.attr="disabled"
                                wire:target="suggestVendor({{ $cardIndex }})"
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
                                            <flux:table.cell>{{ $transaction->card_location }}</flux:table.cell>
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
                                wire:model="match_merchant_names.{{ $cardIndex }}.match_desc"
                                label="Match As"
                            />

                            {{-- Server-searched picker: one scroll-page of vendors at a
                                 time, never the whole list. Same treatment as the
                                 expenses filter. --}}
                            <flux:select
                                wire:model="match_merchant_names.{{ $cardIndex }}.vendor_id"
                                label="Vendor"
                                variant="listbox"
                                searchable
                                placeholder="Select Vendor"
                            >
                                <x-slot name="search">
                                    <flux:select.search x-on:input.debounce.400ms="$wire.searchVendors('{{ $pickerKey }}', $event.target.value)" placeholder="Search vendors..." />
                                </x-slot>
                                <flux:select.option value="NEW">NEW Retail Vendor</flux:select.option>
                                <flux:select.option value="DEPOSIT">Deposit Transaction</flux:select.option>
                                <flux:select.option value="CHECK">Check Paid</flux:select.option>
                                <flux:select.option value="TRANSFER">Transfer/Zelle Out</flux:select.option>
                                <flux:select.option value="CASH">Cash Withdrawal</flux:select.option>
                                <flux:separator />
                                @foreach ($this->vendorOptions($pickerKey) as $vendor)
                                    <flux:select.option :value="$vendor->id">{{ $vendor->business_name }}{{ $vendor->city ? ' — '.$vendor->city.($vendor->state ? ', '.$vendor->state : '') : '' }}</flux:select.option>
                                @endforeach
                                @if ($this->hasMoreVendorOptions($pickerKey))
                                    {{-- Scrolling this into view inside the open dropdown loads the next page. --}}
                                    <div wire:intersect="loadMoreVendorOptions('{{ $pickerKey }}')" class="px-3 py-2 text-xs text-zinc-400" wire:key="vendor-more-{{ $pickerKey }}-{{ $vendor_limit[$pickerKey] ?? 0 }}">
                                        <span wire:loading.remove wire:target="loadMoreVendorOptions('{{ $pickerKey }}')">Scroll for more…</span>
                                        <span wire:loading wire:target="loadMoreVendorOptions('{{ $pickerKey }}')">Loading…</span>
                                    </div>
                                @endif
                            </flux:select>

                            <flux:input.group label="Options">
                                <flux:input
                                    wire:model="match_merchant_names.{{ $cardIndex }}.options"
                                    placeholder="regex"
                                />
                                <flux:select
                                    wire:model="match_merchant_names.{{ $cardIndex }}.amount_sign"
                                    class="max-w-fit"
                                >
                                    <flux:select.option value="">Any</flux:select.option>
                                    <flux:select.option value="1">+$ out</flux:select.option>
                                    <flux:select.option value="2">-$ in</flux:select.option>
                                </flux:select>
                                <flux:button
                                    type="button"
                                    x-on:click="$wire.set('match_merchant_names.{{ $cardIndex }}.bank_specific', !$wire.get('match_merchant_names.{{ $cardIndex }}.bank_specific'))"
                                    variant="{{ ($match_merchant_names[$cardIndex]['bank_specific'] ?? false) ? 'primary' : 'outline' }}"
                                >
                                    Bank Specific
                                </flux:button>
                            </flux:input.group>

                            <div wire:loading wire:target="suggestVendor({{ $cardIndex }})" class="text-xs italic text-zinc-500">
                                Searching the web for this merchant…
                            </div>

                            @if(isset($ai_suggestions[$cardIndex]))
                                @php($suggestion = $ai_suggestions[$cardIndex])
                                <flux:card class="p-3! space-y-2">
                                    @if(isset($suggestion['error']))
                                        <flux:text>{{ $suggestion['error'] }}</flux:text>
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
                                        <flux:text>
                                            {{ $suggestion['reasoning'] }}
                                            @if(!empty($suggestion['website']))
                                                <a href="{{ $suggestion['website'] }}" target="_blank" rel="noopener" class="underline">{{ $suggestion['website'] }}</a>
                                            @endif
                                            @if(!empty($suggestion['city']))
                                                &middot; {{ collect([$suggestion['city'], $suggestion['state'] ?? null])->filter()->implode(', ') }}
                                            @endif
                                        </flux:text>
                                        <div>
                                            <flux:button
                                                type="button"
                                                size="sm"
                                                variant="primary"
                                                wire:click="applySuggestion({{ $cardIndex }})"
                                                wire:loading.attr="disabled"
                                                wire:target="applySuggestion({{ $cardIndex }})"
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

                @if($this->merchantCards->isEmpty())
                    <x-island-card class="mt-6" heading="Transactions">
                        <flux:text class="py-4">Every transaction has a vendor — nothing to match.</flux:text>
                    </x-island-card>
                @else
                    <div class="flex justify-end mt-6">
                        <flux:button type="submit" variant="primary">
                            {{ $view_text['button_text'] }}
                        </flux:button>
                    </div>
                @endif
            </form>
            </div>
        @endisland

        @island(name: 'expense-cards', lazy: island_lazy(), always: true)
            @placeholder
                <div class="max-w-3xl">
                    <x-island-card :enter="true" heading="Receipt Expenses">
                        <div class="py-8 text-sm text-zinc-500">Loading receipt expenses...</div>
                    </x-island-card>
                </div>
            @endplaceholder

            <div class="max-w-3xl">
            <form wire:submit="store_expense_vendors">
                @foreach($this->expenseCards as $merchant_name => $merchant_expenses)
                    @php
                        $cardIndex = $loop->index;
                        $pickerKey = 'exp_'.$cardIndex;
                    @endphp
                    <x-island-card class="mt-6" wire:key="exp-card-{{ $cardIndex }}" :heading="$merchant_name !== '' ? $merchant_name : 'Receipt without a merchant name'">
                        <div class="space-y-4">
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

                            <flux:input
                                wire:model="match_expense_merchant_names.{{ $cardIndex }}.match_desc"
                                label="Match As"
                            />

                            <flux:select
                                wire:model="match_expense_merchant_names.{{ $cardIndex }}.vendor_id"
                                label="Vendor"
                                variant="listbox"
                                searchable
                                placeholder="Select Vendor"
                            >
                                <x-slot name="search">
                                    <flux:select.search x-on:input.debounce.400ms="$wire.searchVendors('{{ $pickerKey }}', $event.target.value)" placeholder="Search vendors..." />
                                </x-slot>
                                <flux:select.option value="NEW">NEW Retail Vendor</flux:select.option>
                                <flux:separator />
                                @foreach ($this->vendorOptions($pickerKey) as $vendor)
                                    <flux:select.option :value="$vendor->id">{{ $vendor->business_name }}{{ $vendor->city ? ' — '.$vendor->city.($vendor->state ? ', '.$vendor->state : '') : '' }}</flux:select.option>
                                @endforeach
                                @if ($this->hasMoreVendorOptions($pickerKey))
                                    <div wire:intersect="loadMoreVendorOptions('{{ $pickerKey }}')" class="px-3 py-2 text-xs text-zinc-400" wire:key="vendor-more-{{ $pickerKey }}-{{ $vendor_limit[$pickerKey] ?? 0 }}">
                                        <span wire:loading.remove wire:target="loadMoreVendorOptions('{{ $pickerKey }}')">Scroll for more…</span>
                                        <span wire:loading wire:target="loadMoreVendorOptions('{{ $pickerKey }}')">Loading…</span>
                                    </div>
                                @endif
                            </flux:select>
                        </div>
                    </x-island-card>
                @endforeach

                @if($this->expenseCards->isNotEmpty())
                    <div class="flex justify-end mt-6">
                        <flux:button type="submit" variant="primary">
                            Sync Expenses & Vendors
                        </flux:button>
                    </div>
                @endif
            </form>
            </div>
        @endisland

        <div class="max-w-5xl">
        <livewire:transactions.vendor-transactions-panel defer />
        </div>
    </div>
</div>
