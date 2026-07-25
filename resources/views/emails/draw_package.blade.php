{{-- No header-brand text: the sender line already says Hive Contractors,
     and the brand span would lead the inbox preview text. --}}
<x-mail::message>
<div style="text-align: center;">
<h1 class="title" style="text-align: center;">Draw {{ $drawNumber }} Package</h1>
<p class="text" style="text-align: center;">
{{ $recipientName !== '' ? 'Hi ' . $recipientName . ',' : 'Hi,' }}<br>
Attached are the sworn statement (GCSS) and {{ $contractorName }}'s own lien waiver for<br>
@if($projectUrl)
<a href="{{ $projectUrl }}" style="color: #4f46e5;">{{ $projectLabel }}</a>.<br>
@else
<strong>{{ $projectLabel }}</strong>.<br>
@endif
Sub waivers were emailed to each vendor separately — track their status on the project's Lien Waivers card.
</p>
</div>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
