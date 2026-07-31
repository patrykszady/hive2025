{{-- The ONLY email-tracking table markup. Rendered three ways from one source:
     the latest row of an embedded card (header), its collapsed history
     (colgroup — a hidden header row would collapse and break table-fixed's
     column widths), and the standalone paginated index (header + 640px floor
     for mobile scroll). Widths always come from EmailTrackingTable::columnDefs.

     Expects: $columns, $events, $header (bool), $floor (bool), $projectId,
     $shortProjectName, $shortDate (embedded cards: "44m ago", so the narrow
     Date column never overflows into a horizontal scrollbar). --}}
<flux:table class="{{ ($floor ?? false) ? 'index-table' : 'narrow-table table-fixed min-w-0 w-full' }} [:where(&)]:p-0 [:where(&)]:space-y-0">
    @if($header ?? true)
        <flux:table.columns>
            @foreach($columns as $trackingColumn)
                <flux:table.column class="{{ $trackingColumn['width'] }}">{{ $trackingColumn['label'] }}</flux:table.column>
            @endforeach
        </flux:table.columns>
    @else
        <colgroup>
            @foreach($columns as $trackingColumn)
                <col class="{{ $trackingColumn['width'] }}">
            @endforeach
        </colgroup>
    @endif

    <flux:table.rows>
        @forelse($events as $event)
            @include('livewire.projects.partials.email-tracking-row', [
                'event' => $event,
                'projectId' => $projectId ?? null,
                'shortProjectName' => $shortProjectName ?? false,
                'shortDate' => $shortDate ?? false,
            ])
        @empty
            <flux:table.row>
                <flux:table.cell :colspan="count($columns)" class="text-center text-gray-500">No tracking events found.</flux:table.cell>
            </flux:table.row>
        @endforelse
    </flux:table.rows>
</flux:table>
