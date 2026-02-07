{{-- Task Cards for this date --}}
@if($tasks->isEmpty())
    {{-- Empty day: no tasks scheduled --}}
@else
    @if($showProjectInfo ?? false)
        {{-- Group tasks by project, render project header with nested task cards (planner cards style) --}}
        @php
            $tasksByProject = $tasks->groupBy(fn ($task) => $task->project_id ?? 0);
        @endphp
        <div class="space-y-2 pl-0">
            @foreach($tasksByProject as $projectId => $projectTasks)
                @php
                    $project = $projectTasks->first()->project;
                    $latestStatus = $project?->latestStatus;
                @endphp
                <flux:kanban class="w-full [&>div]:w-full [&>div]:min-w-0 [&>div]:flex-1">
                    <flux:kanban.column class="!w-full !max-w-full bg-white dark:bg-zinc-900 rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <flux:kanban.column.header
                            class="min-w-0 w-full [&>div:first-child>div:first-child]:!min-w-0 [&>div:first-child>div:first-child]:!flex-1 [&>div:first-child>div:first-child]:truncate [&>div:first-child>div:last-child]:!shrink-0 [&_[data-flux-subheading]]:!min-w-0 [&_[data-flux-subheading]]:truncate"
                        >
                            <flux:heading class="min-w-0 truncate flex items-center gap-2">
                                <a
                                    href="{{ $project ? route('projects.show', $project) : '#' }}"
                                    wire:click.stop
                                    class="truncate hover:underline underline-offset-2"
                                >
                                    {{ $project->short_address ?? 'No project' }}
                                </a>
                                @if($latestStatus)
                                    <flux:tooltip content="{{ $latestStatus->title }}">
                                        <flux:badge :color="$latestStatus->badge_color" size="sm" class="!px-0 !size-2 !min-w-0 rounded-full shrink-0" />
                                    </flux:tooltip>
                                @endif
                            </flux:heading>
                            <x-slot name="actions">
                                <flux:button
                                    variant="subtle"
                                    icon="plus"
                                    size="sm"
                                    class="shrink-0"
                                    wire:click="$dispatchTo('tasks.task-create', 'addTask', { project_id: {{ $projectId }}, date: '{{ $date }}' })"
                                />
                            </x-slot>
                            @if($project?->client || $project?->project_name)
                                <x-slot name="subheading">
                                    <span class="block min-w-0 truncate">
                                        {{ $project->client?->last_names }}{{ $project->client?->last_names && $project->project_name ? ' | ' : '' }}{{ $project->project_name }}
                                    </span>
                                </x-slot>
                            @endif
                        </flux:kanban.column.header>
                        <flux:kanban.column.cards>
                            @foreach($projectTasks as $task)
                                @php
                                    $typeUi = $task->type_ui ?? [];
                                    $taskTypeTextClasses = data_get($typeUi, 'text', '');
                                    $taskUsers = $task->users ?? collect();
                                    $taskVendor = $task->vendor ?? null;

                                    $selectedDates = data_get($task->options, 'dates', []);
                                    $totalDays = count($selectedDates);
                                    $currentDay = 0;
                                    $showDayCounter = false;

                                    if (!empty($selectedDates)) {
                                        sort($selectedDates);
                                        $currentDay = array_search($date, $selectedDates);
                                        if ($currentDay !== false) {
                                            $currentDay++;
                                        }
                                        $showDayCounter = $totalDays > 1 && $currentDay > 0;
                                    }

                                    $arrivalTimeLabel = null;
                                    $dayFormat = $carbonDate->format('Y-m-d');
                                    $dayTimeSettings = data_get($task->options, "time_settings.$dayFormat");
                                    $dayUsesTime = (bool) data_get($dayTimeSettings, 'use_time', false);
                                    $dayStartTime = (string) data_get($dayTimeSettings, 'start_time', '');
                                    if ($dayUsesTime && $dayStartTime !== '') {
                                        try {
                                            $arrivalTimeLabel = \Carbon\Carbon::createFromFormat('H:i', $dayStartTime)->format('g:i A');
                                        } catch (\Exception $e) {
                                            $arrivalTimeLabel = null;
                                        }
                                    }
                                @endphp

                                @if($clickable)
                                    <flux:kanban.card
                                        as="button"
                                        class="min-w-0 w-full"
                                        wire:key="upcoming-task-{{ $task->id }}-{{ $date }}"
                                        wire:click="$dispatchTo('tasks.task-create', 'editTask', { task: {{ $task->id }} })"
                                    >
                                        @include('components.upcoming-tasks-list-card-content', [
                                            'task' => $task,
                                            'taskTypeTextClasses' => $taskTypeTextClasses,
                                            'arrivalTimeLabel' => $arrivalTimeLabel,
                                            'showDayCounter' => $showDayCounter,
                                            'currentDay' => $currentDay,
                                            'totalDays' => $totalDays,
                                            'showAvatars' => $showAvatars,
                                            'taskUsers' => $taskUsers,
                                            'taskVendor' => $taskVendor,
                                            'showProjectInfo' => false,
                                        ])
                                    </flux:kanban.card>
                                @else
                                    <flux:kanban.card
                                        class="min-w-0 w-full transition hover:bg-zinc-50 dark:hover:bg-zinc-800 hover:shadow-sm hover:border-zinc-300 dark:hover:border-zinc-600"
                                        wire:key="upcoming-task-{{ $task->id }}-{{ $date }}"
                                    >
                                        @include('components.upcoming-tasks-list-card-content', [
                                            'task' => $task,
                                            'taskTypeTextClasses' => $taskTypeTextClasses,
                                            'arrivalTimeLabel' => $arrivalTimeLabel,
                                            'showDayCounter' => $showDayCounter,
                                            'currentDay' => $currentDay,
                                            'totalDays' => $totalDays,
                                            'showAvatars' => $showAvatars,
                                            'taskUsers' => $taskUsers,
                                            'taskVendor' => $taskVendor,
                                            'showProjectInfo' => false,
                                        ])
                                    </flux:kanban.card>
                                @endif
                            @endforeach
                        </flux:kanban.column.cards>
                    </flux:kanban.column>
                </flux:kanban>
            @endforeach
        </div>
    @else
    <div class="space-y-2 pl-0">
        @foreach($tasks as $task)
            @php
                $typeUi = $task->type_ui ?? [];
                $taskTypeTextClasses = data_get($typeUi, 'text', '');
                $taskUsers = $task->users ?? collect();
                $taskVendor = $task->vendor ?? null;
                
                // Calculate day counter for multi-day tasks
                $selectedDates = data_get($task->options, 'dates', []);
                $totalDays = count($selectedDates);
                $currentDay = 0;
                $showDayCounter = false;
                
                if (!empty($selectedDates)) {
                    sort($selectedDates);
                    $currentDay = array_search($date, $selectedDates);
                    if ($currentDay !== false) {
                        $currentDay++;
                    }
                    $showDayCounter = $totalDays > 1 && $currentDay > 0;
                }
                
                // Arrival time
                $arrivalTimeLabel = null;
                $dayFormat = $carbonDate->format('Y-m-d');
                $dayTimeSettings = data_get($task->options, "time_settings.$dayFormat");
                $dayUsesTime = (bool) data_get($dayTimeSettings, 'use_time', false);
                $dayStartTime = (string) data_get($dayTimeSettings, 'start_time', '');
                if ($dayUsesTime && $dayStartTime !== '') {
                    try {
                        $arrivalTimeLabel = \Carbon\Carbon::createFromFormat('H:i', $dayStartTime)->format('g:i A');
                    } catch (\Exception $e) {
                        $arrivalTimeLabel = null;
                    }
                }
            @endphp
            
            @if($clickable)
                <flux:kanban.card
                    as="button"
                    class="min-w-0 w-full"
                    wire:key="upcoming-task-{{ $task->id }}-{{ $date }}"
                    wire:click="$dispatchTo('tasks.task-create', 'editTask', { task: {{ $task->id }} })"
                >
                    @include('components.upcoming-tasks-list-card-content', [
                        'task' => $task,
                        'taskTypeTextClasses' => $taskTypeTextClasses,
                        'arrivalTimeLabel' => $arrivalTimeLabel,
                        'showDayCounter' => $showDayCounter,
                        'currentDay' => $currentDay,
                        'totalDays' => $totalDays,
                        'showAvatars' => $showAvatars,
                        'taskUsers' => $taskUsers,
                        'taskVendor' => $taskVendor,
                        'showProjectInfo' => false,
                    ])
                </flux:kanban.card>
            @else
                <flux:kanban.card
                    class="min-w-0 w-full transition hover:bg-zinc-50 dark:hover:bg-zinc-800 hover:shadow-sm hover:border-zinc-300 dark:hover:border-zinc-600"
                    wire:key="upcoming-task-{{ $task->id }}-{{ $date }}"
                >
                    @include('components.upcoming-tasks-list-card-content', [
                        'task' => $task,
                        'taskTypeTextClasses' => $taskTypeTextClasses,
                        'arrivalTimeLabel' => $arrivalTimeLabel,
                        'showDayCounter' => $showDayCounter,
                        'currentDay' => $currentDay,
                        'totalDays' => $totalDays,
                        'showAvatars' => $showAvatars,
                        'taskUsers' => $taskUsers,
                        'taskVendor' => $taskVendor,
                        'showProjectInfo' => false,
                    ])
                </flux:kanban.card>
            @endif
        @endforeach
    </div>
    @endif
@endif
