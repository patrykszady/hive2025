<x-index-table.placeholder
    heading="Projects"
    :columns="\App\Livewire\Projects\ProjectsTable::columnDefs($tableView ?? null)"
    :rows="$placeholderRows ?? 5"
    :floor="($tableView ?? null) !== 'clients.index'"
    :compact="true"
    wire:transition
>
    <x-slot:actions>
        @include('livewire.projects.partials.projects-table-actions')
    </x-slot:actions>
</x-index-table.placeholder>
