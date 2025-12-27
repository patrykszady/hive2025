<x-mail::message :hide-footer="true" :show-header-brand="true">
@php
	$continueUrl = url('/registration') . '?' . http_build_query([
		'step' => 'verify-email',
		'code' => (string) $verification_code,
	]);

	$homeUrl = url('/');
	$host = (string) (parse_url($homeUrl, PHP_URL_HOST) ?: $homeUrl);
@endphp

<div style="text-align: center;">
<h1 class="title" style="text-align: center;">Verify your email</h1>
<p class="text" style="margin-top: 10px; text-align: center;">Copy this 6-digit code into the verification screen.</p>

<div style="height: 6px; line-height: 6px;">&nbsp;</div>

<div class="code" style="padding: 10px 10px;">
<span style="display: inline-block; white-space: nowrap;">{{ $verification_code }}</span>
</div>

<div style="height: 6px; line-height: 6px;">&nbsp;</div>

<x-mail::button :url="$continueUrl">Continue</x-mail::button>

<p class="muted" style="margin-top: 10px; font-size: 12px; line-height: 18px; text-align: center;">If you’re on the same device, use Continue to open the verification step.</p>

<div style="height: 10px; line-height: 10px;">&nbsp;</div>

<p class="muted" style="margin: 0; font-size: 13px; line-height: 18px; text-align: center;">
<a href="{{ $homeUrl }}" style="text-decoration: none; color: inherit;">Hive Contractors</a> ·
<a href="{{ $homeUrl }}" style="text-decoration: none; color: inherit;">{{ $host }}</a>
</p>
</div>
</x-mail::message>
