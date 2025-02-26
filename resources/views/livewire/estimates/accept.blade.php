<flux:modal name="accept_estimate_modal">
    <div class="flex justify-between space-y-2">
        <flux:heading size="lg">Finalize Estimate</flux:heading>
    </div>

    <flux:separator variant="subtle" class="mb-2" />

    <form wire:submit="save" class="grid gap-6">
        <flux:card>
            {{-- HEADING --}}
            <div class="flex justify-between">
                <flux:heading size="lg">Estimate Sections</flux:heading>
            </div>
            {{-- Select which Bid each Section belongs to. --}}
            <flux:subheading>Choose Bid for each Section.</flux:subheading>

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
        </flux:card>

        <flux:card>
            {{-- HEADING --}}
            <div class="flex justify-between">
                <flux:heading size="lg">Reimbursements</flux:heading>
                <span>{{money($project->finances['reimbursments'])}}</span>
            </div>
            <flux:subheading>Include Project Reimbursements in Estimate.</flux:subheading>

            <flux:radio.group wire:model="include_reimbursement" variant="segmented" size="sm">
                <flux:radio value="true" label="Include" />
                <flux:radio value="false" label="Don't Include" />
            </flux:radio.group>
        </flux:card>

        <flux:card>
            {{-- HEADING --}}
            <div class="flex justify-between">
                <flux:heading size="lg">Payment Schedule</flux:heading>
                <span>{{ money($this->sections->where('bid_index', 0)->sum('total')) }}</span>
            </div>
            {{-- List your project progressive payments for the Original Bid of this Estimate. --}}
            <flux:subheading>List Estimate progressive Payments</flux:subheading>
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
                                Payment {{$index + 1}}
                                @if($payments->count() > 1)
                                    <flux:button
                                        wire:click="removePayment({{$index}})"
                                        variant="filled"
                                        size="sm"
                                        >
                                        Remove
                                    </flux:button>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:input
                                    size="sm"
                                    wire:model.live="payments.{{$index}}.description"
                                    placeholder="Payment Description {{$index + 1}}"
                                    />
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
        </flux:card>

        <flux:card>
            {{-- HEADING --}}
            <div class="flex justify-between">
                <flux:heading size="lg">Estimate Duration</flux:heading>
            </div>
            <flux:subheading>Start and End date to include in contract.</flux:subheading>

            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model.live="start_date" label="Start Date" type="date" />
                <flux:input wire:model.live="end_date" label="End Date" type="date" />
            </div>
        </flux:card>

        {{-- FOOTER --}}
        <div class="flex space-x-2 sticky bottom-0">
            <flux:spacer />

            <flux:button type="submit" variant="primary">Finalize</flux:button>
        </div>
    </form>
</flux:modal>
