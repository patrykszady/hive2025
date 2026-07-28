{{-- Wrapped to mirror the loaded component's root (div > card): when the
     shapes differ Livewire destroys and recreates the card instead of patching
     it, which reads as a flash. --}}
<div wire:transition>
{{-- Loading skeleton for the Estimates card — the shared index-table skeleton,
     so it renders through the same card shell as the loaded table.

     In practice this only paints for the lazy card embedded on projects.show
     (the standalone /estimates route is a normal full-page component), and that
     card is narrower than .index-table's 640px floor and drops the Client
     column — hence the view-aware column defs and the min-width reset. --}}
@php
    $tableView = $tableView ?? 'estimates.index';
    $isIndexView = $tableView === 'estimates.index';
@endphp
<x-index-table.placeholder
    heading="Estimates"
    :columns="\App\Livewire\Estimates\EstimatesIndex::columnDefs($tableView)"
    :rows="$rows ?? ($isIndexView ? 5 : 1)"
    :row-heights="$rowHeights ?? null"
    {{-- The embedded card renders no column header row (estimates/index.blade.php
         gates <flux:table.columns> on the standalone view). --}}
    :show-columns="$isIndexView"
    :compact="false"
    :floor="$isIndexView"
    wire:transition
/>
</div>
