{{-- PROJECT FINANCIALS --}}
<flux:card class="space-y-2">
    {{-- HEADING --}}
    <div class="flex justify-between">
        <flux:heading size="lg" class="mb-0">Project Finances</flux:heading>
        <flux:button
            wire:click="$dispatchTo('bids.bid-create', 'addBids', { vendor: {{auth()->user()->vendor->id}}, project: {{$project->id}} })"
            >
            Edit Bid
        </flux:button>
    </div>

    <livewire:bids.bid-create />

    <flux:separator variant="subtle" />

    {{-- DETAILS --}}
    {{-- wire:loading should just target the Reimbursment search_li not the entire Proejct Finances card--}}
    <x-lists.details_list
        {{-- wire:loading
        wire:target="print_reimbursements" --}}
        {{-- wire:loading.attr="disabled"
        wire:loading.class="opacity-50 text-opacity-40" --}}
        >

        <x-lists.details_item title="Estimate" detail="{{money($finances['estimate'])}}" />
        <x-lists.details_item title="Change Order" detail="{{money($finances['change_orders'])}}" />

        <x-lists.details_item
            title="Reimbursements"
            detail="{{money($finances['reimbursments'])}}"
            wire:click="print_reimbursements"
        />

        {{-- <livewire:projects.project-show :project="$project" /> --}}

        <x-lists.details_item title="TOTAL PROJECT" detail="{{money($finances['total_project'])}}" />
        <x-lists.details_item title="Expenses" detail="{{money($finances['expenses'])}}" />
        <x-lists.details_item title="Timesheets" detail="{{money($finances['timesheets'])}}" />
        <x-lists.details_item title="TOTAL COST" detail="{{money($finances['total_cost'])}}" />
        <x-lists.details_item title="Payments" detail="{{money($finances['payments'])}}" />

        @if(in_array($this->project->last_status->title, ['Complete',  'Service Call', 'Service Call Complete']))
            <x-lists.details_item title="PROFIT" detail="{{money($finances['profit'])}}" />
        @endif

        <x-lists.details_item title="Balance" detail="{{money($finances['balance'])}}" />
    </x-lists.details_list>
</flux:card>
