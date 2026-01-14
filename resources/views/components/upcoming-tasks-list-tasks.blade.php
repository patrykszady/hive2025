{{-- Task Cards for this date --}}
@if($tasks->isEmpty())
    @if(!$carbonDate->isWeekend())
        <div class="text-sm text-zinc-400 dark:text-zinc-500 italic pl-1">
            No tasks scheduled
        </div>
    @endif
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
                        'showProjectInfo' => $showProjectInfo ?? false,
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
                        'showProjectInfo' => $showProjectInfo ?? false,
                    ])
                </flux:kanban.card>
            @endif
        @endforeach
    </div>
@endif
