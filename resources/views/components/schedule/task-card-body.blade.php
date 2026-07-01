@props([
    'task',
    'date' => null,
    'showStatus' => true,
    'showOwner' => true,
    'showDayCounter' => true,
    'showAddress' => true,
    'showProject' => false,
])

@php
    $dayKey = $date instanceof \Carbon\Carbon ? $date->format('Y-m-d') : $date;

    $currentDay = 0;
    $totalDays = 0;
    $renderDayCounter = false;

    if ($showDayCounter && $dayKey) {
        $selectedDates = (array) data_get($task->options, 'dates', []);
        $totalDays = count($selectedDates);

        if (! empty($selectedDates)) {
            sort($selectedDates);
            $idx = array_search($dayKey, $selectedDates, true);

            if ($idx !== false) {
                $currentDay = $idx + 1;
            }

            $renderDayCounter = $totalDays > 1 && $currentDay > 0;
        }
    }

    $cityStateZip = trim(implode(' ', array_filter([
        collect([$task->project?->city, $task->project?->state])->filter()->implode(', '),
        $task->project?->zip_code,
    ])));

    $startDate = $task->start_date?->format('Ymd\THis');
    $endDate = $task->end_date?->format('Ymd\THis') ?? $task->start_date?->copy()->addHours(2)->format('Ymd\THis');
    $location = $task->project?->full_address ?? '';
    $description = 'Task for ' . ($task->owner?->name ?? 'Hive');

    $icsContent = "BEGIN:VCALENDAR\r\n"
        . "VERSION:2.0\r\n"
        . "BEGIN:VEVENT\r\n"
        . "DTSTART:{$startDate}\r\n"
        . "DTEND:{$endDate}\r\n"
        . "SUMMARY:{$task->title}\r\n"
        . "LOCATION:{$location}\r\n"
        . "DESCRIPTION:{$description}\r\n"
        . "END:VEVENT\r\n"
        . "END:VCALENDAR";

    $calendarUrl = 'data:text/calendar;charset=utf-8,' . rawurlencode($icsContent);

    $dateTimeLabel = $task->date_with_time;
    if ($task->start_date) {
        $dateTimeLabel = str_replace(', ' . $task->start_date->format('Y'), '', $dateTimeLabel);
    }
    // Strip date portion — only show the time (after " @ ")
    $hasScheduledTime = str_contains($dateTimeLabel, ' @ ');
    if ($hasScheduledTime) {
        $dateTimeLabel = trim(substr($dateTimeLabel, strpos($dateTimeLabel, ' @ ') + 3));
    }
@endphp

<div class="flex items-start justify-between gap-2 min-w-0">
    <div class="flex items-center gap-2 min-w-0">
        <flux:heading size="sm" class="min-w-0 truncate {{ data_get($task->type_ui, 'text', 'text-zinc-800 dark:text-zinc-200') }}">
            {{ $task->title }}
        </flux:heading>
    </div>
    <div class="flex items-center gap-2 shrink-0">
        @if($renderDayCounter)
            <span class="text-xs whitespace-nowrap text-zinc-500 dark:text-zinc-400">
                {{ $currentDay }}/{{ $totalDays }}
            </span>
        @endif
        @if($showStatus)
            <x-task-status-badge :status="$task->vendor_status" />
        @endif
    </div>
</div>

@if($showAddress || $hasScheduledTime || ($showProject && $task->project?->project_name))
<div class="mt-2 space-y-1">
    @if($showAddress && $task->project?->address)
        <div class="flex items-start gap-1.5 text-sm text-zinc-600 dark:text-zinc-400">
            <flux:icon.map-pin class="size-4 shrink-0 mt-0.5" />
            <div class="flex-1 min-w-0">
                <a
                    href="{{ $task->project->getAddressMapURI() }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="hover:underline hover:text-zinc-900 dark:hover:text-zinc-200 transition-colors"
                >
                    <div class="truncate">{{ $task->project->address }}</div>
                    @if($cityStateZip)
                        <div>{{ $cityStateZip }}</div>
                    @endif
                </a>
            </div>
            @if($showProject && $task->project?->project_name)
                <span class="flex items-center gap-1 text-xs whitespace-nowrap text-zinc-500 dark:text-zinc-400 shrink-0">
                    <flux:icon.briefcase class="size-3.5 shrink-0" />
                    {{ $task->project->project_name }}
                </span>
            @endif
        </div>
    @else
        @if($showAddress)
            <flux:subheading class="truncate">No location</flux:subheading>
        @endif
    @endif

    @if($hasScheduledTime)
        <div class="flex items-center gap-1.5 text-sm text-zinc-600 dark:text-zinc-400">
            <flux:icon.clock class="size-4 shrink-0" />
            <a
                href="{{ $calendarUrl }}"
                download="{{ \Illuminate\Support\Str::slug($task->title) }}.ics"
                class="hover:underline hover:text-zinc-900 dark:hover:text-zinc-200 transition-colors"
            >{{ $dateTimeLabel }}</a>
        </div>
    @endif
</div>
@endif

@if($showOwner && $task->owner)
    <div class="flex items-center gap-2 mt-3 min-w-0">
        <flux:avatar
            circle
            size="xs"
            name="{{ $task->owner->full_name ?? $task->owner->name }}"
            color="auto"
            color:seed="{{ $task->owner->id }}"
        />
        <span class="flex-1 min-w-0 truncate text-xs text-zinc-600 dark:text-zinc-400">
            {{ $task->owner->short_name ?? $task->owner->name }}
        </span>
    </div>
@endif
