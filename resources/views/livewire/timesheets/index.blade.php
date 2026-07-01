<div class="max-w-3xl space-y-4">
    @if($weekly_hours_to_confirm->isNotEmpty())
    <x-island-card heading="Confirm Weekly Timesheets">
        <x-slot:actions>
            <flux:button href="{{route('hours.create')}}">Add Hours</flux:button>
        </x-slot:actions>

        <div class="space-y-2" x-data="{ hoveredWeeklyGroup: null }">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Date</flux:table.column>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column>Hours</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach($weekly_hours_to_confirm as $timesheet_user_name => $timesheet_weekly)
                        @foreach($timesheet_weekly as $timesheet_week_date => $timesheet)
                            <flux:table.row
                                :key="'weekly-'.$timesheet->timesheet_id"
                                x-on:mouseenter="hoveredWeeklyGroup = 'weekly:{{ $timesheet->timesheet_id }}'"
                                x-on:mouseleave="hoveredWeeklyGroup = null"
                                x-bind:class="hoveredWeeklyGroup && hoveredWeeklyGroup === 'weekly:{{ $timesheet->timesheet_id }}' ? 'bg-indigo-50/70 dark:bg-indigo-900/20' : ''"
                            >
                                <flux:table.cell>{{ $timesheet_week_date }}</flux:table.cell>
                                <flux:table.cell
                                    wire:navigate.hover
                                    href="{{route('timesheets.create', $timesheet->timesheet_id)}}"
                                    variant="strong"
                                    class="cursor-pointer transition-colors hover:text-indigo-600 dark:hover:text-indigo-400"
                                    >
                                    {{ $timesheet_user_name }}
                                </flux:table.cell>
                                <flux:table.cell>{{ $timesheet->sum_hours }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" :color="'red'" inset="top bottom"><a href="{{route('timesheets.create', $timesheet->timesheet_id)}}">Confirm</a></flux:badge>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    </x-island-card>
    @endif

    <x-island-card heading="Confirmed Timesheets Filters" :separator="true">
        <div class="flex flex-col sm:flex-row items-end gap-4">
            <div class="flex-1 min-w-0 w-full">
                <flux:input wire:model.live.debounce.300ms="search" label="Search" icon="magnifying-glass" placeholder="Name, note, or amount" clearable />
            </div>

            <div class="flex-1 min-w-0 w-full">
                <flux:select wire:model.live="user_id" label="Employee" variant="listbox" searchable clearable placeholder="All employees...">
                    <x-slot name="search">
                        <flux:select.search placeholder="Search..." />
                    </x-slot>
                    @foreach ($employees as $employee)
                        <flux:select.option value="{{ $employee->id }}">{{ trim($employee->first_name . ' ' . $employee->last_name) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="flex-1 min-w-0 w-full">
                <flux:select variant="listbox" label="Status" multiple placeholder="Choose status..." wire:model.live="paid_statuses">
                    <flux:select.option value="Confirmed"><flux:badge size="md" inset="top bottom" color="green">Paid</flux:badge></flux:select.option>
                    <flux:select.option value="Unpaid"><flux:badge size="md" inset="top bottom" color="amber">Unpaid</flux:badge></flux:select.option>
                </flux:select>
            </div>

            <div class="flex-1 min-w-0 w-full">
                <flux:date-picker
                    wire:model.live="date_range"
                    mode="range"
                    with-presets
                    presets="last30Days last3Months last6Months thisMonth lastMonth thisYear lastYear custom"
                    clearable
                    placeholder="All time"
                    label="Date Range"
                />
            </div>
        </div>
    </x-island-card>

    <x-island-card heading="Confirmed Timesheets">

        <div class="space-y-2" x-data="{ hoveredConfirmedGroup: null }">

            <flux:table :paginate="$timesheets->hasPages() ? $timesheets : null">
                <flux:table.columns>
                    <flux:table.column sortable :sorted="$sortBy === 'date'" :direction="$sortDirection" wire:click="sort('date')">Date</flux:table.column>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column>Project</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'hours'" :direction="$sortDirection" wire:click="sort('hours')">Hours</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'amount'" :direction="$sortDirection" wire:click="sort('amount')">Amount</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @php
                        $timesheetsByDate = $timesheets->getCollection()->groupBy(fn ($item) => $item->date->format('m/d/Y'));
                    @endphp

                    @if($timesheetsByDate->isNotEmpty())
                        @foreach($timesheetsByDate as $timesheetDate => $timesheetsForDate)
                            @php
                                $timesheetsByMember = $timesheetsForDate->groupBy(fn ($item) => (string) ($item->user_id ?? 0));
                            @endphp

                            @foreach($timesheetsByMember as $timesheetMemberKey => $timesheetsForMember)
                                @php
                                    $memberName = trim(optional($timesheetsForMember->first()->user)->first_name . ' ' . optional($timesheetsForMember->first()->user)->last_name);
                                    $memberGroupKey = 'group:' . $timesheetDate . ':' . $timesheetMemberKey;
                                @endphp

                                @foreach($timesheetsForMember as $timesheet)
                                    <flux:table.row
                                        :key="'timesheet-'.$timesheet->id"
                                        class="[&[data-hovered=true]>td]:bg-indigo-50/70 dark:[&[data-hovered=true]>td]:bg-indigo-900/20 [&>td]:transition-colors"
                                        x-on:mouseenter="hoveredConfirmedGroup = '{{ $memberGroupKey }}'"
                                        x-on:mouseleave="hoveredConfirmedGroup = null"
                                        x-bind:data-hovered="hoveredConfirmedGroup === '{{ $memberGroupKey }}' ? 'true' : 'false'"
                                    >
                                        @if($loop->parent->first && $loop->first)
                                            <flux:table.cell rowspan="{{ $timesheetsForDate->count() }}" class="align-top">{{ $timesheetDate }}</flux:table.cell>
                                        @endif

                                        @if($loop->first)
                                            <flux:table.cell
                                                rowspan="{{ $timesheetsForMember->count() }}"
                                                wire:navigate.hover
                                                href="{{route('timesheets.show', $timesheet->id)}}"
                                                variant="strong"
                                                class="align-top cursor-pointer transition-colors hover:text-indigo-600 dark:hover:text-indigo-400"
                                                >
                                                {{ $memberName }}
                                            </flux:table.cell>
                                        @endif

                                        <flux:table.cell>
                                            @if($timesheet->project)
                                                <a
                                                    wire:navigate.hover
                                                    href="{{ route('projects.show', $timesheet->project->id) }}"
                                                    class="block truncate transition-colors hover:text-indigo-600 dark:hover:text-indigo-400"
                                                    title="{{ $timesheet->project->project_name ?? $timesheet->project->short_address ?? '' }}"
                                                >
                                                    {{ $timesheet->project->short_address ?? '—' }}
                                                </a>
                                            @else
                                                <span>—</span>
                                            @endif
                                        </flux:table.cell>
                                        <flux:table.cell>{{ $timesheet->hours }}</flux:table.cell>
                                        <flux:table.cell>{{ money($timesheet->amount) }}</flux:table.cell>
                                        <flux:table.cell>
                                            @php
                                                if (! is_null($timesheet->paid_by)) {
                                                    $statusColor = 'yellow';
                                                    $statusLabel = 'Paid By';
                                                    $statusHref = null;
                                                } elseif (! is_null($timesheet->check_id)) {
                                                    $statusColor = 'green';
                                                    $statusLabel = 'Paid';
                                                    $statusHref = route('checks.show', $timesheet->check_id);
                                                } else {
                                                    $statusColor = 'yellow';
                                                    $statusLabel = 'Pay';
                                                    $statusHref = auth()->user()->vendor_role === 'Admin'
                                                        ? route('timesheets.payment', $timesheet->user_id)
                                                        : null;
                                                }
                                            @endphp
                                            @if($statusHref)
                                                <a wire:navigate.hover href="{{ $statusHref }}">
                                                    <flux:badge size="sm" :color="$statusColor" inset="top bottom">{{ $statusLabel }}</flux:badge>
                                                </a>
                                            @else
                                                <flux:badge size="sm" :color="$statusColor" inset="top bottom">{{ $statusLabel }}</flux:badge>
                                            @endif
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            @endforeach
                        @endforeach
                    @else
                        <flux:table.row>
                            <flux:table.cell colspan="6" class="text-center text-zinc-500 dark:text-zinc-400">No timesheets found.</flux:table.cell>
                        </flux:table.row>
                    @endif
                </flux:table.rows>
            </flux:table>
        </div>
    </x-island-card>
</div>
