<x-details.card
    :title="$vendor->name"
    {{-- SUBHEADING  --}}
    {{-- @if($registration)
        <flux:subheading>Confirm information.</flux:subheading>
    @else
        <flux:subheading>YTD Paid {{ money($vendor->ytd_expense_sum) }}</flux:subheading>
    @endif --}}
    :subheading="'YTD Paid: ' . money($vendor->ytd_expense_sum)"
    :canEdit="auth()->user()->can('update', $vendor)"
    >
    <x-slot:header_buttons>
        @if($vendor->business_type != 'Retail')
            @if($vendor->id != auth()->user()->primary_vendor->id)
                {{-- Show payment button + dropdown for other vendors --}}
                <flux:button.group>
                    <flux:button
                        size="sm"
                        href="{{route('vendors.payment', $vendor->id)}}"
                        >
                        Make Payment
                    </flux:button>
                    <flux:dropdown position="bottom" align="end">
                        <flux:button size="sm" icon-trailing="chevron-down"></flux:button>

                        <flux:menu>
                            <flux:menu.item wire:click="$dispatchTo('vendors.vendor-create', 'editVendor', { vendor: {{$vendor->id}} })">Edit</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </flux:button.group>
            @else
                {{-- Show simple edit button for own/ primary_vendor --}}
                <flux:button
                    size="sm"
                    wire:click="$dispatchTo('vendors.vendor-create', 'editVendor', { vendor: {{$vendor->id}} })"
                    >
                    Edit
                </flux:button>
            @endif
        @endif
    </x-slot:header_buttons>
    
    <x-slot:details>
        {{-- Business Name --}}
        <x-details.row 
            title="Name" 
            :content="$vendor->business_name"
        />

        {{-- Vendor Type --}}
        <x-details.row 
            title="Type" 
            :content="$vendor->business_type"
        />

        {{-- Vendor Address with Link --}}
        @if($vendor->business_type != 'Retail')
            <x-details.row 
                title="Address" 
                :content="$vendor->full_address" 
                :href="$vendor->getAddressMapURI()"
                :copyable="true"
            />
        @endif

        {{-- Business Phone --}}
        @if($vendor->business_type != 'Retail' && $vendor->business_phone)
            <x-details.row 
                title="Phone" 
                :content="$vendor->business_phone"
                :copyable="true"
            />
        @endif

        {{-- Business Email --}}
        @if($vendor->business_type != 'Retail' && $vendor->business_email)
            <x-details.row 
                title="Email" 
                :content="$vendor->business_email"
                :copyable="true"
            />
        @endif
    </x-slot:details>

    {{-- @if($registration)
        <x-slot:confirmButton>
            <flux:button
                type="submit"
                variant="primary"
                wire:click="$dispatchTo('entry.vendor-registration', 'confirmProcessStep', { process_step: 'vendor_info' })"
            >
                Confirm Details
            </flux:button>
        </x-slot:confirmButton>
    @endif --}}
    
    <x-slot:footer>
        @can('update', $vendor)
            <livewire:vendors.vendor-create />
        @endcan
    </x-slot:footer>
</x-details.card>