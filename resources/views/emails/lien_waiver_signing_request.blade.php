{{-- No header-brand text: the sender line already says Hive Contractors,
     and the brand span would lead the inbox preview text. --}}
<x-mail::message>
@php
    $projectLink = $projectUrl
        ? '<a href="' . e($projectUrl) . '" style="color: #4f46e5;">' . e($projectLabel) . '</a>'
        : '<strong>' . e($projectLabel) . '</strong>';
    $phoneDigits = preg_replace('/\D+/', '', $contractorPhone);
    $phoneHtml = $contractorPhone !== ''
        ? ' — <a href="tel:+1' . e($phoneDigits) . '" style="color: #4f46e5; white-space: nowrap;">' . e($contractorPhone) . '</a>'
        : '';

@endphp

@if($forwardVendorName)
{{-- Vendor has no email — this went to the draw's creator to pass along. --}}
<table align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 0 18px;">
<tr>
<td bgcolor="#fef3c7" style="border: 1px solid #fcd34d; background-color: #fef3c7; border-radius: 8px; padding: 12px 18px; text-align: center;">
<p style="margin: 0; font-size: 14px; font-weight: 600; color: #92400e; line-height: 1.5;">{{ __('lien_waiver.forward_notice', ['vendor' => $forwardVendorName]) }}</p>
</td>
</tr>
</table>
@endif

<div style="text-align: center;">
<h1 class="title" style="text-align: center;">{{ __('lien_waiver.email_title') }}</h1>
<p class="text" style="text-align: center;">
{{ $recipientName ? __('lien_waiver.greeting', ['name' => $recipientName]) : __('lien_waiver.greeting_fallback') }}<br>
{!! __('lien_waiver.intro_request') !!}<br>
{!! $projectLink !!}.<br>
{!! __('lien_waiver.intro_filled') !!}
</p>
</div>

<table align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 20px 0;">
<tr>
<td bgcolor="#f4f4f5" style="background-color: #f4f4f5; border-radius: 8px; padding: 22px 28px; text-align: center;">
@foreach(['print', 'sign', 'notarize', 'return'] as $step)
<p style="margin: 0 {{ $loop->last ? '' : '0 18px' }}; text-align: center; font-size: 14px; color: #18181b; line-height: 1.6;">
<img src="cid:waiver-step-{{ $step }}" width="24" height="24" alt="" style="vertical-align: middle; margin-right: 7px;"><strong style="vertical-align: middle;">{!! __("lien_waiver.step_{$step}_title") !!}</strong><br>
{!! __("lien_waiver.step_{$step}_body") !!}
@if(\Illuminate\Support\Facades\Lang::has("lien_waiver.step_{$step}_body2"))
<br>{!! __("lien_waiver.step_{$step}_body2") !!}
@endif
</p>
@endforeach
</td>
</tr>
</table>

<div style="text-align: center;">
<p class="text" style="text-align: center;">
{!! __('lien_waiver.help', ['phone' => $phoneHtml]) !!}<br>
{!! __('lien_waiver.urgency') !!}
</p>
<p class="text" style="text-align: center;">
{{ __('lien_waiver.thanks') }}<br>
<strong>{{ $contractorName }}</strong>
</p>
</div>

<x-mail.cta />
</x-mail::message>
