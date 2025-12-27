<x-mail::message :hide-footer="true" :show-header-brand="true">
@php
	$checkUrl = 'https://dashboard.hive.contractors/checks/' . $check->id;
@endphp

<div style="text-align: center;">
<h1 class="title" style="text-align: center;">Payment recorded</h1>
<p class="text" style="margin-top: 10px; text-align: center;">{{ $paying_vendor->name }} recorded a payment.</p>

<div style="height: 12px; line-height: 12px;">&nbsp;</div>

<p class="muted" style="margin: 0; font-size: 13px; line-height: 18px; text-align: center;">Hi {{ $vendor->name }},</p>
</div>

<div style="height: 12px; line-height: 12px;">&nbsp;</div>

<x-mail::table>
| | |
| :-- | :-- |
| **Check** | [{{ $check_number }}]({{ $checkUrl }}) |
| **Date** | {{ $check->date->format('m/d/Y') }} |
| **Total** | {{ money($check->amount) }} |
</x-mail::table>

@if($check->expenses->isNotEmpty())
<x-mail::table>
| Amount | Project |
| :----- | :------ |
@foreach($check->expenses as $expense)
| {{ money($expense->amount) }} | [{{ $expense->project->name }}](https://dashboard.hive.contractors/projects/{{ $expense->project->id }}) |
@endforeach
</x-mail::table>
@endif

@include('emails.top_footer', ['sending_vendor' => $paying_vendor->name])
</x-mail::message>
