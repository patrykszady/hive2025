{{-- Same shape as the loaded component (wrapper div > card) so the swap is
     a patch, not a destroy-and-recreate. --}}
<div wire:transition>
<x-index-table.placeholder
    heading="Email Tracking"
    :columns="\App\Livewire\Projects\EmailTrackingTable::columnDefs(scoped: $scoped ?? false, narrow: (bool) ($embedded ?? $clientId ?? false))"
    :rows="$rows ?? \App\Livewire\Projects\EmailTrackingTable::placeholderRows()"
    :floor="! ($embedded ?? false)"
    :compact="false"
    {{-- The unscoped/client variants paginate; reserve that footer's height. --}}
    :footer="($footer ?? false)"
    wire:transition
>
    {{-- Same header control the loaded card shows once it has history, so the
         header row measures identically in both states. --}}
    <x-slot:badge>
        @if(($historyCount ?? 0) > 0)
            <flux:badge color="zinc" size="sm">{{ $historyCount + 1 }}</flux:badge>
        @endif
    </x-slot:badge>
    <x-slot:actions>
        @if(($historyCount ?? 0) > 0)
            <div class="flex items-center text-zinc-500 dark:text-zinc-400">
                <flux:icon.chevron-down variant="mini" />
            </div>
        @endif
    </x-slot:actions>
</x-index-table.placeholder>
</div>
