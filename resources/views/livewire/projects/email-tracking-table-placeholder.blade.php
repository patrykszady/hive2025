<x-index-table.placeholder
    heading="Email Tracking"
    :columns="\App\Livewire\Projects\EmailTrackingTable::columnDefs(scoped: $scoped ?? false)"
    :floor="! ($scoped ?? false)"
    :compact="false"
    wire:transition
/>
