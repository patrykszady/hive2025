<flux:modal name="receipt_account_vendor_form_modal" class="space-y-2">
    <div>
        <flux:heading size="lg">{{$vendor->name ?? 'NO VENDOR'}}</flux:heading>
        <flux:subheading>Choose which Distribution all receipts or transactions from {{ $vendor->name ?? 'this vendor' }} should be automatically attached to. Select NO PROJECT if you do not want to assign automatically but still save the expense(will be asked to match project manually) </flux:subheading>
    </div>

    <flux:separator variant="subtle" />
    <flux:separator text="Distribution for all receipts and transactions" variant="subtle" />

    <form wire:submit="store" class="grid gap-6">
        <flux:select label="Distribution" wire:model.live="distribution_id" variant="listbox" placeholder="Select Distribution...">
            <flux:select.option value="NO_PROJECT">NO PROJECT</flux:select.option>
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
                    <flux:input.group label="Amount" >
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
                            x-bind:disabled="$wire.get('transactions_bulk_matches.{{ $index }}.options.amount_type') === 'ANY'"
                            inputmode="decimal"
                            step="0.01"
                            icon="currency-dollar"
                            x-bind:placeholder="$wire.get('transactions_bulk_matches.{{ $index }}.options.amount_type') === 'ANY' ? 'Any Amount' : 'Amount'"
                        />
                    </flux:input.group>

                    <flux:input wire:model.blur="transactions_bulk_matches.{{ $index }}.options.desc" label="Description" placeholder="Description to Find(regex)" />

                    <div class="space-y-2">
                        <flux:input.group label="Distribution">
                            <flux:select
                                wire:model.live="transactions_bulk_matches.{{ $index }}.distribution_id"
                                variant="listbox"
                                x-bind:disabled="$wire.get('transactions_bulk_matches.{{ $index }}.split')"
                                {{-- x-bind:disabled="split" --}}
                                {{-- {{$split == false ? 'Match is Split' : 'Choose distribution...'}} --}}
                                {{-- x-bind:placeholder="$wire.get('transactions_bulk_matches.{{ $index }}.split') === true ? 'Match is Split' : 'Choose distribution...'" --}}
                                {{-- x-bind:placeholder="$wire.get('transactions_bulk_matches.{{ $index }}.split')" --}}
                                placeholder="Choose distribution..."
                                >
                                {{-- <flux:select.option value="" disabled>{{$match->split === true ? 'Match is Split' : 'Choose distribution...'}}</flux:select.option> --}}

                                @foreach($this->distributions as $distribution)
                                    <flux:select.option value="{{$distribution->id}}">{{$distribution->name}}</flux:select.option>
                                @endforeach
                            </flux:select>

                            {{-- <flux:switch x-on:click="split = ! split" label="Split" /> --}}
                            <flux:button
                                wire:click="addSplit({{ $index }})"
                                {{-- wire:click="$toggle('transactions_bulk_matches.{{ $index }}.split')" --}}
                                >
                                Split
                            </flux:button>
                        </flux:input.group>
                        {{-- <div
                            x-data="{ isVisible: @entangle('transactions_bulk_matches.'.$index.'.split') }"
                            x-show="isVisible"
                            x-transition
                            >
                        </div> --}}
                        @if($match->splits)
                            <div class="space-y-2">
                                @foreach($match->splits as $split_index => $split)
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
                                                x-bind:placeholder="$wire.get('transactions_bulk_matches.{{ $index }}.splits.{{ $split_index }}.amount_type') === '$' ? 'Any Amount' : 'Percentage: .0145'"
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

                <flux:button type="submit" variant="primary">Connect</flux:button>
            </div>
        </div>
    </form>
</flux:modal>
