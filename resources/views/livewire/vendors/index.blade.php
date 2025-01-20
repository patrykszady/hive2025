<div class="max-w-2xl">
    <flux:card class="space-y-2">
        <div class="flex justify-between">
            <flux:heading size="lg">Filters</flux:heading>
            @can('create', App\Models\Expense::class)
                <flux:button wire:click="$dispatchTo('vendors.vendor-create', 'vendorModal')">Add New Vendor</flux:button>
            @endcan
        </div>

        <flux:separator variant="subtle" />

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <flux:input wire:model.live="business_name" label="Vendor Name" icon="magnifying-glass" placeholder="Search Vendors" />

            <flux:select wire:model.live="vendor_type" label="Business Type" wire:model="vendor_type" placeholder="Choose type...">
                <flux:option value="All">All Vendor Types</flux:option>
                <flux:option value="Sub">Subcontractor</flux:option>
                <flux:option value="Retail">Retail</flux:option>
                <flux:option value="1099">1099/Independent</flux:option>
                <flux:option value="DBA">DBA</flux:option>
            </flux:select>
        </div>
    </flux:card>

    <flux:card class="mt-4 space-y-2">
        <div>
            <flux:heading size="lg">Vendors</flux:heading>
        </div>

        <div class="space-y-2">
            <flux:table :paginate="$this->vendors">
                <flux:columns>
                    <flux:column sortable :sorted="$sortBy === 'business_name'" :direction="$sortDirection" wire:click="sort('business_name')">Vendor</flux:column>
                    <flux:column>Type</flux:column>
                    {{-- <flux:column sortable :sorted="$sortBy === 'expense_count'" :direction="$sortDirection" wire:click="sort('expense_count')">Score</flux:column> --}}
                </flux:columns>

                <flux:rows>
                    @foreach ($this->vendors as $vendor)
                        <flux:row :key="$vendor->id">
                            <flux:cell variant="strong"><a wire:navigate.hover href="{{route('vendors.show', $vendor->id)}}">{{$vendor->name}}</a></flux:cell>
                            <flux:cell><flux:badge color="green" inset="top bottom">{{$vendor->business_type}}</flux:badge></flux:cell>
                            {{-- <flux:cell>{{$vendor->expense_count}}</flux:cell> --}}
                        </flux:row>
                    @endforeach
                </flux:rows>
            </flux:table>

            {{-- VENDOR FORM MODAL --}}
            <livewire:vendors.vendor-create />
        </div>
    </flux:card>
</div>
