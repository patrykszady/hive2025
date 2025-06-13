<flux:card class="space-y-6">
    {{-- HEADER - Keep outside accordion --}}
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0 flex-1">
            <flux:heading size="lg" class="truncate">{!! $vendor->name !!}</flux:heading>
            @if($registration)
                <flux:subheading>Confirm information.</flux:subheading>
            @endif
        </div>

        @can('update', $vendor)
            @if(in_array($vendor->business_type, ["Sub", "DBA", "1099"]))
                @if($vendor->id != auth()->user()->vendor->id)
                    <div class="flex-shrink-0">
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
                                    <flux:menu.item size="sm" wire:click="$dispatchTo('vendors.vendor-create', 'editVendor', { vendor: {{$vendor->id}} })">Edit</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </flux:button.group>
                    </div>
                @else
                    <div class="flex-shrink-0">
                        <flux:button
                            size="sm"
                            wire:click="$dispatchTo('vendors.vendor-create', 'editVendor', { vendor: {{$vendor->id}} })"
                            >
                            Edit Vendor
                        </flux:button>
                    </div>
                @endif
            @endif
        @endcan
    </div>

    <livewire:vendors.vendor-create />

    {{-- DETAILS LIST wrapped in accordion --}}
    <flux:accordion transition>
        <flux:accordion.item>
            <flux:accordion.heading>
                Vendor Details
            </flux:accordion.heading>
            <flux:accordion.content>
                <div class="divide-y divide-gray-200">
                    {{-- Business Name --}}
                    <div class="grid grid-cols-3 gap-4 py-2">
                        <flux:subheading class="text-sm font-medium text-gray-900">Business Name</flux:subheading>
                        <div class="col-span-2 text-sm text-gray-700 truncate">{!! $vendor->business_name !!}</div>
                    </div>

                    {{-- Vendor Type --}}
                    <div class="grid grid-cols-3 gap-4 py-2">
                        <flux:subheading class="text-sm font-medium text-gray-900">Vendor Type</flux:subheading>
                        <div class="col-span-2 text-sm text-gray-700 truncate">{{ $vendor->business_type }}</div>
                    </div>

                    {{-- Vendor Address --}}
                    @if($vendor->business_type != 'Retail')
                        <div class="grid grid-cols-3 gap-4 py-2">
                            <flux:subheading class="text-sm font-medium text-gray-900">Vendor Address</flux:subheading>
                            <div class="col-span-2 text-sm text-gray-700 truncate">
                                <a
                                    href="{{ $vendor->getAddressMapURI() }}"
                                    target="_blank"
                                    class="text-gray-700 hover:text-gray-900 hover:underline"
                                >
                                    {!! $vendor->full_address !!}
                                </a>
                            </div>
                        </div>
                    @endif

                    {{-- Business Phone --}}
                    @if(in_array($vendor->business_type, ["Sub", "DBA", "1099"]) && $vendor->business_phone)
                        <div class="grid grid-cols-3 gap-4 py-2">
                            <flux:subheading class="text-sm font-medium text-gray-900">Business Phone</flux:subheading>
                            <div class="col-span-2 text-sm text-gray-700 truncate">{{ $vendor->business_phone }}</div>
                        </div>
                    @endif

                    {{-- Business Email --}}
                    @if(in_array($vendor->business_type, ["Sub", "DBA", "1099"]) && $vendor->business_email)
                        <div class="grid grid-cols-3 gap-4 py-2">
                            <flux:subheading class="text-sm font-medium text-gray-900">Business Email</flux:subheading>
                            <div class="col-span-2 text-sm text-gray-700 truncate">{{ $vendor->business_email }}</div>
                        </div>
                    @endif
                </div>
            </flux:accordion.content>
        </flux:accordion.item>
    </flux:accordion>

    {{-- CONFIRM BUTTON --}}
    @if($registration)
        <div class="flex justify-end pt-4">
            <flux:button
                type="submit"
                variant="primary"
                wire:click="$dispatchTo('entry.vendor-registration', 'confirmProcessStep', { process_step: 'vendor_info' })"
            >
                Confirm Details
            </flux:button>
        </div>
    @endif
</flux:card>
