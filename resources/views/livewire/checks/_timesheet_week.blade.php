{{-- One week of timesheets settled by this check, as a labelled section inside
     the person's card, so several weeks live under one card instead of a card
     nested in a card. Not collapsible: the card's own accordion covers it, and
     a second chevron on the row just competes with it.

     Expects: $heading, $week (collection of timesheets), and optionally $href
     (the timesheet this week links to). --}}
<x-card-group
    :heading="$heading"
    :href="$href ?? null"
    :collapsible="false"
    wire:key="week-{{ $week->first()->id }}"
>
    <x-slot:badge>
        <flux:badge color="zinc" size="sm" inset="top bottom">{{ money($week->sum('amount')) }}</flux:badge>
    </x-slot:badge>

    <x-index-table.table :columns="\App\Livewire\Checks\CheckShow::timesheetColumnDefs()">
        @foreach($week as $week_timesheet)
            <flux:table.row :key="$week_timesheet->id">
                <flux:table.cell variant="strong" class="whitespace-nowrap">
                    <x-table-link
                        :href="route('timesheets.show', $week_timesheet->id)"
                        :label="money($week_timesheet->amount)"
                    />
                </flux:table.cell>
                <flux:table.cell class="whitespace-nowrap">{{ $week_timesheet->hours }}</flux:table.cell>
                <flux:table.cell class="min-w-0">
                    <x-table-link
                        :href="route('projects.show', $week_timesheet->project->id)"
                        :label="$week_timesheet->project->name"
                    />
                </flux:table.cell>
            </flux:table.row>
        @endforeach
    </x-index-table.table>
</x-card-group>
