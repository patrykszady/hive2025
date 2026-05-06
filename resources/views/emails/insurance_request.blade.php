<x-mail::message :show-header-brand="true">
@php
	$baseUrl = rtrim((string) config('app.url'), '/');
	$requestingVendorUrl = $baseUrl . '/vendors/' . $requesting_vendor->id;
	$insuredVendorUrl = $baseUrl . '/vendors/' . $vendor->id;
@endphp

<div style="text-align: center;">
<h1 class="title" style="text-align: center;">{{ $requestLabel }}</h1>
<p class="text" style="margin: 6px 0 8px; text-align: center;"><a href="{{ $requestingVendorUrl }}">{{ $requesting_vendor->name }}</a> is requesting updated documents for <a href="{{ $insuredVendorUrl }}">{{ $vendor->name }}</a>.</p>
</div>

<hr style="border: none; border-top: 1px solid #cbd5e1; margin: 6px 0;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse; margin: 0;">
	<tr>
		<td style="padding: 6px 0; width: 120px; vertical-align: top;"><strong>Insured</strong></td>
		<td style="padding: 6px 0; vertical-align: top;"><a href="{{ $insuredVendorUrl }}">{{ $vendor->name }}</a></td>
	</tr>
	<tr>
		<td style="padding: 6px 0; width: 120px; vertical-align: top;">Address</td>
		<td style="padding: 6px 0; vertical-align: top;">{{ $vendor->address }}@if(!is_null($vendor->address_2)), {{ $vendor->address_2 }}@endif, {{ $vendor->city }}, {{ $vendor->state }} {{ $vendor->zip_code }}</td>
	</tr>
</table>

<hr style="border: none; border-top: 1px solid #cbd5e1; margin: 6px 0;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse; margin: 0;">
	<tr>
		<td style="padding: 6px 0; width: 120px; vertical-align: top;"><strong>Holder</strong></td>
		<td style="padding: 6px 0; vertical-align: top;"><a href="{{ $requestingVendorUrl }}">{{ $requesting_vendor->name }}</a></td>
	</tr>
	<tr>
		<td style="padding: 6px 0; width: 120px; vertical-align: top;">Address</td>
		<td style="padding: 6px 0; vertical-align: top;">{{ $requesting_vendor->address }}@if(!is_null($requesting_vendor->address_2)), {{ $requesting_vendor->address_2 }}@endif, {{ $requesting_vendor->city }}, {{ $requesting_vendor->state }} {{ $requesting_vendor->zip_code }}</td>
	</tr>
</table>

@if(!empty($agent_expired_docs) && count($agent_expired_docs) > 0)
<hr style="border: none; border-top: 2px solid #cbd5e1; margin: 8px 0 6px;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse; margin: 0;">
	<thead>
		<tr>
			<th align="left" style="padding: 8px 0; border-bottom: 1px solid #cbd5e1;">Policy</th>
			<th align="left" style="padding: 8px 0; border-bottom: 1px solid #cbd5e1;">Number</th>
			<th align="left" style="padding: 8px 0; border-bottom: 1px solid #cbd5e1;">Expiration</th>
		</tr>
	</thead>
	<tbody>
		@foreach($agent_expired_docs as $agent_expired_doc)
			<tr>
				<td style="padding: 8px 0; vertical-align: top;">{{ \Illuminate\Support\Str::headline((string) $agent_expired_doc->type) }}</td>
				<td style="padding: 8px 0; vertical-align: top;">{{ $agent_expired_doc->number }}</td>
				<td style="padding: 8px 0; vertical-align: top;">{{ $agent_expired_doc->expiration_date->format('m/d/Y') }}</td>
			</tr>
		@endforeach
	</tbody>
</table>
@endif

@include('emails.top_footer', ["sending_vendor" => $requesting_vendor->name])
</x-mail::message>
