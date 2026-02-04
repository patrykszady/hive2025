<x-mail::message :hide-footer="true" :show-header-brand="true">
@php
	$baseUrl = rtrim((string) config('app.url'), '/');
	$checkUrl = $baseUrl . '/checks/' . $check->id;
	$payingVendorUrl = $baseUrl . '/vendors/' . $paying_vendor->id;
	$receivingVendorUrl = $baseUrl . '/vendors/' . $vendor->id;
@endphp

<div style="text-align: center;">
<h1 class="title" style="text-align: center;">Payment added</h1>
<p class="text" style="margin-top: 10px; text-align: center;"><a href="{{ $payingVendorUrl }}">{{ $paying_vendor->name }}</a> added a payment for <a href="{{ $receivingVendorUrl }}">{{ $vendor->name }}</a>.</p>

</div>

<div style="height: 12px; line-height: 12px;">&nbsp;</div>

<x-mail::table>
| | |
| :-- | :-- |
| **Amount** | {{ money($check->amount) }} |
| **Payee** | <a href="{{ $receivingVendorUrl }}">{{ $check->owner }}</a> |
| **Date** | {{ $check->date->format('m/d/Y') }} |
@if($check->bank_account)
| **Bank** | {{ $check->bank_account->getNameAndType() }} |
@endif
| **Type** | {{ $check->check_type }} |
| **{{ $check->payment_label }}** | {{ $check_number }} |
</x-mail::table>

@if($check->expenses->isNotEmpty())
<x-mail::table>
| Amount | Project |
| :----- | :------ |
@foreach($check->expenses as $expense)
| {{ money($expense->amount) }} | <a href="{{ $baseUrl }}/projects/{{ $expense->project->id }}">{{ $expense->project->name }}</a> |
@endforeach
</x-mail::table>
@endif

@include('emails.top_footer', ['sending_vendor' => $paying_vendor->name])
</x-mail::message>
