<x-mail::message :show-header-brand="true">
@php
	$baseUrl = rtrim((string) config('app.url'), '/');
@endphp

<div style="text-align: center;">
<h1 class="title" style="text-align: center;">{{ $vendorName ?: "You're Invited" }}</h1>
<p class="text" style="margin-top: 10px; text-align: center;">
	Hi {{ $vendorName ?: 'there' }},
	<strong>{{ $inviterName }}</strong> invited you to collaborate on
	<strong>{{ $projectName }}</strong> in
	<a href="{{ $baseUrl }}">Hive Contractors</a>.
</p>
</div>

<table align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 20px 0;">
<tr>
<td align="center" bgcolor="#f4f4f5" style="background-color: #f4f4f5 !important; border-radius: 8px; padding: 20px 24px; text-align: center;">
<p style="margin: 0; font-size: 12px; color: #71717a !important; text-transform: uppercase; letter-spacing: 0.05em; text-align: center;">Project</p>
<p style="margin: 6px 0 0; font-size: 18px; color: #18181b !important; font-weight: 600; text-align: center;">{{ $projectName }}</p>
@if($projectAddress)
<p style="margin: 6px 0 0; font-size: 14px; line-height: 1.5; text-align: center;">
<a href="{{ $projectUrl }}" style="color: #52525b !important; text-decoration: none !important; pointer-events: none;">{!! nl2br(e($projectAddress)) !!}</a>
</p>
@endif
</td>
</tr>
</table>

@if($customMessage)
<table align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 16px 0;">
<tr>
<td align="center" style="background-color: #f4f4f5; border-radius: 8px; padding: 16px 24px; font-size: 14px; color: #3f3f46; line-height: 1.6; text-align: center;">
{!! nl2br(e($customMessage)) !!}
</td>
</tr>
</table>
@endif

<x-mail::button :url="$projectUrl">
Join Project
</x-mail::button>

<div style="text-align: center;">
<p class="text" style="text-align: center;">
	<a href="{{ $baseUrl }}">Hive Contractors</a> lets you manage <strong>finances, estimates, timesheets, schedules</strong>,
	and more &mdash; all in one place. Accept the invite to start collaborating with {{ $inviterName }}.
</p>
</div>
</x-mail::message>
