{{-- The table chrome inside an <x-index-table> card — single source of truth
     for embedded (non-paginated) card tables: fixed layout so the columnDefs
     percentage widths actually hold, full width (table-layout: fixed is ignored
     when the table sizes to `auto`), and no inner padding/spacing of its own —
     the card owns that.

     Columns come from a component's static columnDefs(); rows are the slot. --}}
@props([
    'columns' => [],
])
<flux:table {{ $attributes->class('table-fixed min-w-0 w-full [:where(&)]:p-0 [:where(&)]:space-y-0') }}>
    <flux:table.columns>
        @foreach($columns as $column)
            <flux:table.column class="{{ $column['width'] }}">{{ $column['label'] }}</flux:table.column>
        @endforeach
    </flux:table.columns>

    <flux:table.rows>
        {{ $slot }}
    </flux:table.rows>
</flux:table>
