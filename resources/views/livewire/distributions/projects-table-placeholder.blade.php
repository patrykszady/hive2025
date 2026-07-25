<x-index-table.placeholder
    heading="Projects {{ $type }} Distributions"
    :columns="\App\Livewire\Distributions\DistributionProjectsTable::columnDefs($type)"
    :rows="\App\Livewire\Distributions\DistributionProjectsTable::placeholderRows()"
    :compact="false"
/>
