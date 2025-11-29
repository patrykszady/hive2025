<flux:modal name="receipt_account_vendor_form_modal" class="space-y-2">
    <div>
        <flux:heading size="lg">{{$vendor->name ?? 'NO VENDOR'}}</flux:heading>
        <flux:subheading>Choose which Distribution all receipts or transactions from {{ $vendor->name ?? 'this vendor' }} should be automatically attached to. Select NO DISTRIBUTION if you do not want to assign automatically but still save the expense(will be asked to match project manually) </flux:subheading>
    </div>

    <flux:separator variant="subtle" />
    <flux:separator text="Recurring Expenses/Transactions" variant="subtle" />

    <flux:table>
        <flux:table.rows>
            @foreach ($vendor_transactions as $amount => $vendor_transactions_amount)
                <flux:table.row class="border-b-2">
                    <flux:table.cell variant="strong">
                        <span>{{ money($amount) }}</span>
                        <flux:badge color="sky" size="sm" inset="top bottom">
                            {{ $vendor_transactions_amount['count'] }}
                        </flux:badge>
                    </flux:table.cell>
                </flux:table.row>
                @foreach($vendor_transactions_amount['distributions_count'] as $distribution_name => $distribution_count)
                    <!-- Nested row with disabled borders and indenEted content -->
                    <flux:table.row class="border-0">
                        <flux:table.cell class="pl-4">
                            <flux:badge color="yellow" size="sm" inset="top bottom">
                                {{ $distribution_count }}
                            </flux:badge>
                            <span class="ml-4">{{ $distribution_name }}</span>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            @endforeach
        </flux:table.rows>
    </flux:table>

    <flux:separator text="Distribution for all receipts and transactions" variant="subtle" />

    <form wire:submit="store" class="grid gap-6">
        <flux:select label="Distribution" wire:model.live="distribution_id" variant="listbox" placeholder="Select Distribution...">
            <flux:select.option value="NO_PROJECT">NO DISTRIBUTION</flux:select.option>
            @foreach($distributions as $distribution)
                <flux:select.option value="{{$distribution->id}}">{{$distribution->name}}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:separator text="Unless receipt/transaction Amount is Matched below:" variant="subtle" />

        <flux:card class="space-y-2">
            {{-- HEADING --}}
            <div class="flex justify-between">
                <flux:heading size="lg">Amount Match</flux:heading>

                <flux:button
                    wire:click="addMatch"
                    size="sm"
                    icon="plus"
                    >
                    Add Match
                </flux:button>
            </div>
            <flux:subheading>Specific Amount Match</flux:subheading>

            @foreach ($transactions_bulk_matches as $index => $match)
                <flux:card wire:key="{{$index}}">
                    {{-- HEADING --}}
                    <div class="flex justify-between">
                        <flux:heading size="lg">Match {{$index + 1}}</flux:heading>

                        {{-- @if($loop->count > 1)
                        @endif --}}
                        <flux:button wire:click="removeMatch({{$index}})" size="sm" icon="minus">
                            Remove Match
                        </flux:button>
                    </div>

                    {{-- AMOUNT --}}
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
                            x-bind:disabled="{{($match['options']['amount_type'] ?? 'ANY') == 'ANY'}}"
                            inputmode="decimal"
                            step="0.01"
                            icon="currency-dollar"
                            placeholder="{{($match['options']['amount_type'] ?? 'ANY') == 'ANY' ? 'Any Amount' : 'Amount'}}"
                        />
                    </flux:input.group>

                    <flux:input wire:model.blur="transactions_bulk_matches.{{ $index }}.options.desc" label="Description" placeholder="Description to Find(regex)" />

                    <div class="space-y-2">
                        <flux:input.group label="Distribution">
                            <flux:select
                                wire:model.live="transactions_bulk_matches.{{ $index }}.distribution_id"
                                variant="listbox"
                                x-bind:disabled="$wire.transactions_bulk_matches[{{ $index }}].split"
                                placeholder="Choose distribution..."
                                >
                                @foreach($this->distributions as $distribution)
                                    <flux:select.option value="{{$distribution->id}}">{{$distribution->name}}</flux:select.option>
                                @endforeach
                            </flux:select>

                            <flux:button
                                wire:click="toggleSplit({{ $index }})"
                                >
                                {{ ($match['split'] ?? false) ? 'Remove Splits' : 'Split' }}
                            </flux:button>
                        </flux:input.group>
                        {{-- <div
                            x-data="{ isVisible: @entangle('transactions_bulk_matches.'.$index.'.split') }"
                            x-show="isVisible"
                            x-transition
                            >
                        </div> --}}
                        @if(isset($match['splits']) && is_array($match['splits']) && !empty($match['splits']))
                            <div class="space-y-2">
                                @foreach($match['splits'] as $split_index => $split)
                                    <flux:card wire:key="{{$split_index}}">
                                        {{-- HEADING --}}
                                        <div class="flex justify-between">
                                            <flux:heading size="lg">Split {{$split_index + 1}}</flux:heading>

                                            @if($loop->count > 2)
                                                <flux:button wire:click="removeSplit({{$index}}, {{ $split_index }})" size="sm" icon="minus">
                                                    Remove Split
                                                </flux:button>
                                            @endif
                                        </div>

                                        {{-- AMOUNT --}}
                                        <flux:input.group label="Amount" >
                                            <flux:select
                                                wire:model.live="transactions_bulk_matches.{{ $index }}.splits.{{ $split_index }}.amount_type"
                                                class="max-w-fit"
                                                >
                                                <flux:select.option value="$">$</flux:select.option>
                                                <flux:select.option value="%">%</flux:select.option>
                                            </flux:select>

                                            <flux:input
                                                wire:model.live="transactions_bulk_matches.{{ $index }}.splits.{{ $split_index }}.amount"
                                                placeholder="{{($split['amount_type'] ?? '$') == '$' ? 'Amount' : 'Percentage: .0145'}}"
                                            />
                                        </flux:input.group>

                                        <div class="mb-2">
                                            <flux:input.group label="Distribution">
                                                <flux:select
                                                    wire:model.live="transactions_bulk_matches.{{ $index }}.splits.{{ $split_index }}.distribution_id"
                                                    variant="listbox"
                                                    placeholder="Choose distribution"
                                                    >
                                                    @foreach($this->distributions as $distribution)
                                                        <flux:select.option value="{{$distribution->id}}">{{$distribution->name}}</flux:select.option>
                                                    @endforeach
                                                </flux:select>
                                            </flux:input.group>
                                        </div>
                                    </flux:card>
                                @endforeach

                                <flux:button wire:click="addSplit({{$index}})" size="sm" icon="plus">
                                    Add Split
                                </flux:button>
                            </div>
                        @endif
                    </div>
                </flux:card>
            @endforeach
        </flux:card>

        {{-- Amazon Login API --}}
        {{-- @if($vendor ? ($vendor->receipts->first()->from_type == 4 ? true : false) : false)
            <div
                x-data="{ logged_in: @entangle('vendor.logged_in') }"
                >
                <div>
                    <flux:button
                        wire:click="api_login"
                        x-text="logged_in == true ? 'Logout' : 'Login'"
                        variant="primary"
                        class="w-full"
                        >
                    </flux:button>
                </div>
            </div>
        @endif --}}

        <div
            {{-- , connect_logged_in: @entangle('vendor.logged_in') --}}
            x-show="$wire.distribution_id"
            x-transition
            >
            <div class="flex space-x-2 sticky bottom-0">
                <flux:spacer />

                <flux:button type="submit" variant="primary">Add</flux:button>
            </div>
        </div>
    </form>
</flux:modal>
