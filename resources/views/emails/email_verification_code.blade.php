<x-mail::message :show-header-brand="true">
@php
	$homeUrl = url('/');
	$host = (string) (parse_url($homeUrl, PHP_URL_HOST) ?: $homeUrl);
@endphp

{{-- Preheader text: shows in notification previews / inbox list but hidden in email body --}}
<div style="display:none;font-size:1px;color:#ffffff;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">
Your verification code is {{ $verification_code }}
</div>

<div style="text-align: center;">
<h1 class="title" style="text-align: center;">Your verification code</h1>
<p class="text" style="margin-top: 10px; text-align: center;">Enter this 6-digit code to continue signing in.</p>

<div style="height: 6px; line-height: 6px;">&nbsp;</div>

<div class="code" style="padding: 10px 10px;">
<span style="display: inline-block; white-space: nowrap;">{{ $verification_code }}</span>
</div>

<div style="height: 6px; line-height: 6px;">&nbsp;</div>
</div>
</x-mail::message>
