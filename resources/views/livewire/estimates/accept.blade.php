<x-form-modal name="accept_estimate_modal" title="Settings">
    <form id="accept_estimate_modal_form" wire:submit="save" class="space-y-4">
        <x-island-card heading="Estimate Sections" subheading="Choose Bid for each Section.">

            <flux:table class="p-0! m-0!">
                <flux:table.columns>
                    <flux:table.column>Section Name</flux:table.column>
                    <flux:table.column>Bid</flux:table.column>
                    <flux:table.column class="text-right">Amount</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach($sections as $index => $section)
                        <flux:table.row :key="$index">
                            <flux:table.cell class="text-bold">{{$section->name}}</flux:table.cell>
                            <flux:table.cell>
                                <flux:field size="sm">
                                    <flux:input.group size="sm">
                                        <flux:select wire:model.live="sections.{{$index}}.bid_index" variant="listbox" placeholder="Choose Bid...">
                                            @foreach($bids as $bid_index => $bid)
                                                <flux:select.option wire:key="{{$bid_index}}" value="{{$bid_index}}">
                                                    <div>
                                                        {{$bid->name}}
                                                    </div>
                                                </flux:select.option>
                                            @endforeach
                                        </flux:select>

                                        <flux:button wire:click="newEstimateBid({{$index}})" icon="plus"><span class="text-thin">Bid</span></flux:button>
                                    </flux:input.group>
                                </flux:field>
                            </flux:table.cell>

                            <flux:table.cell class="text-right">{{money($section->total)}}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </x-island-card>

        <x-island-card heading="Payment Schedule" subheading="List Estimate progressive Payments">
            <x-slot:actions>
                <span>{{ money($this->sections->where('bid_index', 0)->sum('total')) }}</span>
            </x-slot:actions>
            <flux:table class="p-0! m-0!">
                <flux:table.columns>
                    <flux:table.column>Payment</flux:table.column>
                    <flux:table.column>Description</flux:table.column>
                    <flux:table.column class="text-right">Amount</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach($payments as $index => $payment)
                        <flux:table.row :key="$index">
                            <flux:table.cell class="text-bold">
                                <div class="flex items-center justify-between gap-2">
                                    <span>Payment {{$index + 1}}</span>

                                    @if($payments->count() > 1)
                                        <flux:button
                                            wire:click="removePayment({{$index}})"
                                            variant="ghost"
                                            size="sm"
                                            icon="x-mark"
                                            class="shrink-0 text-zinc-400 hover:text-red-500 dark:text-zinc-500 dark:hover:text-red-400"
                                        />
                                    @endif
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:autocomplete
                                    size="sm"
                                    wire:model.live="payments.{{$index}}.description"
                                    placeholder="Payment Description {{$index + 1}}"
                                >
                                    @foreach($this->availableDescriptions($index) as $desc)
                                        <flux:autocomplete.item wire:key="payment-desc-{{ $index }}-{{ $loop->index }}">{{ $desc }}</flux:autocomplete.item>
                                    @endforeach
                                </flux:autocomplete>
                            </flux:table.cell>

                            <flux:table.cell class="text-right">
                                <flux:input
                                    icon="currency-dollar"
                                    size="sm"
                                    wire:model.live="payments.{{$index}}.amount"
                                    placeholder="Amount"
                                    />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
            <div class="flex justify-between">
                <flux:button wire:click="addPayment">Add Payment</flux:button>
                <span>Remaining {{ money($this->payments_remaining) }}</span>
            </div>
        </x-island-card>

        <x-island-card heading="Estimate Duration" subheading="Start and End date to include in contract.">

            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model.live="start_date" label="Start Date" type="date" />
                <flux:input wire:model.live="end_date" label="End Date" type="date" />
            </div>
        </x-island-card>
    </form>

    <x-slot name="footer">
        <flux:spacer />
        <flux:button type="submit" form="accept_estimate_modal_form" variant="primary">Update</flux:button>
    </x-slot>
</x-form-modal>
