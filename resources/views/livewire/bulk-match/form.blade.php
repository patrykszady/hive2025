<flux:modal name="bulk_match_form_modal" class="space-y-2">
    <div class="flex justify-between">
        <flux:heading size="lg">{{$view_text['card_title']}}</flux:heading>
    </div>

    <flux:separator variant="subtle" />

    <form wire:submit="{{$view_text['form_submit']}}" class="grid gap-6">
        {{-- VENDOR --}}
        <flux:field>
            {{-- $view_text['form_submit'] === 'edit' ? $new_vendor->name : 'Choose vendor...' --}}
            <flux:select
                label="Vendor"
                wire:model.live="form.vendor_id"
                x-bind:disabled="{{isset($form->match)}}"
                variant="listbox"
                searchable
                placeholder="Choose vendor..."
                >
                <x-slot name="search">
                    <flux:select.search placeholder="Search..." />
                </x-slot>
                @if(isset($form->match))
                    @foreach($this->vendors as $vendor)
                        <flux:option value="{{$vendor->id}}">{{$vendor->name}}</flux:option>
                    @endforeach
                @else
                    {{-- existing_vendors --}}
                    @foreach($this->new_vendors as $vendor)
                        <flux:option value="{{$vendor->id}}">{{$vendor->name}}</flux:option>
                    @endforeach
                @endif
            </flux:select>
        </flux:field>

        <div
            x-data="{ vendor_id: @entangle('form.vendor_id') }"
            x-show="vendor_id"
            x-transition
            >
            <div
                x-show="vendor_id"
                x-transition
                >
                <flux:card class="!p-2 max-w-2xl">
                    <div class="flex justify-between">
                        {{-- <a href="{{route('checks.show', $check->id)}}"> --}}
                        <flux:heading>Vendor Transactions</flux:heading>
                        {{-- <flux:subheading>{{$check->check_type . ' ' . $check->check_number . ' ' . $check->date->format('m/d/Y')}}</flux:subheading> --}}

                        {{-- <a href="{{route('checks.show', $check->id)}}" class="text-red-800"><b>{{money($check->amount)}}</b></a> --}}
                    </div>
                </flux:card>
            </div>

            {{-- AMOUNT --}}
            <flux:input.group label="Amount" >
                <flux:select
                    wire:model.live="form.amount_type"
                    class="max-w-fit"
                    >
                    <flux:option value="ANY" selected>ANY</flux:option>
                    <flux:option value="=">=</flux:option>
                    <flux:option value=">=">>=</flux:option>
                    <flux:option value="<="><=</flux:option>
                    <flux:option value=">">></flux:option>
                    <flux:option value="<"><</flux:option>
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
                        {{-- x-bind:placeholder="split === true ? 'Bulk Match is Split' : 'Select Distribution'" --}}
                        {{-- placeholder="{{$split ? 'Match is Split' : 'Choose distribution...'}}" --}}
                        placeholder="Choose distribution..."
                        >

                        @foreach($this->distributions as $distribution)
                            <flux:option value="{{$distribution->id}}">{{$distribution->name}}</flux:option>
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

            <div
                x-data="{ split: @entangle('split') }"
                x-show="split"
                x-transition
                class="mb-2"
                >

                <flux:card class="space-y-2 !m-0">
                    {{-- HEADING --}}
                    <div class="flex justify-between">
                        <flux:heading size="lg">Splits</flux:heading>
                        <flux:button wire:click="addSplit" size="sm" icon="plus">
                            Add Split
                        </flux:button>
                    </div>

                    @foreach ($bulk_splits as $index => $split)
                        <flux:card class="space-y-2 !m-0" wire:key="{{$index}}">
                            {{-- HEADING --}}
                            <div class="flex justify-between">
                                <flux:heading size="lg">Split {{$index + 1}}</flux:heading>
                                @if($loop->count > 2)
                                    <flux:button wire:click="removeSplit({{$index}})" size="sm" icon="minus">
                                        Remove Split
                                    </flux:button>
                                @endif
                            </div>

                            {{-- AMOUNT --}}
                            <flux:input.group label="Amount" >
                                <flux:input

                                    wire:model.live="bulk_splits.{{ $index }}.amount"
                                    inputmode="decimal"
                                    step="0.01"
                                    icon="currency-dollar"
                                    placeholder="Amount / Percentage"
                                />

                                <flux:select

                                    wire:model.live="bulk_splits.{{ $index }}.amount_type"
                                    class="max-w-fit"
                                    >
                                    <flux:option value="$" selected>$</flux:option>
                                    <flux:option value="%">%</flux:option>
                                </flux:select>
                            </flux:input.group>

                            {{-- DISTRIBUTION --}}
                            <flux:select
                                wire:model.live="bulk_splits.{{ $index }}.distribution_id"
                                variant="listbox"
                                placeholder="Choose distribution..."
                                >
                                @foreach($this->distributions as $distribution)
                                    <flux:option value="{{$distribution->id}}">{{$distribution->name}}</flux:option>
                                @endforeach
                            </flux:select>
                        </flux:card>
                    @endforeach
                </flux:card>
            </div>

            {{-- FOOTER --}}
            <div class="flex space-x-2 sticky bottom-0 ">
                <flux:spacer />

                <flux:button wire:click="remove" variant="danger">Remove</flux:button>
                <flux:button type="submit" variant="primary">{{$view_text['button_text']}}</flux:button>
            </div>
        </div>
    </form>
</flux:modal>
