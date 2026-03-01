<x-mail::message :show-header-brand="true">
@php
$baseUrl = rtrim((string) config('app.url'), '/');
$typeColors = [
'Task' => '#4f46e5',
'Milestone' => '#6366f1',
'Meet' => '#ea580c',
'Reminder' => '#e11d48',
];
$taskCount = $tasks->count();
$taskWord = $taskCount === 1 ? 'task' : 'tasks';
@endphp

<div style="text-align: center;">
<h1 class="title" style="text-align: center; margin: 0 0 14px 0;">{{ $taskCount === 1 ? 'New Task' : $taskCount . ' New Tasks' }} for Your Project</h1>
@if($recipientName)
<p class="text" style="margin-top: 6px; text-align: center; color: #71717a;">Hi {{ $recipientName }},</p>
@endif
<p style="margin-top: 10px; text-align: center; color: #3f3f46; font-size: 15px; line-height: 1.5;">
<strong>{{ $contractorName }}</strong> has added {{ $taskCount === 1 ? 'a new task' : $taskCount . ' new tasks' }} for
@if($projectAddress || $projectName)
{{ $projectAddress }}@if($projectAddress && $projectName) | @endif{{ $projectName }}
@else
your project
@endif
</p>
</div>

<div style="height: 16px; line-height: 16px;">&nbsp;</div>

{{-- Task cards --}}
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0 auto; max-width: 420px;">
@foreach($tasks as $task)
@php
$taskType = $task->type ?? 'Task';
$titleColor = $typeColors[$taskType] ?? $typeColors['Task'];
@endphp
<tr><td style="padding: 3px 0;">
<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td class="task-card" style="padding: 10px 14px; border: 1px solid #d4d4d8; border-radius: 8px;">
{{-- Task title + pending badge --}}
<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td style="font-size: 14px; font-weight: 600;"><span style="color: {{ $titleColor }};">{{ $task->title }}</span>@if(!$task->start_date) <span style="display: inline-block; padding: 2px 10px; font-size: 12px; font-weight: 500; color: #92400e; background-color: #fef3c7; border-radius: 9999px; margin-left: 8px; vertical-align: middle;">Pending</span>@endif</td>
</tr></table>
@if($task->start_date)
<div style="margin-top: 4px; font-size: 13px; color: #71717a;">{{ $task->date_with_time }}</div>
@endif
</td></tr></table>
</td></tr>
@endforeach
</table>

@php
$hasPending = $tasks->contains(fn ($t) => !$t->start_date);
@endphp

@if($hasPending)
<div style="height: 8px; line-height: 8px;">&nbsp;</div>
<p style="text-align: center; font-size: 14px; color: #52525b; line-height: 1.5; margin: 0;">
We'll let you know as soon as {{ $taskCount === 1 ? "it's" : "they're" }} scheduled.
</p>
@endif

<div style="height: 20px; line-height: 20px;">&nbsp;</div>

{{-- Registration CTA card --}}
<div style="text-align: center; background-color: #f4f4f5; border-radius: 8px; padding: 20px 24px; margin: 0 auto; max-width: 420px;">
<p style="margin: 0 0 12px 0; font-size: 15px; color: #3f3f46; line-height: 1.5;">
<strong>{{ $contractorName }}</strong> uses <a href="{{ $baseUrl }}" style="color: #4f46e5; text-decoration: none; font-weight: 600;">Hive Contractors</a> to manage your project.
Register today to access your schedule, project updates, and stay connected with {{ $contractorName }}. Registering is easy — just confirm your number, email, and set a password.
</p>
<div style="height: 8px; line-height: 8px;">&nbsp;</div>
<a href="{{ $registerUrl }}" style="display: inline-block; padding: 12px 32px; background-color: #4f46e5; color: #ffffff; font-size: 15px; font-weight: 600; text-decoration: none; border-radius: 6px;">Register for Hive</a>
</div>

</x-mail::message>
