<flux:card>
    {{-- HEADER - Keep outside accordion --}}
    <div class="flex justify-between">
        <flux:heading size="lg" class="mb-0 truncate">{!! $vendor->name !!}</flux:heading>

        @can('update', $vendor)
            @if(in_array($vendor->business_type, ["Sub", "DBA", "1099"]))
                @if($vendor->id != auth()->user()->vendor->id)
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
                    <flux:button
                        size="sm"
                        wire:click="$dispatchTo('vendors.vendor-create', 'editVendor', { vendor: {{$vendor->id}} })"
                        >
                        Edit
                    </flux:button>
                @endif
            @endif
        @endcan
    </div>
    {{-- SUBHEADING  --}}
    @if($registration)
        <flux:subheading>Confirm information.</flux:subheading>
    @else
        <flux:subheading>YTD Paid {{ money($vendor->ytd_expense_sum) }}</flux:subheading>
    @endif

    <flux:separator class="my-2"/>

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
                        <div class="col-span-2 text-sm text-gray-700">{!! $vendor->business_name !!}</div>
                    </div>

                    {{-- Vendor Type --}}
                    <div class="grid grid-cols-3 gap-4 py-2">
                        <flux:subheading class="text-sm font-medium text-gray-900">Vendor Type</flux:subheading>
                        <div class="col-span-2 text-sm text-gray-700">{{ $vendor->business_type }}</div>
                    </div>

                    {{-- Vendor Address --}}
                    @if($vendor->business_type != 'Retail')
                        <div class="grid grid-cols-3 gap-4 py-2">
                            <flux:subheading class="text-sm font-medium text-gray-900">Vendor Address</flux:subheading>
                            <div class="col-span-2 text-sm text-gray-700">
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
                            <div class="col-span-2 text-sm text-gray-700">{{ $vendor->business_phone }}</div>
                        </div>
                    @endif

                    {{-- Business Email --}}
                    @if(in_array($vendor->business_type, ["Sub", "DBA", "1099"]) && $vendor->business_email)
                        <div class="grid grid-cols-3 gap-4 py-2">
                            <flux:subheading class="text-sm font-medium text-gray-900">Business Email</flux:subheading>
                            <div class="col-span-2 text-sm text-gray-700">{{ $vendor->business_email }}</div>
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

    @can('update', $vendor)
        <livewire:vendors.vendor-create />
    @endcan
</flux:card>
