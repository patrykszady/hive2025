<x-details.card
    :title="$titleOverride ?? $vendor->name"
    :subheading="!($nonLivewire ?? false) ? ($this->view == 'vendor_registration' ? 'Confirm Company information.' : (auth()->user()->can('update', $vendor) && $vendor->id != auth()->user()->vendor?->id ? 'YTD Paid: ' . money($vendor->ytd_expense_sum) : '')) : ''"
    :canEdit="!($nonLivewire ?? false) ? auth()->user()->can('update', $vendor) : null"
    :expanded="$expanded ?? true"
    :nonLivewire="$nonLivewire ?? false"
    >
    @unless($nonLivewire ?? false)
        <x-slot:header_buttons>
            @if($vendor->id != auth()->user()->vendor->id && $vendor->business_type != 'Retail')
                {{-- Show payment button + dropdown for other non-retail vendors --}}
                <flux:button.group>
                    <flux:button size="sm" href="{{route('vendors.payment', $vendor->id)}}">
                        Make Payment
                    </flux:button>
                    <flux:dropdown position="bottom" align="end">
                        <flux:button size="sm" icon-trailing="chevron-down"></flux:button>
                        <flux:menu>
                            <flux:menu.item wire:click="$dispatchTo('vendors.vendor-create', 'editVendor', { vendor: {{$vendor->id}} })">
                                Edit
                            </flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </flux:button.group>
            @else
                {{-- Show simple edit button for own vendor or retail vendors --}}
                <flux:button
                    size="sm"
                    wire:click="$dispatchTo('vendors.vendor-create', 'editVendor', { vendor: {{$vendor->id}} })"
                >
                    Edit
                </flux:button>
            @endif
        </x-slot:header_buttons>
    @endunless
    
    <x-slot:details>

        <x-details.row title="Name" :content="$vendor->business_name" :no-cloak="$nonLivewire ?? false" />
        @unless(in_array('type', $hide ?? []))
            <x-details.row title="Type" :content="$vendor->business_type" :no-cloak="$nonLivewire ?? false" />
        @endunless

        @unless($vendor->business_type == 'Retail')
            @if($vendor->full_address)
                <x-details.row title="Address" :content="$vendor->full_address" :href="$vendor->getAddressMapURI()" :copyable="!($nonLivewire ?? false)" :no-cloak="$nonLivewire ?? false" />
            @endif
            @if($vendor->business_phone)
                <x-details.row title="Phone" :content="$vendor->business_phone" :copyable="!($nonLivewire ?? false)" :no-cloak="$nonLivewire ?? false" />
            @endif
            @if($vendor->business_email)
                <x-details.row title="Email" :content="$vendor->business_email" :copyable="!($nonLivewire ?? false)" :no-cloak="$nonLivewire ?? false" />
            @endif
        @endunless
    </x-slot:details>
    
    @unless($nonLivewire ?? false)
        <x-slot:footer>
            <div x-data="{ isConfirmed: false }">
                @if($this->view == 'vendor_registration' && ! data_get($vendor->registration, 'vendor_info', false))
                    <flux:button
                        variant="primary"
                        x-show="!isConfirmed"
                        wire:click="$dispatchTo('entry.vendor-registration', 'confirmProcess', { process_step: 'vendor_info' }); $nextTick(() => { isConfirmed = true })"
                    >
                        Confirm Vendor Details
                    </flux:button>
                @endif

                @can('update', $vendor)
                    <livewire:vendors.vendor-create :is-registration="$this->view == 'vendor_registration'" />
                @endcan
            </div>
        </x-slot:footer>
    @endunless
</x-details.card>