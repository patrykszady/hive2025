<x-mail::message :hide-footer="true" :show-header-brand="true">
@php
	$homeUrl = url('/');
	$host = (string) (parse_url($homeUrl, PHP_URL_HOST) ?: $homeUrl);
@endphp

<div style="text-align: center;">
<h1 class="title" style="text-align: center;">Your verification code</h1>
<p class="text" style="margin-top: 10px; text-align: center;">Enter this 6-digit code to continue signing in.</p>

<div style="height: 6px; line-height: 6px;">&nbsp;</div>

<div class="code" style="padding: 10px 10px;">
<span style="display: inline-block; white-space: nowrap;">{{ $verification_code }}</span>
</div>

<div style="height: 6px; line-height: 6px;">&nbsp;</div>

<p class="muted" style="margin: 0; font-size: 13px; line-height: 18px; text-align: center;">
<a href="{{ $homeUrl }}" style="text-decoration: none; color: inherit;">Hive Contractors</a> ·
<a href="{{ $homeUrl }}" style="text-decoration: none; color: inherit;">{{ $host }}</a>
</p>
</div>
</x-mail::message>
