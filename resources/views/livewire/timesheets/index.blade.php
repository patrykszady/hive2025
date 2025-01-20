<div class="max-w-3xl">
    <flux:card class="space-y-2 mb-4">
        <div class="flex justify-between">
            <flux:heading size="lg">Confirm Weekly Timesheets</flux:heading>
            <flux:button href="{{route('hours.create')}}">Add Hours</flux:button>
        </div>

        <div class="space-y-2">
            <flux:table>
                <flux:columns>
                    <flux:column>Date</flux:column>
                    <flux:column>Name</flux:column>
                    <flux:column>Hours</flux:column>
                    {{-- <flux:column>Amount</flux:column> --}}
                    {{-- <flux:column sortable :sorted="$sortBy === 'date'" :direction="$sortDirection" wire:click="sort('date')">Date</flux:column> --}}
                    <flux:column>Status</flux:column>
                </flux:columns>

                <flux:rows>
                    @foreach($weekly_hours_to_confirm as $timesheet_user_name => $timesheet_weekly)
                        @foreach($timesheet_weekly as $timesheet_week_date => $timesheet)
                            <flux:row :key="$timesheet->timesheet_id">
                                <flux:cell>{{ $timesheet_week_date }}</flux:cell>
                                <flux:cell
                                    wire:navigate.hover
                                    href="{{route('timesheets.create', $timesheet->timesheet_id)}}"
                                    variant="strong"
                                    class="cursor-pointer"
                                    >
                                    {{ $timesheet_user_name }}
                                </flux:cell>
                                <flux:cell>{{ $timesheet->sum_hours }}</flux:cell>
                                {{-- <flux:cell>{{ money($timesheet->sum_amount) }}</flux:cell> --}}
                                <flux:cell>
                                    <flux:badge size="sm" :color="'red'" inset="top bottom"><a href="{{route('timesheets.create', $timesheet->timesheet_id)}}">Confirm</a></flux:badge>
                                </flux:cell>
                            </flux:row>
                        @endforeach
                    @endforeach
                </flux:rows>
            </flux:table>
        </div>
    </flux:card>

    <flux:card class="space-y-2">
        <div class="flex justify-between">
            <flux:heading size="lg">Confirmed Timesheets</flux:heading>
            {{-- <flux:button href="{{route('hours.create')}}">Add Hours</flux:button> --}}
        </div>

        <div class="space-y-2">

            <flux:table :paginate="$timesheets">
                <flux:columns>
                    <flux:column>Date</flux:column>
                    <flux:column>Name</flux:column>
                    <flux:column>Hours</flux:column>
                    <flux:column>Amount</flux:column>
                    {{-- <flux:column sortable :sorted="$sortBy === 'date'" :direction="$sortDirection" wire:click="sort('date')">Date</flux:column> --}}
                    <flux:column>Status</flux:column>
                </flux:columns>

                <flux:rows>
                    @foreach($timesheets as $timesheet_week => $timesheet_weekly)
                        @foreach($timesheet_weekly as $timesheet_user_name => $timesheet)
                            <flux:row :key="$timesheet->timesheet_id">
                                <flux:cell>{{ $timesheet->date }}</flux:cell>
                                <flux:cell
                                    wire:navigate.hover
                                    href="{{route('timesheets.show', $timesheet->timesheet_id)}}"
                                    variant="strong"
                                    class="cursor-pointer"
                                    >
                                    {{ $timesheet_user_name }}
                                </flux:cell>
                                <flux:cell>{{ $timesheet->sum_hours }}</flux:cell>
                                <flux:cell>{{ money($timesheet->sum_amount) }}</flux:cell>
                                <flux:cell>
                                    <flux:badge size="sm" :color="'green'" inset="top bottom">Confirmed</flux:badge>
                                </flux:cell>
                            </flux:row>
                        @endforeach
                    @endforeach
                </flux:rows>
            </flux:table>
        </div>
    </flux:card>
</div>
