<div class="max-w-3xl">
    <flux:card class="space-y-2 mb-4">
        <div class="flex justify-between">
            <flux:heading size="lg">Confirm Weekly Timesheets</flux:heading>
            <flux:button href="{{route('hours.create')}}">Add Hours</flux:button>
        </div>

        <div class="space-y-2">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Date</flux:table.column>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column>Hours</flux:table.column>
                    {{-- <flux:table.column>Amount</flux:table.column> --}}
                    {{-- <flux:table.column sortable :sorted="$sortBy === 'date'" :direction="$sortDirection" wire:click="sort('date')">Date</flux:table.column> --}}
                    <flux:table.column>Status</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach($weekly_hours_to_confirm as $timesheet_user_name => $timesheet_weekly)
                        @foreach($timesheet_weekly as $timesheet_week_date => $timesheet)
                            <flux:table.row :key="$timesheet->timesheet_id">
                                <flux:table.cell>{{ $timesheet_week_date }}</flux:table.cell>
                                <flux:table.cell
                                    wire:navigate.hover
                                    href="{{route('timesheets.create', $timesheet->timesheet_id)}}"
                                    variant="strong"
                                    class="cursor-pointer"
                                    >
                                    {{ $timesheet_user_name }}
                                </flux:table.cell>
                                <flux:table.cell>{{ $timesheet->sum_hours }}</flux:table.cell>
                                {{-- <flux:table.cell>{{ money($timesheet->sum_amount) }}</flux:table.cell> --}}
                                <flux:table.cell>
                                    <flux:badge size="sm" :color="'red'" inset="top bottom"><a href="{{route('timesheets.create', $timesheet->timesheet_id)}}">Confirm</a></flux:badge>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    </flux:card>

    <flux:card class="space-y-2">
        <div class="flex justify-between">
            <flux:heading size="lg">Confirmed Timesheets</flux:heading>
            {{-- <flux:button href="{{route('hours.create')}}">Add Hours</flux:button> --}}
        </div>

        <div class="space-y-2">

            <flux:table :paginate="$timesheets->hasPages() ? $timesheets : null">
                <flux:table.columns>
                    <flux:table.column>Date</flux:table.column>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column>Hours</flux:table.column>
                    <flux:table.column>Amount</flux:table.column>
                    {{-- <flux:table.column sortable :sorted="$sortBy === 'date'" :direction="$sortDirection" wire:click="sort('date')">Date</flux:table.column> --}}
                    <flux:table.column>Status</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach($timesheets as $timesheet_week => $timesheet_weekly)
                        @foreach($timesheet_weekly as $timesheet_user_name => $timesheet)
                            <flux:table.row :key="$timesheet->timesheet_id">
                                <flux:table.cell>{{ $timesheet->date }}</flux:table.cell>
                                <flux:table.cell
                                    wire:navigate.hover
                                    href="{{route('timesheets.show', $timesheet->timesheet_id)}}"
                                    variant="strong"
                                    class="cursor-pointer"
                                    >
                                    {{ $timesheet_user_name }}
                                </flux:table.cell>
                                <flux:table.cell>{{ $timesheet->sum_hours }}</flux:table.cell>
                                <flux:table.cell>{{ money($timesheet->sum_amount) }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" :color="'green'" inset="top bottom">Confirmed</flux:badge>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    </flux:card>
</div>
