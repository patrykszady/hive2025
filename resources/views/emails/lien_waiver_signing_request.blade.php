@component('mail::message')
# {{ $waiverTypeLabel }}

Hi {{ $recipientName ?: 'there' }},

**{{ $contractorName }}** has prepared a lien waiver for your signature in the amount of **{{ $amountFormatted }}**
@if(! empty($projectLabel))
for the project **{{ $projectLabel }}**
@endif
@if(! empty($throughDate))
through **{{ $throughDate }}**
@endif.

Please review the attached PDF, then click the button below to sign electronically.

@component('mail::button', ['url' => $signingUrl])
Review &amp; Sign Lien Waiver
@endcomponent

If the button doesn't work, copy and paste this link into your browser:
{{ $signingUrl }}

Thanks,
{{ config('app.name') }}
@endcomponent
