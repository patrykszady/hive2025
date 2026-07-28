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
    {{-- false for embedded tables that render no <flux:table.columns> header
         row (e.g. Estimates on projects.show) — painting one makes the skeleton
         a header taller than the card it stands in for. --}}
    'showColumns' => true,
    {{-- Optional per-row pixel heights, when the component can cheaply know
         that its rows differ (e.g. only inactive estimates carry an actions
         cluster). Index i applies to row i; missing entries fall back to the
         column skeletons' natural height. --}}
    'rowHeights' => null,
    'rows' => 5,
    'compact' => true,
    {{-- true when the loaded card will render a pagination footer: the skeleton
         reserves the same height so the card doesn't grow when data arrives.
         Pass the same condition the real card passes to :paginator. --}}
    'footer' => false,
    {{-- Or just declare the table's page size and let the shared rule decide: a
         skeleton painting a FULL page is exactly the case where the loaded card
         paginates. Needs no extra query — the component already knows its page
         size (its placeholderRows()). --}}
    'pageSize' => null,
])
@php
    // Ceiling is a runaway guard only. Callers already cap `rows` at their own
    // page size, so clamping lower than that silently shortchanges cards that
    // legitimately show more (projects.show Payments paginates at 25 — a 15-row
    // clamp left its skeleton 4 rows / 180px short).
    $skeletonRows = max(0, min((int) $rows, 30));
    // A skeleton painting a FULL page is exactly when the loaded card paginates.
    $reserveFooter = $footer || ($pageSize && $skeletonRows >= (int) $pageSize);
@endphp
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
        @if($showColumns)
            <flux:table.columns>
                @foreach($columns as $column)
                    <flux:table.column class="{{ $column['width'] ?? '' }}">{{ $column['label'] ?? '' }}</flux:table.column>
                @endforeach
            </flux:table.columns>
        @endif

        <flux:table.rows>
            <flux:skeleton.group animate="shimmer">
                @for($i = 0; $i < $skeletonRows; $i++)
                    {{-- Bound :style (an @if inside a component tag's attribute
                         list does not compile). --}}
                    <flux:table.row :style="isset($rowHeights[$i]) ? 'height: '.$rowHeights[$i].'px' : null">
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
                                    {{-- skeletonHeight: match a cell whose real
                                         content is taller than a text line
                                         (badge + action cluster), so the row
                                         height can't drift. --}}
                                    <flux:skeleton.line class="{{ $column['skeletonWidth'] ?? 'w-24' }} {{ $column['skeletonHeight'] ?? '' }}" />
                                @endif
                            </flux:table.cell>
                        @endforeach
                    </flux:table.row>
                @endfor
            </flux:skeleton.group>
        </flux:table.rows>
    </flux:table>
    @endif
    @if($reserveFooter)
        {{-- Mirrors flux:pagination's own box: pt-3 + a 28px control row + the
             top border = 41px inside .index-table-footer's padding (53px), so
             the card doesn't grow when the real pager swaps in. --}}
        <div class="index-table-footer">
            <div class="pt-3 border-t border-zinc-100 dark:border-zinc-700">
                <flux:skeleton.group animate="shimmer">
                    <div class="flex h-7 items-center justify-between">
                        <flux:skeleton.line class="w-40" />
                        <flux:skeleton class="h-7 w-32 rounded-lg" />
                    </div>
                </flux:skeleton.group>
            </div>
        </div>
    @endif
</x-index-table>
