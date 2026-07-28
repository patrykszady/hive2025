{{-- Loading skeleton for the Payments card — the shared index-table skeleton,
     so it paints through the same card shell as the loaded table.

     In practice this only renders for the lazy cards embedded on projects.show
     (the standalone /payments route is a normal full-page component), and those
     cards are narrower than .index-table's 640px floor and drop the Project and
     Client columns — hence the view-aware column defs and the min-width reset. --}}
@php
    $tableView = $tableView ?? null;
    $isIndexView = ! in_array($tableView, ['projects.show', 'estimate.pdf'], true);
@endphp
<x-index-table.placeholder
    heading="Payments"
    :columns="\App\Livewire\Payments\PaymentsIndex::columnDefs($tableView)"
    :rows="$rows ?? ($isIndexView ? 5 : 3)"
    :compact="false"
    :floor="$isIndexView"
    wire:transition
/>
