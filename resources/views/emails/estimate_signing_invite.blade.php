<x-mail::message :show-header-brand="true">
<h1 style="text-align: center; margin: 0 0 14px 0;">Contract Ready for Signature</h1>

@if($recipientName)
<p style="text-align: center; margin: 0 0 10px 0;">Hi {{ $recipientName }},</p>
@endif

<p style="text-align: center; margin: 0 0 14px 0;">
	<strong>{{ $contractorName }}</strong> has signed Estimate #{{ $estimateNumber }} and it's ready for your signature.
</p>

<p style="text-align: center; margin: 0 0 20px 0;">
	Log in or register to review the contract and sign electronically.
</p>

<x-mail::button :url="$signingUrl">
Sign Contract
</x-mail::button>
</x-mail::message>
