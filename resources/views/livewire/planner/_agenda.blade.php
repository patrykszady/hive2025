{{-- The Week view (default since 2026-10-01): this week and next, one day
     per row with its tasks grouped by project, and the "To schedule" tray
     beside it. A tray task is booked by dragging it onto a day or from its
     date menu (CardsIndex::scheduleTask); clicking any task opens it. --}}
@php
    $agendaDays = $this->agendaDays;
    $toSchedule = $this->toScheduleByProject;
    $toScheduleCount = $toSchedule->sum(fn (array $group) => $group['tasks']->count());
    $scheduledCount = $agendaDays->flatMap(fn (array $day) => $day['groups']->flatMap(fn (array $group) => $group['tasks']->pluck('id')))->unique()->count();
    $agendaFirst = $agendaDays->first()['carbon'];
    $agendaLast = $agendaDays->last()['carbon'];
    $isThisWeek = $agendaFirst->isSameDay(browser_today()->copy()->startOfWeek(\Carbon\Carbon::MONDAY));
    $quickToday = browser_today()->copy();
    $quickDates = [
        ['label' => 'Today', 'date' => $quickToday->format('Y-m-d'), 'hint' => $quickToday->format('D, M j')],
        ['label' => 'Tomorrow', 'date' => $quickToday->copy()->addDay()->format('Y-m-d'), 'hint' => $quickToday->copy()->addDay()->format('D, M j')],
        ['label' => 'Next Monday', 'date' => $quickToday->copy()->next(\Carbon\Carbon::MONDAY)->format('Y-m-d'), 'hint' => $quickToday->copy()->next(\Carbon\Carbon::MONDAY)->format('M j')],
    ];
@endphp

<div class="flex-1 min-h-0 flex overflow-hidden" x-data="plannerAgenda()" x-on:dragend.window="end()">
    {{-- Days --}}
    <div class="flex-1 min-w-0 overflow-y-auto">
        <div class="max-w-5xl mx-auto px-6 py-5">
            <div class="flex items-center justify-between gap-4 mb-5">
                <div class="min-w-0">
                    <flux:heading size="lg">
                        {{ $agendaFirst->format('M j') }} &ndash; {{ $agendaLast->format($agendaFirst->month === $agendaLast->month ? 'j, Y' : 'M j, Y') }}
                    </flux:heading>
                    <flux:text class="mt-0.5">
                        {{ $scheduledCount }} {{ Str::plural('task', $scheduledCount) }} scheduled
                        @if ($toScheduleCount > 0)
                            &middot; <span class="text-amber-700 dark:text-amber-400">{{ $toScheduleCount }} still to schedule</span>
                        @endif
                    </flux:text>
                </div>
                <div class="flex items-center gap-1 shrink-0">
                    <flux:button wire:click="agendaPreviousWeek" size="sm" variant="ghost" icon="chevron-left" aria-label="Previous week" />
                    <flux:button wire:click="agendaThisWeek" size="sm" :variant="$isThisWeek ? 'filled' : 'outline'">This week</flux:button>
                    <flux:button wire:click="agendaNextWeek" size="sm" variant="ghost" icon="chevron-right" aria-label="Next week" />
                </div>
            </div>

            @foreach ($agendaDays->chunk(7) as $weekIndex => $week)
                @php
                    $weekStart = $week->first()['carbon'];
                    $weekLabel = match (true) {
                        $weekStart->isSameDay(browser_today()->copy()->startOfWeek(\Carbon\Carbon::MONDAY)) => 'This week',
                        $weekStart->isSameDay(browser_today()->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->addWeek()) => 'Next week',
                        $weekStart->isSameDay(browser_today()->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->subWeek()) => 'Last week',
                        default => 'Week of '.$weekStart->format('M j'),
                    };
                @endphp
                <div class="{{ $weekIndex > 0 ? 'mt-8' : '' }}">
                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 mb-2">{{ $weekLabel }}</div>

                    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($week as $day)
                            @php
                                $hasWork = $day['groups']->isNotEmpty();
                                $dimmed = $day['isPast'] || ($day['isWeekend'] && ! $hasWork);
                            @endphp
                            <div
                                wire:key="agenda-day-{{ $day['date'] }}"
                                data-agenda-day="{{ $day['date'] }}"
                                class="group/day relative flex gap-4 px-4 transition-colors {{ $hasWork ? 'py-3' : 'py-2' }} {{ $day['isToday'] ? 'bg-indigo-50/60 dark:bg-indigo-950/30' : '' }}"
                                :class="over === '{{ $day['date'] }}' ? '!bg-indigo-100 dark:!bg-indigo-900/50 ring-2 ring-inset ring-indigo-400' : ''"
                                x-on:dragover.prevent="dragging && (over = '{{ $day['date'] }}')"
                                x-on:dragleave="over === '{{ $day['date'] }}' && (over = null)"
                                x-on:drop.prevent="drop($event, '{{ $day['date'] }}')"
                            >
                                {{-- Date --}}
                                <div class="w-24 shrink-0 pt-0.5 {{ $dimmed ? 'opacity-50' : '' }}">
                                    <div class="text-sm font-semibold {{ $day['isToday'] ? 'text-indigo-600 dark:text-indigo-400' : 'text-zinc-800 dark:text-zinc-200' }}">
                                        {{ $day['carbon']->format('D') }} <span class="tabular-nums">{{ $day['carbon']->format('M j') }}</span>
                                    </div>
                                    @if ($day['isToday'])
                                        <flux:badge size="sm" color="indigo" class="mt-0.5">Today</flux:badge>
                                    @endif
                                </div>

                                {{-- Work --}}
                                <div class="flex-1 min-w-0 {{ $day['isPast'] ? 'opacity-60' : '' }}">
                                    @if ($hasWork)
                                        <div class="space-y-3">
                                            @foreach ($day['groups'] as $group)
                                                @php $project = $group['project']; @endphp
                                                <div wire:key="agenda-{{ $day['date'] }}-project-{{ $project->id }}">
                                                    <div class="flex items-center gap-2 min-w-0 mb-1.5">
                                                        <a
                                                            href="{{ route('projects.show', $project) }}"
                                                            class="truncate text-sm font-semibold text-zinc-800 dark:text-zinc-100 hover:underline underline-offset-2"
                                                        >{{ $project->short_address }}</a>
                                                        @if ($project->latestStatus)
                                                            <flux:tooltip :content="$project->latestStatus->title">
                                                                <flux:badge :color="$project->latestStatus->badge_color" size="sm" class="!px-0 !size-2 !min-w-0 rounded-full shrink-0" />
                                                            </flux:tooltip>
                                                        @endif
                                                        @if ($project->client?->last_names || $project->project_name)
                                                            <span class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                                                                {{ $project->client?->last_names }}{{ $project->client?->last_names && $project->project_name ? ' | ' : '' }}{{ $project->project_name }}
                                                            </span>
                                                        @endif
                                                    </div>
                                                    <div class="grid grid-cols-1 sm:grid-cols-2 2xl:grid-cols-3 gap-2">
                                                        @foreach ($group['tasks'] as $task)
                                                            <flux:kanban.card
                                                                as="button"
                                                                class="min-w-0 w-full text-left {{ $task->trashed() ? 'opacity-50' : '' }}"
                                                                wire:key="agenda-{{ $day['date'] }}-task-{{ $task->id }}"
                                                                wire:click="$dispatchTo('tasks.task-create', 'editTask', { task: {{ $task->id }} })"
                                                            >
                                                                @include('components.upcoming-tasks-list-card-content', [
                                                                    'task' => $task,
                                                                    'date' => $day['date'],
                                                                    'taskUsers' => $task->users,
                                                                    'isWeekend' => $day['isWeekend'],
                                                                ])
                                                            </flux:kanban.card>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @else
                                        <div class="text-sm text-zinc-400 dark:text-zinc-500 pt-0.5" x-show="over !== '{{ $day['date'] }}'">Nothing scheduled</div>
                                    @endif
                                    <div class="text-sm font-medium text-indigo-700 dark:text-indigo-300 pt-0.5" x-show="over === '{{ $day['date'] }}'" style="display: none;">
                                        Drop to schedule on {{ $day['carbon']->format('D, M j') }}
                                    </div>
                                </div>

                                {{-- Add a task on this day --}}
                                <div class="shrink-0 opacity-0 group-hover/day:opacity-100 focus-within:opacity-100 transition-opacity">
                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        icon="plus"
                                        wire:click="$dispatchTo('tasks.task-create', 'addTask', { date: '{{ $day['date'] }}' })"
                                        aria-label="Add a task on {{ $day['carbon']->format('D, M j') }}"
                                    >Add</flux:button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- To schedule --}}
    <aside class="w-80 xl:w-96 shrink-0 border-l border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/60 overflow-y-auto">
        <div class="px-4 py-5">
            <div class="flex items-center gap-2">
                <flux:heading size="lg">To schedule</flux:heading>
                <flux:badge size="sm" :color="$toScheduleCount > 0 ? 'amber' : 'zinc'">{{ $toScheduleCount }}</flux:badge>
            </div>
            <flux:text class="mt-1">
                @if ($toScheduleCount > 0)
                    Drag a task onto a day, or pick a date.
                @else
                    Every task has a date.
                @endif
            </flux:text>

            <div class="mt-4 space-y-4">
                @foreach ($toSchedule as $group)
                    @php $project = $group['project']; @endphp
                    <div wire:key="to-schedule-project-{{ $project->id }}">
                        <div class="flex items-center gap-2 min-w-0 mb-1.5">
                            <a
                                href="{{ route('projects.show', $project) }}"
                                class="truncate text-sm font-semibold text-zinc-800 dark:text-zinc-100 hover:underline underline-offset-2"
                            >{{ $project->short_address }}</a>
                            @if ($project->latestStatus)
                                <flux:tooltip :content="$project->latestStatus->title">
                                    <flux:badge :color="$project->latestStatus->badge_color" size="sm" class="!px-0 !size-2 !min-w-0 rounded-full shrink-0" />
                                </flux:tooltip>
                            @endif
                        </div>
                        <div class="space-y-2">
                            @foreach ($group['tasks'] as $task)
                                <div
                                    wire:key="to-schedule-task-{{ $task->id }}"
                                    data-to-schedule-task="{{ $task->id }}"
                                    draggable="true"
                                    x-on:dragstart="start($event, {{ $task->id }})"
                                    class="group/task flex items-start gap-2 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-3 py-2 shadow-xs cursor-grab active:cursor-grabbing"
                                    :class="dragging === {{ $task->id }} ? 'opacity-50' : ''"
                                >
                                    <flux:icon.bars-2 class="size-4 mt-0.5 shrink-0 text-zinc-300 dark:text-zinc-600 group-hover/task:text-zinc-400" />
                                    <button
                                        type="button"
                                        class="flex-1 min-w-0 text-left"
                                        wire:click="$dispatchTo('tasks.task-create', 'editTask', { task: {{ $task->id }} })"
                                    >
                                        <div class="truncate text-sm font-medium {{ data_get($task->type_ui, 'text', 'text-zinc-800 dark:text-zinc-100') }}">{{ $task->title }}</div>
                                        @if ($task->vendor || $task->users->isNotEmpty())
                                            <div class="flex items-center gap-1.5 mt-1 min-w-0">
                                                @foreach ($task->users->take(3) as $user)
                                                    <flux:avatar circle size="xs" name="{{ $user->full_name }}" color="auto" color:seed="{{ $user->id }}" title="{{ $user->full_name }}" />
                                                @endforeach
                                                @if ($task->vendor)
                                                    <flux:avatar circle size="xs" name="{{ $task->vendor->name }}" color="auto" color:seed="{{ $task->vendor->id }}" />
                                                    <span class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $task->vendor->name }}</span>
                                                @endif
                                            </div>
                                        @endif
                                    </button>
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button size="xs" variant="ghost" icon="calendar" aria-label="Schedule {{ $task->title }}" />
                                        <flux:menu>
                                            @foreach ($quickDates as $quick)
                                                <flux:menu.item wire:click="scheduleTask({{ $task->id }}, '{{ $quick['date'] }}')">
                                                    <span class="flex-1">{{ $quick['label'] }}</span>
                                                    <span class="ml-4 text-xs text-zinc-400">{{ $quick['hint'] }}</span>
                                                </flux:menu.item>
                                            @endforeach
                                            <flux:menu.separator />
                                            <flux:menu.item icon="calendar-days" x-on:click="pickDate({{ $task->id }}, @js($task->title))">Pick a date&hellip;</flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </aside>

    {{-- One date picker shared by every tray task. --}}
    <flux:modal name="planner-schedule-date" class="w-auto">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Schedule</flux:heading>
                <flux:text class="mt-1 truncate" x-text="pickTitle"></flux:text>
            </div>
            <flux:calendar
                x-ref="scheduleCalendar"
                x-on:change="choose($event.target.value)"
                start-day="1"
                with-today
            />
        </div>
    </flux:modal>
</div>
