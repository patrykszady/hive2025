@props([
    'task',
    'showProject' => true,
])

{{-- Vendor-facing meta rows: tappable map address + .ics time download.
     Sits under the shared task-card content on the public schedule page —
     the card look stays identical to hub/planner, these rows add what a
     sub on their phone needs (extracted from schedule/task-card-body). --}}
@php
    $metaCityStateZip = trim(implode(' ', array_filter([
        collect([$task->project?->city, $task->project?->state])->filter()->implode(', '),
        $task->project?->zip_code,
    ])));

    $metaStart = $task->start_date?->format('Ymd\THis');
    $metaEnd = $task->end_date?->format('Ymd\THis') ?? $task->start_date?->copy()->addHours(2)->format('Ymd\THis');
    $metaLocation = $task->project?->full_address ?? '';

    $metaIcs = "BEGIN:VCALENDAR\r\n"
        . "VERSION:2.0\r\n"
        . "BEGIN:VEVENT\r\n"
        . "DTSTART:{$metaStart}\r\n"
        . "DTEND:{$metaEnd}\r\n"
        . "SUMMARY:{$task->title}\r\n"
        . "LOCATION:{$metaLocation}\r\n"
        . 'DESCRIPTION:Task for ' . ($task->owner?->name ?? 'Hive') . "\r\n"
        . "END:VEVENT\r\n"
        . "END:VCALENDAR";
    $metaCalendarUrl = 'data:text/calendar;charset=utf-8,' . rawurlencode($metaIcs);

    $metaTimeLabel = $task->date_with_time;
    if ($task->start_date) {
        $metaTimeLabel = str_replace(', ' . $task->start_date->format('Y'), '', $metaTimeLabel);
    }
    $metaHasTime = str_contains((string) $metaTimeLabel, ' @ ');
    if ($metaHasTime) {
        $metaTimeLabel = trim(substr($metaTimeLabel, strpos($metaTimeLabel, ' @ ') + 3));
    }
@endphp

@if(($task->project?->address) || $metaHasTime)
    <div class="mt-2 space-y-1">
        @if($task->project?->address)
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
                        @if($metaCityStateZip)
                            <div>{{ $metaCityStateZip }}</div>
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
        @endif

        @if($metaHasTime)
            <div class="flex items-center gap-1.5 text-sm text-zinc-600 dark:text-zinc-400">
                <flux:icon.clock class="size-4 shrink-0" />
                <a
                    href="{{ $metaCalendarUrl }}"
                    download="{{ \Illuminate\Support\Str::slug($task->title) }}.ics"
                    class="hover:underline hover:text-zinc-900 dark:hover:text-zinc-200 transition-colors"
                >{{ $metaTimeLabel }}</a>
            </div>
        @endif
    </div>
@endif
