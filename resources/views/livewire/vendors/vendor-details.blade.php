<x-details.card
    :title="$titleOverride ?? $vendor->name"
    :subheading="!($nonLivewire ?? false) ? ($this->view == 'vendor_registration' ? 'Confirm Company information.' : (auth()->user()->can('update', $vendor) && $vendor->id != auth()->user()->vendor?->id ? 'YTD Paid: ' . money($vendor->ytd_expense_sum) : '')) : ''"
    :canEdit="!($nonLivewire ?? false) ? auth()->user()->can('update', $vendor) : null"
    :expanded="$expanded ?? true"
    :nonLivewire="$nonLivewire ?? false"
    >
    @php
        $disableCloak = true;
    @endphp
    @unless($nonLivewire ?? false)
        <x-slot:header_buttons>
            @if($vendor->id != auth()->user()->vendor->id && $vendor->business_type != 'Retail')
                {{-- Show payment button + dropdown for other non-retail vendors --}}
                <flux:button.group class="w-full sm:w-auto">
                    <flux:button size="sm" href="{{route('vendors.payment', $vendor->id)}}" wire:navigate.hover class="w-full sm:w-auto">
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
        @if(($nonLivewire ?? false) && isset($vendorLogoDataUrl) && is_string($vendorLogoDataUrl) && $vendorLogoDataUrl !== '')
            <div class="py-2 flex justify-start">
                <img
                    src="{{ $vendorLogoDataUrl }}"
                    alt="{{ $vendor->business_name }} logo"
                    class="h-24 w-auto object-contain"
                />
            </div>
        @endif

        <x-details.row title="Name" :content="$vendor->business_name" :no-cloak="$disableCloak" />
        {{-- The accessor falls back to the full name — only show a REAL stored short name. --}}
        @php($storedShortName = data_get($vendor->options, 'short_name'))
        @if(is_string($storedShortName) && $storedShortName !== '')
            <x-details.row title="Short Name" :content="$storedShortName" :no-cloak="$disableCloak" />
        @endif
        @unless(in_array('type', $hide ?? []))
            <x-details.row title="Type" :content="$vendor->business_type" :no-cloak="$disableCloak" />
        @endunless

        @unless($vendor->business_type == 'Retail')
            @if($vendor->full_address)
                <x-details.row title="Address" :content="$vendor->full_address" :href="$vendor->getAddressMapURI()" :copyable="!($nonLivewire ?? false)" :no-cloak="$disableCloak" />
            @endif
            @if($vendor->business_phone)
                <x-details.row title="Phone" :content="$vendor->business_phone" :copyable="!($nonLivewire ?? false)" :no-cloak="$disableCloak" />
            @endif
            @if($vendor->business_email)
                <x-details.row title="Email" :content="$vendor->business_email" :copyable="!($nonLivewire ?? false)" :no-cloak="$disableCloak" />
            @endif
            @if($vendor->business_website)
                @php($websiteUrl = preg_match('#^https?://#i', $vendor->business_website) ? $vendor->business_website : 'https://' . $vendor->business_website)
                <x-details.row title="Website" :content="$vendor->business_website" :href="$websiteUrl" :copyable="!($nonLivewire ?? false)" :no-cloak="$disableCloak" />
            @endif
        @endunless
        @unless($nonLivewire ?? false)
            {{-- Modal host only (teleports to body) — lives in the details
                 slot so the card has no footer and the last row sits flush. --}}
            @can('update', $vendor)
                <livewire:vendors.vendor-create :is-registration="$this->view == 'vendor_registration'" />
            @endcan
        @endunless
    </x-slot:details>
</x-details.card>