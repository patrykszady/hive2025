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

<p style="text-align: center; margin: 20px 0 0 0; font-size: 13px; color: #6b7280;">
	New to Hive? Registering is a simple 3-step process — confirm your phone number, confirm your email, and set a password. The easiest registration ever.
</p>
</x-mail::message>
