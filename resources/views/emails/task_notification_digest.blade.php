<x-mail::message :show-header-brand="true">
@php
$baseUrl = rtrim((string) config('app.url'), '/');
$typeColors = [
'Task' => '#4f46e5',
'Milestone' => '#6366f1',
'Meet' => '#ea580c',
'Reminder' => '#e11d48',
];
@endphp
<div style="text-align: center;">
<h1 class="title" style="text-align: center;">{{ $headline }}</h1>
<p class="text subtitle-text" style="margin-top: 6px; text-align: center; color: #71717a;">Hi {{ $user->first_name }}, here's your schedule.</p>
</div>
<div style="height: 16px; line-height: 16px;">&nbsp;</div>
@foreach($groupedTasks as $date => $tasks)
@php
$carbonDate = \Carbon\Carbon::parse($date);
$now = \Carbon\Carbon::now($userTz);
$isToday = $carbonDate->isSameDay($now);
$isTomorrow = $carbonDate->isSameDay($now->copy()->addDay());
$isWeekend = $carbonDate->isWeekend();
$isPast = $carbonDate->lt($now->copy()->startOfDay());
$dimDay = ($isWeekend && $tasks->isEmpty()) || ($isPast && $tasks->isEmpty());
$dateColor = $isToday ? '#4f46e5' : ($dimDay ? '#a1a1aa' : '#3f3f46');
@endphp
<div style="margin-bottom: 4px; margin-top: 20px;{{ $dimDay ? ' opacity: 0.5;' : '' }}">
<span class="{{ $isToday ? 'date-header-today' : 'date-header' }}" style="font-weight: 600; font-size: 14px; color: {{ $dateColor }}; text-decoration: none;">{{ $carbonDate->format('D') }},&zwnj; {{ $carbonDate->format('M') }}&zwnj; {{ $carbonDate->format('j') }},&zwnj; {{ $carbonDate->format('Y') }}</span>
@if($isToday)
<span class="badge-today" style="display: inline-block; margin-left: 8px; padding: 2px 8px; font-size: 11px; font-weight: 600; color: #3730a3; background-color: #e0e7ff; border-radius: 9999px;">Today</span>
@elseif($isTomorrow)
<span class="badge-tomorrow" style="display: inline-block; margin-left: 8px; padding: 2px 8px; font-size: 11px; font-weight: 600; color: #52525b; background-color: #f4f4f5; border-radius: 9999px;">Tomorrow</span>
@endif
@if($tasks->isEmpty())
<span class="badge-no-tasks" style="display: inline-block; margin-left: 8px; padding: 2px 10px; font-size: 12px; font-weight: 500; color: #a1a1aa; background-color: #f4f4f5; border-radius: 9999px;">No Tasks</span>
@endif
</div>
@if($tasks->isEmpty())
@else
{{-- Group tasks by project --}}
@php $tasksByProject = $tasks->groupBy(fn ($task) => $task->project_id ?? 0); @endphp
@foreach($tasksByProject as $projectId => $projectTasks)
@php
$project = $projectTasks->first()->project;
$projectUrl = $project ? $baseUrl . '/projects/' . $project->id : null;
$latestStatus = $project?->latestStatus;
$statusDot = '';
if ($latestStatus) {
    $dotColors = ['green' => '#22c55e', 'yellow' => '#eab308', 'red' => '#ef4444', 'blue' => '#3b82f6', 'indigo' => '#6366f1', 'zinc' => '#a1a1aa'];
    $dotColor = $dotColors[$latestStatus->badge_color ?? 'zinc'] ?? '#a1a1aa';
    $statusDot = '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background-color:' . $dotColor . ';margin-left:6px;vertical-align:middle;"></span>';
}
@endphp
@if($isClientUser)
{{-- Client user: tasks without project card wrapper --}}
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top: 8px; margin-bottom: 4px;">
@else
{{-- Team/Vendor: Project card wrapper --}}
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top: 8px; margin-bottom: 4px;">
<tr><td class="project-card" style="border: 1px solid #d4d4d8; border-radius: 10px; padding: 0; overflow: hidden;">
{{-- Project header --}}
<table width="100%" cellpadding="0" cellspacing="0" border="0">
<tr><td style="padding: 10px 14px 6px 14px;">
<span class="project-title" style="font-weight: 600; font-size: 14px; color: #18181b;">@if($projectUrl)<a href="{{ $projectUrl }}" class="project-title" style="color: #18181b; text-decoration: none;">{{ $project->short_address ?? 'No project' }}</a>@else No project @endif</span>{!! $statusDot !!}
@if($project?->client || $project?->project_name)
<div class="project-subtitle" style="font-size: 12px; color: #71717a; margin-top: 2px;">{{ $project?->client?->last_names }}{{ $project?->client?->last_names && $project?->project_name ? ' | ' : '' }}{{ $project?->project_name }}</div>
@endif
</td></tr>
</table>
{{-- Task cards inside the project --}}
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="padding: 4px 10px 10px 10px;">
@endif
@foreach($projectTasks as $task)
@php
$taskType = $task->type ?? 'Task';
$titleColor = $typeColors[$taskType] ?? $typeColors['Task'];
$taskVendor = $task->vendor ?? null;
$selectedDates = data_get($task->options, 'dates', []);
$totalDays = count($selectedDates);
$currentDay = 0;
$showDayCounter = false;
if (!empty($selectedDates)) {
    sort($selectedDates);
    $currentDay = array_search($date, $selectedDates);
    if ($currentDay !== false) { $currentDay++; }
    $showDayCounter = $totalDays > 1 && $currentDay > 0;
}
$dayFormat = $carbonDate->format('Y-m-d');
$arrivalTimeLabel = $task->getArrivalTimeLabel($dayFormat);
$taskUsers = $task->users ?? collect();
@endphp
<tr><td style="padding: 3px 0;">
<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td class="task-card" style="padding: 8px 12px; border: 1px solid #d4d4d8; border-radius: 8px;">
{{-- Task title row --}}
<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td style="font-size: 14px; font-weight: 600;"><a href="{{ $baseUrl }}/projects/{{ $task->project_id }}?task={{ $task->id }}" style="color: {{ $titleColor }}; text-decoration: none;">{{ $task->title }}</a>@if($arrivalTimeLabel) <span class="task-time" style="font-weight: 400; font-size: 12px; color: #71717a; margin-left: 8px;">{{ $arrivalTimeLabel }}</span>@endif</td>
@if($showDayCounter)<td class="task-day-counter" style="text-align: right; font-size: 12px; color: #71717a; white-space: nowrap;">{{ $currentDay }}/{{ $totalDays }}</td>@endif
</tr></table>
{{-- Avatars & vendor --}}
@if($taskUsers->count() > 0 || $taskVendor)
<table cellpadding="0" cellspacing="0" border="0" style="margin-top: 4px;"><tr>
@foreach($taskUsers->take(3) as $taskUser)
@php
$initials = collect(explode(' ', $taskUser->full_name))->map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)))->take(2)->join('');
$fluxBgColors = ['#fecaca','#fed7aa','#fde68a','#fef08a','#d9f99d','#bbf7d0','#a7f3d0','#99f6e4','#a5f3fc','#bae6fd','#bfdbfe','#c7d2fe','#ddd6fe','#e9d5ff','#f5d0fe','#fbcfe8','#fecdd3'];
$fluxTextColors = ['#991b1b','#9a3412','#92400e','#854d0e','#3f6212','#166534','#065f46','#115e59','#155e75','#075985','#1e40af','#3730a3','#5b21b6','#6b21a8','#86198f','#9d174d','#9f1239'];
$colorIndex = crc32((string) $taskUser->id) % 17;
$bgColor = $fluxBgColors[$colorIndex];
$textColor = $fluxTextColors[$colorIndex];
@endphp
<td style="padding-right: 2px;"><div style="width: 24px; height: 24px; border-radius: 50%; background-color: {{ $bgColor }}; color: {{ $textColor }}; font-size: 10px; font-weight: 600; line-height: 24px; text-align: center;">{{ $initials }}</div></td>
@endforeach
@if($taskVendor)
@php
$vendorInitials = collect(explode(' ', $taskVendor->name ?? 'Vendor'))->map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)))->take(2)->join('');
@endphp
<td style="padding-left: 4px;"><div style="width: 24px; height: 24px; border-radius: 50%; background-color: #dcfce7; color: #166534; font-size: 10px; font-weight: 600; line-height: 24px; text-align: center;">{{ $vendorInitials }}</div></td>
@if(!$isClientUser)
<td class="vendor-name" style="padding-left: 6px; font-size: 12px; color: #52525b;">{{ $taskVendor->name }}</td>
@endif
@endif
</tr></table>
@endif
</td></tr></table>
</td></tr>
@endforeach
@if($isClientUser)
</table>
@else
</table>
</td></tr>
</table>
@endif
@endforeach
@endif
@endforeach
<div style="height: 20px; line-height: 20px;">&nbsp;</div>
<div style="text-align: center;">
<a href="{{ $baseUrl }}" style="display: inline-block; padding: 10px 24px; background-color: #4f46e5; color: #ffffff; font-size: 14px; font-weight: 600; text-decoration: none; border-radius: 6px;">View Full Schedule</a>
</div>
</x-mail::message>
