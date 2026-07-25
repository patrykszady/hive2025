{{-- No @blaze: its slot compiler drops the @isset guard around the
     forwarded toolbar slot (undefined-variable at runtime), and this view
     renders once per lazy load anyway. --}}
{{-- Loading skeleton for an index-page main table — single source of truth for
     EVERY index page's placeholder. Renders the same <x-index-table> shell and
     the same flux:table classes/column widths as the real card, so the skeleton
     and the loaded table are pixel-identical apart from the shimmer.

     Props:
     - heading  — card heading (same string the real card uses)
     - columns  — the table's column defs: [['label' => 'Address',
                  'width' => 'w-[30%] min-w-0',
                  'skeleton' => 'line'|'badge'|'two-line'|'none',
                  'skeletonWidth' => 'w-32'], ...]. Pass the SAME array the real
                  table renders its headers from (a static method on the
                  Livewire component) so widths can never drift.
     - rows     — how many skeleton rows (default 5). Pass the REAL count when
                  it's cheap to know (a COUNT is far cheaper than the paginated
                  query the skeleton stands in for): 0 renders the card chrome
                  alone, so an empty card never flashes fake rows first.
     - compact  — match the real table's compact-table row rhythm (default true;
                  the real tables use it wherever rows hold selects) --}}
@props([
    'heading' => null,
    'actions' => null,
    'toolbar' => null,
    {{-- false for EMBEDDED cards (narrow columns on show pages): drops the
         640px .index-table floor so the skeleton can't force a horizontal
         scrollbar, exactly like the real embedded tables use min-w-0. --}}
    'floor' => true,
    {{-- Optional lower floor (e.g. '600px' on the max-w-2xl /vendors page) —
         mirrors a real table's style="--index-table-min: ..." override. --}}
    'floorMin' => null,
    'columns' => [],
    'rows' => 5,
    'compact' => true,
])
@php($skeletonRows = max(0, min((int) $rows, 15)))
<x-index-table :heading="$heading" :skeleton="true" {{ $attributes }}>
    {{-- Always forwarded (Blade hoists <x-slot> out of conditionals, so an
         @isset guard here breaks): real controls, not skeletons, so the card's
         buttons and search box are there from the first paint instead of
         popping in when the rows arrive. x-index-table ignores them when
         empty. --}}
    <x-slot:actions>{{ $actions }}</x-slot:actions>
    <x-slot:toolbar>{{ $toolbar }}</x-slot:toolbar>
    @if($skeletonRows > 0)
    <flux:table
        class="{{ $compact ? 'compact-table ' : '' }}{{ $floor ? 'index-table' : 'table-fixed min-w-0 w-full' }} [:where(&)]:p-0 [:where(&)]:space-y-0"
        :style="$floor && $floorMin ? '--index-table-min: '.$floorMin : null"
    >
        <flux:table.columns>
            @foreach($columns as $column)
                <flux:table.column class="{{ $column['width'] ?? '' }}">{{ $column['label'] ?? '' }}</flux:table.column>
            @endforeach
        </flux:table.columns>

        <flux:table.rows>
            <flux:skeleton.group animate="shimmer">
                @for($i = 0; $i < $skeletonRows; $i++)
                    <flux:table.row>
                        @foreach($columns as $column)
                            <flux:table.cell class="{{ $column['width'] ?? '' }}">
                                @if(($column['skeleton'] ?? 'line') === 'two-line')
                                    {{-- Cells that stack a title over a smaller sub-line
                                         (lien waivers' project name + address): 20px + 16px,
                                         the same 36px content box the real cell has. --}}
                                    <div>
                                        <flux:skeleton.line class="{{ $column['skeletonWidth'] ?? 'w-28' }}" />
                                        <flux:skeleton.line class="{{ $column['skeletonSubWidth'] ?? 'w-20' }} h-4" />
                                    </div>
                                @elseif(($column['skeleton'] ?? 'line') === 'badge')
                                    {{-- Compact tables put a flux:select in this column (32px tall);
                                         non-compact ones a badge (20px). Match it so the card doesn't
                                         resize when the real rows swap in. --}}
                                    <flux:skeleton class="{{ $compact ? 'h-8 w-24 rounded-lg' : 'h-5 w-16 rounded-full' }}" />
                                @elseif(($column['skeleton'] ?? 'line') !== 'none')
                                    <flux:skeleton.line class="{{ $column['skeletonWidth'] ?? 'w-24' }}" />
                                @endif
                            </flux:table.cell>
                        @endforeach
                    </flux:table.row>
                @endfor
            </flux:skeleton.group>
        </flux:table.rows>
    </flux:table>
    @endif
</x-index-table>
