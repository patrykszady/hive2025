<div class="mt-4">
    <x-index-table.placeholder
        heading="Email Tracking"
        :columns="\App\Livewire\Vendors\VendorPaymentEmailTrackingTable::columnDefs()"
        :rows="\App\Livewire\Vendors\VendorPaymentEmailTrackingTable::placeholderRows()"
        :floor="! ($scopedToVendor ?? false)"
        :floor-min="($scopedToVendor ?? false) ? null : '600px'"
        :compact="false"
        wire:transition
    />
</div>
