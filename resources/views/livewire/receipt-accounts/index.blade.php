<flux:card class="{{$view == NULL ? 'w-full px-4 sm:px-6 lg:max-w-xl lg:px-8 pb-5 mb-1' : ''}}">
    <div class="mb-4">
        <flux:heading size="lg">Receipt Accounts</flux:heading>
        <flux:subheading>Vendors you are able to automatically receive Receipts for are below.</flux:subheading>
    </div>

    <flux:separator variant="subtle" />

    <flux:table>
        <flux:columns>
            <flux:column>Vendor</flux:column>
            <flux:column>Project</flux:column>
            <flux:column>Details</flux:column>
        </flux:columns>

        <flux:rows>
            @foreach ($vendors as $vendor)
                <flux:row :key="$vendor->id">
                    <flux:cell
                        wire:click="$dispatchTo('receipt-accounts.receipt-account-vendor-create', 'editReceiptVendor', { vendor_id: {{$vendor->id}} })"
                        variant="strong"
                        class="cursor-pointer"
                        >
                        {{ $vendor->name }}
                    </flux:cell>
                    <flux:cell>{{ !isset($vendor->receipt_account) ? '' : ($vendor->receipt_account->distribution_id ? $vendor->receipt_account->distribution->name : 'NO PROJECT') }}</flux:cell>
                    <flux:cell>
                        <flux:badge size="sm" :color="$vendor->status == 'Active' ? 'green' : ($vendor->status == 'Disabled' ? 'red' : 'indigo')" inset="top bottom">
                            {{ $vendor->type }}
                        </flux:badge>
                    </flux:cell>
                </flux:row>
            @endforeach
        </flux:rows>
    </flux:table>

    <livewire:receipt-accounts.receipt-account-vendor-create :vendors="$vendors"/>
</flux:card>
