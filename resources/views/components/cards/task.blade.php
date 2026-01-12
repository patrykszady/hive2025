@props([
    'task',
    'showProject' => false,
    'showDate' => true,
    'date' => null,
])

@php
    $taskUsers = $task->users ?? collect();
    $taskVendor = $task->vendor;
    $typeUi = $task->type_ui ?? [];
    $taskTypeTextClasses = data_get($typeUi, 'text', '');

    // Calculate day counter for multi-day tasks
    $selectedDates = data_get($task->options, 'dates', []);
    $totalDays = count($selectedDates);
    $currentDay = 0;
    $showDayCounter = false;

    if ($date && !empty($selectedDates)) {
        $dayFormat = $date instanceof \Carbon\Carbon ? $date->format('Y-m-d') : $date;
        sort($selectedDates);
        $currentDay = array_search($dayFormat, $selectedDates);
        if ($currentDay !== false) {
            $currentDay++;
        }
        $showDayCounter = $totalDays > 1 && $currentDay > 0;
    }

    // Arrival time
    $arrivalTimeLabel = null;
    if ($date) {
        $dayFormat = $date instanceof \Carbon\Carbon ? $date->format('Y-m-d') : $date;
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
    }

    // Check if task is today
    $isToday = false;
    if ($task->start_date && $task->end_date) {
        $isToday = $task->start_date->isToday() || ($task->start_date->isPast() && $task->end_date->isFuture());
    }
@endphp

<flux:kanban.card
    as="button"
    {{ $attributes->class(['min-w-0 w-full']) }}
>
    <div class="flex items-start justify-between gap-2 min-w-0">
        <div class="flex items-center gap-2 min-w-0">
            <flux:heading size="sm" class="min-w-0 truncate {{ $taskTypeTextClasses }}">
                {{ $task->title }}
            </flux:heading>
            @if ($arrivalTimeLabel)
                <span class="shrink-0 text-xs text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
                    {{ $arrivalTimeLabel }}
                </span>
            @endif
            @if($isToday && $showDate)
                <flux:badge size="sm" :color="data_get($typeUi, 'flux', 'zinc')">Today</flux:badge>
            @endif
        </div>
        @if($showDayCounter)
            <span class="text-xs text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
                {{ $currentDay }}/{{ $totalDays }}
            </span>
        @elseif($showDate && $task->start_date)
            <span class="text-xs text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
                {{ $task->start_date->format('M j') }}
                @if($task->end_date && !$task->start_date->isSameDay($task->end_date))
                    - {{ $task->end_date->format('M j') }}
                @endif
            </span>
        @endif
    </div>

    @if($showProject && $task->project)
        <flux:text size="sm" class="text-zinc-500 mt-1 truncate">
            {{ $task->project->short_address ?? 'No project' }}
            @if($task->project->client)
                — {{ $task->project->client->name }}
            @endif
        </flux:text>
    @endif

    @if($taskUsers->count() > 0 || $taskVendor)
        <div class="flex items-center gap-2 mt-2 min-w-0">
            @if($taskUsers->count() > 0)
                <flux:avatar.group>
                    @foreach($taskUsers->take(3) as $user)
                        <flux:avatar
                            circle
                            size="xs"
                            name="{{ $user->full_name }}"
                            color="auto"
                            color:seed="{{ $user->id }}"
                            title="{{ $user->full_name }}"
                        />
                    @endforeach
                    @if($taskUsers->count() > 3)
                        <flux:avatar circle size="xs">{{ $taskUsers->count() - 3 }}+</flux:avatar>
                    @endif
                </flux:avatar.group>
            @endif

            @if($taskVendor)
                <flux:avatar
                    circle
                    size="xs"
                    name="{{ $taskVendor->name }}"
                    color="auto"
                    color:seed="{{ $taskVendor->id }}"
                    title="{{ $taskVendor->name }}"
                />
                <span class="flex-1 min-w-0 truncate text-xs text-zinc-600 dark:text-zinc-400">{{ $taskVendor->name }}</span>
                
                @if($task->vendor_status)
                    @php $statusUi = $task->vendor_status_ui; @endphp
                    <flux:badge 
                        size="sm" 
                        :color="$statusUi['flux'] ?? 'zinc'"
                        :icon="$statusUi['icon'] ?? null"
                    >
                        {{ $statusUi['label'] ?? ucfirst($task->vendor_status) }}
                    </flux:badge>
                @endif
            @endif
        </div>
    @endif
</flux:kanban.card>
