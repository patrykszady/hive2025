@php
    // Derive defaults from $task + $date if not explicitly passed
    $typeUi = $task->type_ui ?? [];
    $taskTypeTextClasses = $taskTypeTextClasses ?? data_get($typeUi, 'text', '');
    $taskUsers = $taskUsers ?? ($task->users ?? collect());
    $taskVendor = $taskVendor ?? ($task->vendor ?? null);
    $showAvatars = $showAvatars ?? true;
    $showProjectInfo = $showProjectInfo ?? false;
    $showVendorInfo = $showVendorInfo ?? true;

    // Arrival time — use model method if not pre-computed
    if (! isset($arrivalTimeLabel)) {
        $dayFormat = isset($date)
            ? ($date instanceof \Carbon\Carbon ? $date->format('Y-m-d') : $date)
            : null;
        $arrivalTimeLabel = $dayFormat ? $task->getArrivalTimeLabel($dayFormat) : null;
    }

    // Day counter — compute from task options if not pre-set
    if (! isset($showDayCounter)) {
        $selectedDates = data_get($task->options, 'dates', []);
        $totalDays = count($selectedDates);
        $currentDay = 0;
        $showDayCounter = false;

        if (isset($date) && ! empty($selectedDates)) {
            $dayFormat = $date instanceof \Carbon\Carbon ? $date->format('Y-m-d') : $date;
            sort($selectedDates);
            $currentDay = array_search($dayFormat, $selectedDates);
            if ($currentDay !== false) {
                $currentDay++;
            }
            $showDayCounter = $totalDays > 1 && $currentDay > 0;
        }
    }
@endphp

<div class="flex items-start justify-between gap-2 min-w-0">
    <div class="flex items-baseline gap-2 min-w-0">
        <flux:heading size="sm" class="min-w-0 truncate {{ $taskTypeTextClasses }}">
            {{ $task->title }}
        </flux:heading>
        @if ($arrivalTimeLabel)
            <span class="shrink-0 text-xs text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
                {{ $arrivalTimeLabel }}
            </span>
        @endif
    </div>
    @if($showDayCounter)
        <span class="text-xs text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
            {{ $currentDay }}/{{ $totalDays }}
        </span>
    @endif
</div>

@if(($showProjectInfo ?? false) && $task->project)
    <div class="mt-1 space-y-0.5">
        <a 
            href="{{ route('projects.show', $task->project) }}"
            wire:click.stop
            class="text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300 hover:underline truncate"
        >
            {{ $task->project->short_address ?? 'No project' }}
        </a>
        @if($task->project->client)
            <div class="text-xs text-zinc-500 dark:text-zinc-400 truncate">
                {{ $task->project->client->last_names }}
            </div>
        @endif
    </div>
@endif

@if($showAvatars && ($taskUsers->count() > 0 || $taskVendor))
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
                :title="($showVendorInfo ?? true) ? $taskVendor->name : null"
            />
            @if($showVendorInfo ?? true)
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
        @endif
    </div>
@endif
