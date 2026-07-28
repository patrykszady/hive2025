{{-- Skeleton for the Vendor Documents card — the SHARED index-table skeleton,
     same shell and same columnDefs the loaded table renders from, with a real
     row count so it paints exactly what will arrive (0 rows = header only). --}}
<div wire:transition>
    <x-index-table.placeholder
        :heading="$view ? 'Vendor Documents' : ($vendor->name ?? 'Vendor Documents')"
        :columns="\App\Livewire\VendorDocs\VendorDocsCard::columnDefs()"
        :rows="$rows ?? \App\Livewire\VendorDocs\VendorDocsCard::placeholderRows()"
        :floor="false"
        :compact="false"
    />
</div>
