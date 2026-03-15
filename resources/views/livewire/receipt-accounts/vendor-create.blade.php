<x-form-modal name="receipt_account_vendor_form_modal">
    <x-slot:header>
        <div class="flex items-center justify-between gap-2 min-w-0">
            <flux:heading size="lg">
                @if($vendor?->id)
                    <flux:link href="{{ route('vendors.show', $vendor) }}" target="_blank" variant="ghost" :accent="false" class="hover:underline">{{ $vendor->name }}</flux:link>
                @else
                    NO VENDOR
                @endif
            </flux:heading>
        </div>
        <flux:separator variant="subtle" />
    </x-slot:header>
    <flux:subheading class="mb-4">Create automatic receipt and transaction matches for {{ $vendor->name ?? 'this vendor' }}. Matched expenses will be assigned a distribution automatically.</flux:subheading>

    <form id="receipt_account_vendor_form_modal_form" wire:submit="store">
        <x-island-card heading="Amount Match">
            <x-slot:actions>
                <flux:button wire:click="addMatch" size="sm" icon="plus">Add Match</flux:button>
            </x-slot:actions>

            @if(count($transactions_bulk_matches) > 0)
                <div class="space-y-3">
                    @foreach ($transactions_bulk_matches as $index => $match)
                        <flux:card wire:key="match-{{ $index }}">
                            <div class="flex justify-between">
                                <flux:heading size="lg">Match {{ $index + 1 }}</flux:heading>
                                <flux:button wire:click="removeMatch({{ $index }})" size="sm" icon="minus">Remove</flux:button>
                            </div>

                            <flux:input.group label="Amount">
                                <flux:select
                                    wire:model.live="transactions_bulk_matches.{{ $index }}.options.amount_type"
                                    class="max-w-fit"
                                >
                                    <flux:select.option value="ANY" selected>ANY</flux:select.option>
                                    <flux:select.option value="=">=</flux:select.option>
                                    <flux:select.option value=">=">>=</flux:select.option>
                                    <flux:select.option value="<="><=</flux:select.option>
                                    <flux:select.option value=">">></flux:select.option>
                                    <flux:select.option value="<"><</flux:select.option>
                                </flux:select>

                                <flux:input
                                    wire:model.live="transactions_bulk_matches.{{ $index }}.amount"
                                    x-bind:disabled="{{ ($match['options']['amount_type'] ?? 'ANY') == 'ANY' }}"
                                    inputmode="decimal"
                                    step="0.01"
                                    icon="currency-dollar"
                                    placeholder="{{ ($match['options']['amount_type'] ?? 'ANY') == 'ANY' ? 'Any Amount' : 'Amount' }}"
                                />
                            </flux:input.group>

                            <flux:input wire:model.blur="transactions_bulk_matches.{{ $index }}.options.desc" label="Description" placeholder="Description to Find (regex)" />

                            <div class="space-y-2">
                                <flux:input.group label="Distribution">
                                    <flux:select
                                        wire:model.live="transactions_bulk_matches.{{ $index }}.distribution_id"
                                        variant="listbox"
                                        x-bind:disabled="$wire.transactions_bulk_matches[{{ $index }}].split"
                                        placeholder="Choose distribution..."
                                    >
                                        @foreach($this->distributions as $distribution)
                                            <flux:select.option value="{{ $distribution->id }}">{{ $distribution->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>

                                    <flux:button wire:click="toggleSplit({{ $index }})">
                                        {{ ($match['split'] ?? false) ? 'Remove Splits' : 'Split' }}
                                    </flux:button>
                                </flux:input.group>

                                @if(isset($match['splits']) && is_array($match['splits']) && !empty($match['splits']))
                                    <div class="space-y-2">
                                        @foreach($match['splits'] as $split_index => $split)
                                            <flux:card wire:key="split-{{ $index }}-{{ $split_index }}">
                                                <div class="flex justify-between">
                                                    <flux:heading size="lg">Split {{ $split_index + 1 }}</flux:heading>
                                                    @if($loop->count > 2)
                                                        <flux:button wire:click="removeSplit({{ $index }}, {{ $split_index }})" size="sm" icon="minus">Remove</flux:button>
                                                    @endif
                                                </div>

                                                <flux:input.group label="Amount">
                                                    <flux:select
                                                        wire:model.live="transactions_bulk_matches.{{ $index }}.splits.{{ $split_index }}.amount_type"
                                                        class="max-w-fit"
                                                    >
                                                        <flux:select.option value="$">$</flux:select.option>
                                                        <flux:select.option value="%">%</flux:select.option>
                                                    </flux:select>

                                                    <flux:input
                                                        wire:model.live="transactions_bulk_matches.{{ $index }}.splits.{{ $split_index }}.amount"
                                                        placeholder="{{ ($split['amount_type'] ?? '$') == '$' ? 'Amount' : 'Percentage: .0145' }}"
                                                    />
                                                </flux:input.group>

                                                <flux:input.group label="Distribution">
                                                    <flux:select
                                                        wire:model.live="transactions_bulk_matches.{{ $index }}.splits.{{ $split_index }}.distribution_id"
                                                        variant="listbox"
                                                        placeholder="Choose distribution"
                                                    >
                                                        @foreach($this->distributions as $distribution)
                                                            <flux:select.option value="{{ $distribution->id }}">{{ $distribution->name }}</flux:select.option>
                                                        @endforeach
                                                    </flux:select>
                                                </flux:input.group>
                                            </flux:card>
                                        @endforeach

                                        <flux:button wire:click="addSplit({{ $index }})" size="sm" icon="plus">Add Split</flux:button>
                                    </div>
                                @endif
                            </div>
                        </flux:card>
                    @endforeach
                </div>
            @endif
        </x-island-card>
    </form>

    @if(count($vendor_transactions) > 0)
        <x-island-card heading="Recurring Transaction Patterns" class="mt-4">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Amount</flux:table.column>
                    <flux:table.column>Count</flux:table.column>
                    <flux:table.column>Distribution</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($vendor_transactions as $amount => $vendor_transactions_amount)
                        @foreach($vendor_transactions_amount['distributions_count'] as $distribution_name => $distribution_count)
                            <flux:table.row wire:key="recurring-{{ $amount }}-{{ $loop->index }}">
                                <flux:table.cell variant="strong">
                                    @if($loop->first)
                                        {{ money($amount) }}
                                        <flux:badge color="sky" size="sm" inset="top bottom">{{ $vendor_transactions_amount['count'] }}</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge color="yellow" size="sm" inset="top bottom">{{ $distribution_count }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>{{ $distribution_name }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </x-island-card>
    @endif

    <x-slot name="footer">
        <flux:spacer />
        <flux:button type="submit" form="receipt_account_vendor_form_modal_form" variant="primary">Add</flux:button>
    </x-slot>
</x-form-modal>
