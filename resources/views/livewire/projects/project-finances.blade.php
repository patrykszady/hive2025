{{-- PROJECT FINANCIALS --}}
<flux:card class="space-y-2">
    {{-- HEADING --}}
    <div class="flex justify-between">
        <flux:heading size="lg" class="mb-0">Project Finances</flux:heading>
        <flux:button
            wire:click="$dispatchTo('bids.bid-create', 'addBids', { vendor: {{auth()->user()->vendor->id}}, project: {{$project->id}} })"
            size="sm"
            >
            Edit Bid
        </flux:button>
    </div>

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
                    Reimbursements
                    <flux:badge
                        as="button" icon="arrow-down-on-square" size="lg" color="sky" inset="top bottom"
                        wire:click="print_reimbursements"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-50 disabled:pointer-events-none"
                        >
                        Download
                    </flux:badge>
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

            @if(in_array($this->project->latestStatus->title, ['Complete',  'Service Call', 'Service Call Complete']))
                <flux:table.cell variant="strong">PROFIT</flux:table.cell>
                <flux:table.cell variant="strong">{{money($finances['profit'])}}</flux:table.cell>
            @endif

            <flux:table.row>
                <flux:table.cell>Balance</flux:table.cell>
                <flux:table.cell>{{money($finances['balance'])}}</flux:table.cell>
            </flux:table.row>
        </flux:table.rows>
    </flux:table>
</flux:card>
