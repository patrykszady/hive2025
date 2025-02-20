<flux:card class="{{$view == NULL ? 'w-full px-4 sm:px-6 lg:max-w-xl lg:px-8 pb-5 mb-1' : ''}}">
    <div class="mb-4">
        <flux:heading size="lg">Receipt Accounts</flux:heading>
        <flux:subheading>Vendors you are able to automatically receive Receipts for are below.</flux:subheading>
    </div>

    <flux:separator variant="subtle" />

    <flux:table>
        <flux:table.columns>
            <flux:table.column>Vendor</flux:table.column>
            <flux:table.column>Project</flux:table.column>
            <flux:table.column>Details</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($vendors as $vendor)
                <flux:table.row :key="$vendor->id">
                    <flux:table.cell
                        wire:click="$dispatchTo('receipt-accounts.receipt-account-vendor-create', 'editReceiptVendor', { vendor_id: {{$vendor->id}} })"
                        variant="strong"
                        class="cursor-pointer"
                        >
                        {{ $vendor->name }}
                    </flux:table.cell>
                    <flux:table.cell>{{ !isset($vendor->receipt_account) ? '' : ($vendor->receipt_account->distribution_id ? $vendor->receipt_account->distribution->name : 'NO PROJECT') }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$vendor->status == 'Active' ? 'green' : ($vendor->status == 'Disabled' ? 'red' : 'indigo')" inset="top bottom">
                            {{ $vendor->type }}
                        </flux:badge>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <livewire:receipt-accounts.receipt-account-vendor-create :vendors="$vendors"/>
</flux:card>
