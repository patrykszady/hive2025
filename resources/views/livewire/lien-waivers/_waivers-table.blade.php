{{-- The ONLY waivers-table markup for a project card. Rendered for each draw
     and for the ungrouped "Other waivers" group, so the header can't drift
     between them. Widths come from LienWaivers\Index::columnDefs(scoped: true).

     Expects: $columns, $waivers, and optionally $statement (the draw's GCSS row
     renders first). --}}
<x-index-table.table :columns="$columns">
    @isset($statement)
        @include('livewire.lien-waivers._statement-row', ['statement' => $statement, 'columns' => $columns])
    @endisset

    @foreach($waivers as $waiver)
        @include('livewire.lien-waivers._waiver-row', ['waiver' => $waiver, 'columns' => $columns])
    @endforeach
</x-index-table.table>
