{{-- The ONLY waivers-table markup for a project card. Rendered for each draw
     and for the ungrouped "Other waivers" group, so the header can't drift
     between them. Widths come from LienWaivers\Index::columnDefs(scoped: true).

     Expects: $columns, $waivers, and optionally $statement (the draw's GCSS row
     renders first). --}}
<flux:table class="table-fixed min-w-0 w-full [:where(&)]:p-0 [:where(&)]:space-y-0">
    <flux:table.columns>
        @foreach($columns as $column)
            <flux:table.column class="{{ $column['width'] }}">{{ $column['label'] }}</flux:table.column>
        @endforeach
    </flux:table.columns>

    <flux:table.rows>
        @isset($statement)
            @include('livewire.lien-waivers._statement-row', ['statement' => $statement, 'columns' => $columns])
        @endisset

        @foreach($waivers as $waiver)
            @include('livewire.lien-waivers._waiver-row', ['waiver' => $waiver, 'columns' => $columns])
        @endforeach
    </flux:table.rows>
</flux:table>
