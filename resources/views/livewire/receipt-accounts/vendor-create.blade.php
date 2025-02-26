<flux:modal name="receipt_account_vendor_form_modal" class="space-y-2">
    <div>
        <flux:heading size="lg">{{$vendor ? $vendor->name : 'NO VENDOR'}}</flux:heading>
        <flux:subheading>Choose which Distribution a receipt from this vendor should be automatically attached to. Select NO PROJECT if you do not want to assign a distribution. </flux:subheading>
    </div>

    <flux:separator variant="subtle" />

    <form wire:submit="store" class="grid gap-6">
        <flux:select label="Distribution" wire:model.live="distribution_id" variant="listbox" placeholder="Distribution...">
            <flux:select.option value="NO_PROJECT">NO PROJECT</flux:select.option>
            @foreach($distributions as $distribution)
                <flux:select.option value="{{$distribution->id}}">{{$distribution->name}}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:card class="space-y-2">
            {{-- HEADING --}}
            <div class="flex justify-between">
                <flux:heading size="lg">Amount Match</flux:heading>
                {{-- wire:click="addSplit"  --}}
                <flux:button size="sm" icon="plus">
                    Add Match
                </flux:button>
            </div>
            <flux:subheading>Specific Receipt Amount Match</flux:subheading>


            @foreach ($vendor->transactions_bulk_match ?? [] as $index => $match)
                <flux:card wire:key="{{$index}}">
                    {{-- HEADING --}}
                    <div class="flex justify-between">
                        <flux:heading size="lg">Match {{$index + 1}}</flux:heading>
                        {{-- @if($loop->count > 2)
                            <flux:button wire:click="removeSplit({{$index}})" size="sm" icon="minus">
                                Remove Split
                            </flux:button>
                        @endif --}}
                    </div>

                    {{-- AMOUNT --}}
                    <flux:input.group label="Amount" >
                        <flux:select
                            wire:model.live="form.amount_type"
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
                            wire:model.live="form.amount"
                            x-bind:disabled="{{$form->amount_type == 'ANY'}}"
                            inputmode="decimal"
                            step="0.01"
                            icon="currency-dollar"
                            placeholder="{{$form->amount_type == 'ANY' ? 'Any Amount' : 'Amount'}}"
                            />
                    </flux:input.group>

                    <flux:input wire:model.blur="form.desc" label="Description" placeholder="Description to Find(regex)" />

                    <div x-data="{ split: @entangle('split') }" class="mb-2">
                        <flux:input.group label="Distribution">
                            <flux:select
                                wire:model.live="form.distribution_id"
                                x-bind:disabled="split"
                                variant="listbox"
                                {{-- {{$split == false ? 'Match is Split' : 'Choose distribution...'}} --}}
                                {{-- x-bind:placeholder="$wire.split === true ? 'Match is Split' : 'placeholder'" --}}
                                {{-- placeholder="{{$split ? 'Match is Split' : 'Choose distribution...'}}" --}}
                                {{-- x-bind:placeholder="split ? 'HAS SPLIT' : 'NO SPLIT'" --}}
                                placeholder="Choose distribution"
                                >

                                @foreach($this->distributions as $distribution)
                                    <flux:select.option value="{{$distribution->id}}">{{$distribution->name}}</flux:select.option>
                                @endforeach
                            </flux:select>

                            {{-- <flux:switch x-on:click="split = ! split" label="Split" /> --}}
                            <flux:button
                                {{-- wire:click="bulkSplits" --}}
                                {{-- x-on:click="split = ! split" --}}
                                wire:click="$toggle('split')"
                                >
                                Split
                            </flux:button>
                        </flux:input.group>
                    </div>
                </flux:card>
            @endforeach
        </flux:card>

        {{-- Amazon Login API --}}
        @if($vendor ? $vendor->receipts->first()->from_type == 4 : false)
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
        @endif

        <div
            {{-- , connect_logged_in: @entangle('vendor.logged_in') --}}
            x-data="{ distribution_id: @entangle('distribution_id') }"
            x-show="distribution_id"
            x-transition
            >
            <div class="flex space-x-2 sticky bottom-0">
                <flux:spacer />

                <flux:button type="submit" variant="primary">Connect</flux:button>
            </div>
        </div>
    </form>
</flux:modal>
