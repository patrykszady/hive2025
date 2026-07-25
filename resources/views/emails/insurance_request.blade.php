{{-- No header-brand text: the sender line already says Hive Contractors,
     and the brand span would lead the inbox preview text. --}}
<x-mail::message>
@php
    $baseUrl = rtrim((string) config('app.url'), '/');
    $holderName = $requesting_vendor->short_name ?? $requesting_vendor->name;
    $requestingVendorUrl = $baseUrl . '/vendors/' . $requesting_vendor->id;
    $insuredVendorUrl = $baseUrl . '/vendors/' . $vendor->id;

    $fmtAddress = fn ($v) => trim(implode(', ', array_filter([
        trim(implode(' ', array_filter([$v->address, $v->address_2]))),
        $v->city,
        trim(($v->state ?? '') . ' ' . ($v->zip_code ?? '')),
    ])));

    $label = 'padding: 12px 0; font-size: 13px; color: #71717a; text-align: left; vertical-align: top; white-space: nowrap;';
    $value = 'padding: 12px 0 12px 16px; font-size: 14px; color: #18181b; font-weight: 600; text-align: right;';
    $divider = 'border-top: 1px solid #f4f4f5;';

    $intro = __('insurance_request.intro', [
        'holder' => '<a href="' . $requestingVendorUrl . '" style="color: #4f46e5; text-decoration: none; font-weight: 600;">' . e($holderName) . '</a>',
        'vendor' => '<a href="' . $insuredVendorUrl . '" style="color: #4f46e5; text-decoration: none; font-weight: 600;">' . e($vendor->name) . '</a>',
    ]);
@endphp

<div style="text-align: center;">
<h1 class="title" style="text-align: center;">{{ __('insurance_request.label_' . $requestType) }}</h1>
<p class="text" style="margin: 6px 0 0; text-align: center;">{!! $intro !!}</p>
<p class="text" style="margin: 6px 0 0; text-align: center;">{!! __('insurance_request.return_line', ['email' => '<strong>' . e(config('nylas.certificates_email')) . '</strong>']) !!}</p>
</div>

{{-- Insured / Holder — island-card style label/value rows. --}}
<table align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 18px 0 4px;">
<tr>
<td bgcolor="#ffffff" style="border: 1px solid #e4e4e7; background-color: #ffffff; border-radius: 12px; padding: 2px 20px;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td style="{{ $label }}">{{ __('insurance_request.insured') }}</td>
<td style="{{ $value }}"><a href="{{ $insuredVendorUrl }}" style="color: #4f46e5; text-decoration: none;">{{ $vendor->name }}</a></td>
</tr>
<tr>
<td style="{{ $label }} {{ $divider }}">{{ __('insurance_request.address') }}</td>
<td style="{{ $value }} {{ $divider }} font-weight: 400;">{{ $fmtAddress($vendor) }}</td>
</tr>
<tr>
<td style="{{ $label }} {{ $divider }}">{{ __('insurance_request.holder') }}</td>
<td style="{{ $value }} {{ $divider }}"><a href="{{ $requestingVendorUrl }}" style="color: #4f46e5; text-decoration: none;">{{ $requesting_vendor->name }}</a></td>
</tr>
<tr>
<td style="{{ $label }} {{ $divider }}">{{ __('insurance_request.address') }}</td>
<td style="{{ $value }} {{ $divider }} font-weight: 400;">{{ $fmtAddress($requesting_vendor) }}</td>
</tr>
</table>
</td>
</tr>
</table>

@if(!empty($agent_expired_docs) && count($agent_expired_docs) > 0)
{{-- Expired / expiring documents. --}}
<table align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 12px 0 4px;">
<tr>
<td bgcolor="#ffffff" style="border: 1px solid #e4e4e7; background-color: #ffffff; border-radius: 12px; padding: 2px 20px;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td style="padding: 12px 0 6px; font-size: 11px; font-weight: 600; color: #a1a1aa; text-transform: uppercase; letter-spacing: 0.05em; text-align: left;">{{ __('insurance_request.policy') }}</td>
<td style="padding: 12px 0 6px; font-size: 11px; font-weight: 600; color: #a1a1aa; text-transform: uppercase; letter-spacing: 0.05em; text-align: left;">{{ __('insurance_request.number') }}</td>
<td style="padding: 12px 0 6px; font-size: 11px; font-weight: 600; color: #a1a1aa; text-transform: uppercase; letter-spacing: 0.05em; text-align: right;">{{ __('insurance_request.expiration') }}</td>
</tr>
@foreach($agent_expired_docs as $doc)
@php
    $typeKey = 'insurance_request.policy_' . strtolower((string) $doc->type);
    $policyName = \Illuminate\Support\Facades\Lang::has($typeKey) ? __($typeKey) : \Illuminate\Support\Str::headline((string) $doc->type);
    $isExpired = $doc->expiration_date?->isPast();
@endphp
<tr>
<td style="padding: 10px 0; font-size: 14px; color: #18181b; text-align: left; {{ $divider }}">{{ $policyName }}</td>
<td style="padding: 10px 0; font-size: 14px; color: #71717a; text-align: left; {{ $divider }}">{{ $doc->number }}</td>
<td style="padding: 10px 0; font-size: 14px; font-weight: 600; text-align: right; {{ $divider }} color: {{ $isExpired ? '#dc2626' : '#18181b' }};">{{ $doc->expiration_date->format('m/d/Y') }}</td>
</tr>
@endforeach
</table>
</td>
</tr>
</table>
@endif

<x-mail.cta />
</x-mail::message>
