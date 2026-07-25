{{-- No header-brand text: the sender line already says Hive Contractors,
     and the brand span would lead the inbox preview text. --}}
<x-mail::message>
@php
    $baseUrl = rtrim((string) config('app.url'), '/');
    $payingName = $paying_vendor->short_name ?? $paying_vendor->name;
    // Localize the payment type ("Check"/"Cash"/"Transfer") when we have a
    // translation; unknown types print as stored.
    $typeKey = 'vendor_payment.type_' . strtolower((string) $check->check_type);
    $typeLabel = \Illuminate\Support\Facades\Lang::has($typeKey) ? __($typeKey) : $check->payment_label;
    $paymentValue = $check->check_type === 'Check' ? $check_number : $typeLabel;
    $projectExpenses = $check->expenses->filter(fn ($expense) => $expense->project);

    $label = 'padding: 12px 0; font-size: 13px; color: #71717a; text-align: left; vertical-align: top;';
    $value = 'padding: 12px 0; font-size: 14px; color: #18181b; font-weight: 600; text-align: right;';
    $divider = 'border-top: 1px solid #f4f4f5;';
@endphp

<div style="text-align: center;">
<h1 class="title" style="text-align: center;">{{ __('vendor_payment.title', ['contractor' => $payingName]) }}</h1>
</div>

{{-- Hero amount, like the app's XL amount treatment. --}}
<p style="margin: 22px 0 2px; font-size: 32px; font-weight: 800; color: #18181b; text-align: center; letter-spacing: -0.02em;">{{ money($check->amount) }}</p>
<p style="margin: 0 0 6px; font-size: 13px; color: #71717a; text-align: center;">{{ $check->date->format('m/d/Y') }}</p>

{{-- Details card — zinc border + label/value rows, island-card style. --}}
<table align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 16px 0 4px;">
<tr>
<td bgcolor="#ffffff" style="border: 1px solid #e4e4e7; background-color: #ffffff; border-radius: 12px; padding: 2px 20px;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
@if($check->bank_account)
<tr>
<td style="{{ $label }}">{{ __('vendor_payment.bank') }}</td>
<td style="{{ $value }}">{{ $check->bank_account->getNameAndType() }}</td>
</tr>
@endif
<tr>
<td style="{{ $label }} {{ $check->bank_account ? $divider : '' }}">{{ $typeLabel }}</td>
<td style="{{ $value }} {{ $check->bank_account ? $divider : '' }}">{{ $paymentValue }}</td>
</tr>
@if($projectExpenses->count() === 1)
<tr>
<td style="{{ $label }} {{ $divider }}">{{ __('vendor_payment.project') }}</td>
<td style="{{ $value }} {{ $divider }}"><a href="{{ $baseUrl }}/projects/{{ $projectExpenses->first()->project->id }}" style="color: #4f46e5; text-decoration: none;">{{ $projectExpenses->first()->project->name }}</a></td>
</tr>
@endif
</table>
</td>
</tr>
</table>

@if($projectExpenses->count() > 1)
{{-- Split across projects — one row per project, amounts right-aligned. --}}
<table align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 12px 0 4px;">
<tr>
<td bgcolor="#ffffff" style="border: 1px solid #e4e4e7; background-color: #ffffff; border-radius: 12px; padding: 2px 20px;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td style="padding: 12px 0 6px; font-size: 11px; font-weight: 600; color: #a1a1aa; text-transform: uppercase; letter-spacing: 0.05em; text-align: left;">{{ __('vendor_payment.project') }}</td>
<td style="padding: 12px 0 6px; font-size: 11px; font-weight: 600; color: #a1a1aa; text-transform: uppercase; letter-spacing: 0.05em; text-align: right;">{{ __('vendor_payment.amount') }}</td>
</tr>
@foreach($projectExpenses as $expense)
<tr>
<td style="padding: 10px 0; font-size: 14px; text-align: left; {{ $divider }}"><a href="{{ $baseUrl }}/projects/{{ $expense->project->id }}" style="color: #4f46e5; text-decoration: none;">{{ $expense->project->name }}</a></td>
<td style="padding: 10px 0; font-size: 14px; color: #18181b; font-weight: 600; text-align: right; {{ $divider }}">{{ money($expense->amount) }}</td>
</tr>
@endforeach
</table>
</td>
</tr>
</table>
@endif

<x-mail.cta />
</x-mail::message>
