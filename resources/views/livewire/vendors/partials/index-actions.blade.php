{{-- Card actions — included by BOTH the real card and its loading
     skeleton so the buttons are present from the first paint. --}}
@can('create', App\Models\Vendor::class)
    <flux:button size="sm" wire:click="$dispatchTo('vendors.vendor-create', 'newVendor')">Add New Vendor</flux:button>
@endcan
