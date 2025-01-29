<div>
    <x-lists.details_card>
        {{-- HEADING --}}
        <x-slot:heading>
            <div>
                <flux:heading size="lg" class="mb-0">Vendor Details</flux:heading>
                @if($registration)
                    <flux:subheading>Confirm information.</flux:subheading>
                @endif
            </div>

            @can('update', $vendor)
                @if(in_array($vendor->business_type, ["Sub", "DBA", "1099"]))
                    @if($vendor->id != auth()->user()->vendor->id)
                        <flux:button.group>
                            <flux:button
                                size="sm"
                                href="{{route('vendors.payment', $vendor->id)}}"
                                >
                                Vendor Payment
                            </flux:button>
                            <flux:dropdown position="bottom" align="end">
                                <flux:button size="sm" icon-trailing="chevron-down"></flux:button>

                                <flux:menu>
                                    <flux:menu.item size="sm" wire:click="$dispatchTo('vendors.vendor-create', 'editVendor', { vendor: {{$vendor->id}} })">Edit</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </flux:button.group>
                    @else
                        <flux:button
                            size="sm"
                            wire:click="$dispatchTo('vendors.vendor-create', 'editVendor', { vendor: {{$vendor->id}} })"
                            >
                            Edit Vendor
                        </flux:button>
                    @endif
                @endif
            @endcan
        </x-slot>
        <livewire:vendors.vendor-create />

        {{-- DETAILS --}}
        <x-lists.details_list>
            <x-lists.details_item title="Business Name" detail="{!!$vendor->business_name!!}" />
            <x-lists.details_item title="Vendor Type" detail="{{$vendor->business_type}}" />
            @if($vendor->business_type != 'Retail')
                <x-lists.details_item title="Vendor Address" detail="{!!$vendor->full_address!!}" href="{{$vendor->getAddressMapURI()}}" target="_blank" />
            @endif
            @if(in_array($vendor->business_type, ["Sub", "DBA", "1099"]))
                <x-lists.details_item title="Business Phone" detail="{{$vendor->business_phone}}" />
                <x-lists.details_item title="Business Email" detail="{{$vendor->business_email}}" />
            @endif
        </x-lists.details_list>

        {{-- <flux:separator /> --}}

        <div class="flex space-x-2">
            <flux:spacer />

            <div
                x-data="{ vendor_info: @entangle('registration') }"
                x-show="vendor_info"
                x-transition
                >
                <flux:button type="submit" variant="primary" wire:click="$dispatchTo('entry.vendor-registration', 'confirmProcessStep', { process_step: 'vendor_info' })">
                    Confirm Details
                </flux:button>
            </div>
        </div>
    </x-lists.details_card>
</div>
