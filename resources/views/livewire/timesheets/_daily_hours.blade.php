{{-- One day's hours. Uses the shared index-table card (collapsible header +
     columnDefs widths + x-table-link), so it matches every other card in the
     app instead of hand-rolling a flux:accordion inside a raw flux:card. --}}
@php($dailyColumns = \App\Livewire\Timesheets\TimesheetShow::dailyColumnDefs())
<x-index-table
    heading="{{ \Carbon\Carbon::parse($date)->format('D, M j') }}"
    x-data="{ open: true }"
    :collapsible="true"
>
    <x-slot:badge>
        <flux:badge color="green" size="sm" inset="top bottom" icon="clock">{{ $hours->sum('hours') }} Hours</flux:badge>
    </x-slot:badge>

    {{-- Same collapse affordance as Email Tracking / Lien Waivers: the whole
         heading row toggles, the chevron shows which way. --}}
    <x-slot:actions>
        <x-card-collapse-toggle label="Toggle day" />
    </x-slot:actions>

    <x-index-table.table :columns="$dailyColumns">
        @foreach($hours as $project_name => $daily_project)
            <flux:table.row :key="$daily_project->id">
                <flux:table.cell variant="strong" class="whitespace-nowrap">{{ $daily_project->hours }}</flux:table.cell>
                <flux:table.cell class="min-w-0">
                    <x-table-link
                        :href="route('projects.show', $daily_project->project->id)"
                        :label="$daily_project->project->name"
                    />
                </flux:table.cell>
            </flux:table.row>
        @endforeach
    </x-index-table.table>
</x-index-table>
