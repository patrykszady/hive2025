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
                {{-- Select which Bid each Section belongs to. --}}
                <flux:subheading>Choose Bid for each Section.</flux:subheading>
            </div>

            <flux:table class="!p-0 !m-0">
                <flux:columns>
                    <flux:column>Section Name</flux:column>
                    <flux:column>Bid</flux:column>
                    <flux:column class="text-right">Amount</flux:column>
                </flux:columns>

                <flux:rows>
                    @foreach($sections as $index => $section)
                        <flux:row :key="$index">
                            <flux:cell class="text-bold">{{$section->name}}</flux:cell>
                            <flux:cell>
                                <flux:field size="sm">
                                    <flux:input.group size="sm">
                                        <flux:select wire:model.live="sections.{{$index}}.bid_index" variant="listbox" placeholder="Choose Bid...">
                                            @foreach($bids as $bid_index => $bid)
                                                <flux:option wire:key="{{$bid_index}}" value="{{$bid_index}}">
                                                    <div>
                                                        {{$bid->name}}
                                                    </div>
                                                </flux:option>
                                            @endforeach
                                        </flux:select>

                                        <flux:button wire:click="newEstimateBid({{$index}})" icon="plus"><span class="text-thin">Bid</span></flux:button>
                                    </flux:input.group>
                                </flux:field>
                            </flux:cell>

                            <flux:cell class="text-right">{{money($section->total)}}</flux:cell>
                        </flux:row>
                    @endforeach
                </flux:rows>
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
            <flux:table class="!p-0 !m-0">
                <flux:columns>
                    <flux:column>Payment</flux:column>
                    <flux:column>Description</flux:column>
                    <flux:column class="text-right">Amount</flux:column>
                </flux:columns>

                <flux:rows>
                    @foreach($payments as $index => $payment)
                        <flux:row :key="$index">
                            <flux:cell class="text-bold">
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
                            </flux:cell>
                            <flux:cell>
                                <flux:input
                                    size="sm"
                                    wire:model.live="payments.{{$index}}.description"
                                    placeholder="Payment Description {{$index + 1}}"
                                    />
                            </flux:cell>

                            <flux:cell class="text-right">
                                <flux:input
                                    icon="currency-dollar"
                                    size="sm"
                                    wire:model.live="payments.{{$index}}.amount"
                                    placeholder="Amount"
                                    />
                            </flux:cell>
                        </flux:row>
                    @endforeach
                </flux:rows>
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
