{{-- PROJECT FINANCIALS --}}
<x-island-card heading="Project Finances" wire:transition>
    <x-slot:actions>
        @can('create', App\Models\Bid::class)
            @php
                $userBids = $project->bids()->vendorBids(auth()->user()->vendor->id)->with('estimate_sections')->get();
                $hasEditableBids = $userBids->isEmpty() || $userBids->contains(function($bid) {
                    return $bid->estimate_sections->isEmpty();
                });
            @endphp
            @if($hasEditableBids)
                <flux:button
                    wire:click="$dispatchTo('bids.bid-create', 'addBids', { vendor: {{auth()->user()->vendor->id}}, project: {{$project->id}} })"
                    size="sm"
                    >
                    Edit Bid
                </flux:button>
            @endif
        @endcan
    </x-slot:actions>

    <livewire:bids.bid-create />

    <flux:separator variant="subtle" />

    {{-- DETAILS --}}
    <flux:table>
        <flux:table.rows>
            <flux:table.row>
                <flux:table.cell >Estimate</flux:table.cell>
                <flux:table.cell>{{money($finances['estimate'])}}</flux:table.cell>
            </flux:table.row>
            <flux:table.row>
                <flux:table.cell>Change Order</flux:table.cell>
                <flux:table.cell>{{money($finances['change_orders'])}}</flux:table.cell>
            </flux:table.row>
            <flux:table.row>
                <flux:table.cell>
                    <div class="flex items-center justify-between gap-2">
                        <span>Reimbursements</span>
                        @if((float) ($finances['reimbursments'] ?? 0) > 0)
                            <flux:button
                                icon="arrow-down-on-square"
                                size="xs"
                                variant="ghost"
                                class="shrink-0 !p-0"
                                wire:click="print_reimbursements"
                                tooltip="Download"
                            />
                        @endif
                    </div>
                </flux:table.cell>
                <flux:table.cell>{{money($finances['reimbursments'])}}</flux:table.cell>
            </flux:table.row>
            <flux:table.row>
                <flux:table.cell variant="strong">TOTAL PROJECT</flux:table.cell>
                <flux:table.cell variant="strong">{{money($finances['total_project'])}}</flux:table.cell>
            </flux:table.row>
            <flux:table.row>
                <flux:table.cell>Expenses</flux:table.cell>
                <flux:table.cell>{{money($finances['expenses'])}}</flux:table.cell>
            </flux:table.row>
            <flux:table.row>
                <flux:table.cell>Timesheets</flux:table.cell>
                <flux:table.cell>{{money($finances['timesheets'])}}</flux:table.cell>
            </flux:table.row>
            <flux:table.row>
                <flux:table.cell variant="strong">TOTAL COST</flux:table.cell>
                <flux:table.cell variant="strong">{{money($finances['total_cost'])}}</flux:table.cell>
            </flux:table.row>
            <flux:table.row>
                <flux:table.cell>Payments</flux:table.cell>
                <flux:table.cell>{{money($finances['payments'])}}</flux:table.cell>
            </flux:table.row>

            @if(in_array($this->project->latestStatus->title, ['Complete', 'Service Call']))
                <flux:table.cell variant="strong">PROFIT</flux:table.cell>
                <flux:table.cell variant="strong">{{money($finances['profit'])}}</flux:table.cell>
            @endif

            <flux:table.row>
                <flux:table.cell>Balance</flux:table.cell>
                <flux:table.cell>{{money($finances['balance'])}}</flux:table.cell>
            </flux:table.row>
        </flux:table.rows>
    </flux:table>
</x-island-card>


