<div class="max-w-3xl space-y-4">
    @if($weekly_hours_to_confirm->isNotEmpty())
    <x-index-table heading="Confirm Weekly Timesheets" x-data="{ hoveredWeeklyGroup: null }">
        <x-slot:actions>
            <flux:button href="{{route('hours.create')}}">Add Hours</flux:button>
        </x-slot:actions>

            <flux:table class="index-table [:where(&)]:p-0 [:where(&)]:space-y-0">
                <flux:table.columns>
                    <flux:table.column class="w-[22%]">Date</flux:table.column>
                    <flux:table.column class="w-[22%] min-w-0">Name</flux:table.column>
                    <flux:table.column class="w-[18%]">Hours</flux:table.column>
                    <flux:table.column class="w-[14%]">Status</flux:table.column>
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
                                    <flux:badge size="sm" :color="'red'" inset="top bottom"><a wire:navigate.hover href="{{route('timesheets.create', $timesheet->timesheet_id)}}">Confirm</a></flux:badge>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    @endforeach
                </flux:table.rows>
            </flux:table>
    </x-index-table>
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
                <flux:select variant="listbox" label="Status" multiple clearable placeholder="Choose status..." wire:model.live="paid_statuses">
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

    {{-- lazy island: the card paints from the shared skeleton first, then the
         real table swaps in — same loading treatment as the Leads card. --}}
    @island(name: 'timesheets-table', lazy: island_lazy(), always: true)
        @placeholder
            <x-index-table.placeholder
                heading="Confirmed Timesheets"
                :columns="\App\Livewire\Timesheets\TimesheetsIndex::columnDefs()"
                :rows="\App\Livewire\Timesheets\TimesheetsIndex::placeholderRows()"
                :page-size="\App\Livewire\Timesheets\TimesheetsIndex::placeholderRows()"
                :compact="false"
            />
        @endplaceholder
    {{-- One week per person as the main row (sums), its per-project rows as
         shaded sub-rows — same pattern as expense splits under a main expense.
         Single-project weeks stay one plain row, like an unsplit expense. --}}
    <x-index-table heading="Confirmed Timesheets" :paginator="$this->timesheets">
            <flux:table class="index-table [:where(&)]:p-0 [:where(&)]:space-y-0">
                <flux:table.columns>
                    <flux:table.column sortable :sorted="$sortBy === 'date'" :direction="$sortDirection" wire:click="sort('date')" class="w-[13%]">Date</flux:table.column>
                    <flux:table.column class="w-[22%] min-w-0">Name</flux:table.column>
                    <flux:table.column class="w-[27%] min-w-0">Projects</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'hours'" :direction="$sortDirection" wire:click="sort('hours')" class="w-[11%]">Hours</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'amount'" :direction="$sortDirection" wire:click="sort('amount')" class="w-[13%]">Amount</flux:table.column>
                    <flux:table.column class="w-[14%]">Status</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse($this->timesheets as $group)
                        @php
                            $singleRow = $group->week_rows->count() === 1 ? $group->week_rows->first() : null;

                            // Week-level status, action first: anything unpaid
                            // means the week still needs a payment.
                            if ($singleRow) {
                                [$statusLabel, $statusColor, $statusHref] = \App\Livewire\Timesheets\TimesheetsIndex::rowStatus($singleRow);
                            } elseif ($group->unpaid_count > 0) {
                                $statusColor = 'yellow';
                                $statusLabel = 'Pay';
                                $statusHref = auth()->user()->vendor_role === 'Admin'
                                    ? route('timesheets.payment', $group->user_id)
                                    : null;
                            } elseif ($group->paid_count > 0) {
                                $statusColor = 'green';
                                $statusLabel = 'Paid';
                                // Link straight to the check when the whole
                                // week settled on one.
                                $statusHref = $group->distinct_checks == 1
                                    ? route('checks.show', $group->single_check_id)
                                    : route('timesheets.show', $group->first_timesheet_id);
                            } else {
                                $statusColor = 'yellow';
                                $statusLabel = 'Paid By';
                                $statusHref = null;
                            }
                        @endphp

                        <flux:table.row :key="'week-'.$group->user_id.'-'.$group->date->format('Y-m-d')">
                            <flux:table.cell class="whitespace-nowrap">{{ $group->date->format('m/d/Y') }}</flux:table.cell>
                            <flux:table.cell variant="strong" class="min-w-0">
                                <x-table-link
                                    :href="route('timesheets.show', $group->first_timesheet_id)"
                                    :label="$group->member_name"
                                />
                            </flux:table.cell>
                            <flux:table.cell class="min-w-0">
                                @if($singleRow)
                                    <x-table-link
                                        :href="$singleRow->project ? route('projects.show', $singleRow->project->id) : null"
                                        :label="$singleRow->project->short_address ?? $singleRow->project->project_name ?? '—'"
                                    />
                                @endif
                                {{-- Multi-project weeks: the sub-rows below
                                     carry the names, the main row stays empty. --}}
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap">{{ (float) $group->total_hours }}</flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap">{{ money($group->total_amount) }}</flux:table.cell>
                            <flux:table.cell>
                                @if($statusHref)
                                    <a wire:navigate.hover href="{{ $statusHref }}">
                                        <flux:badge size="sm" :color="$statusColor" inset="top bottom">{{ $statusLabel }}</flux:badge>
                                    </a>
                                @else
                                    <flux:badge size="sm" :color="$statusColor" inset="top bottom">{{ $statusLabel }}</flux:badge>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>

                        {{-- Per-project sub-rows, styled like expense splits:
                             shaded, tighter, muted; empty cells keep column
                             alignment. A sub-row only repeats a status badge
                             when it differs from the week's own. --}}
                        @unless($singleRow)
                            @foreach($group->week_rows as $row)
                                @php
                                    [$rowLabel, $rowColor, $rowHref] = \App\Livewire\Timesheets\TimesheetsIndex::rowStatus($row);
                                @endphp
                                <flux:table.row :key="'week-row-'.$row->id" class="bg-gray-50 dark:bg-gray-800/50 [&_td]:!py-2">
                                    <flux:table.cell></flux:table.cell>
                                    <flux:table.cell></flux:table.cell>
                                    <flux:table.cell class="min-w-0 text-sm text-gray-600 dark:text-gray-400">
                                        <x-table-link
                                            :href="$row->project ? route('projects.show', $row->project->id) : null"
                                            :label="$row->project->short_address ?? $row->project->project_name ?? '—'"
                                        />
                                    </flux:table.cell>
                                    <flux:table.cell class="whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">{{ (float) $row->hours }}</flux:table.cell>
                                    <flux:table.cell class="whitespace-nowrap text-sm text-gray-600 dark:text-gray-400 tabular-nums">{{ money($row->amount) }}</flux:table.cell>
                                    <flux:table.cell>
                                        @if($rowLabel !== $statusLabel)
                                            @if($rowHref)
                                                <a wire:navigate.hover href="{{ $rowHref }}">
                                                    <flux:badge size="sm" :color="$rowColor" inset="top bottom">{{ $rowLabel }}</flux:badge>
                                                </a>
                                            @else
                                                <flux:badge size="sm" :color="$rowColor" inset="top bottom">{{ $rowLabel }}</flux:badge>
                                            @endif
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        @endunless
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6" class="text-center text-zinc-500 dark:text-zinc-400">No timesheets found.</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
    </x-index-table>
    @endisland
</div>
