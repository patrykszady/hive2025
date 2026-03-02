<x-mail::message :show-header-brand="true">
<h1 style="text-align: center; margin: 0 0 14px 0;">Contract Ready for Signature</h1>

@if($recipientName)
<p style="text-align: center; margin: 0 0 10px 0;">Hi {{ $recipientName }},</p>
@endif

<p style="text-align: center; margin: 0 0 4px 0;">
	<strong>{{ $contractorName }}</strong> has signed Estimate #{{ $estimateNumber }}
</p>
@if($projectAddress || $projectName)
<p style="text-align: center; margin: 0 0 4px 0;">
	{{ $projectAddress }}@if($projectAddress && $projectName) | @endif{{ $projectName }}
</p>
@endif
<p style="text-align: center; margin: 0 0 14px 0;">
	and it's ready for your signature.
</p>

<div style="height: 8px; line-height: 8px;">&nbsp;</div>

<div style="text-align: center; border: 1px solid #d4d4d8; border-radius: 8px; padding: 20px 24px; margin: 0 auto; max-width: 420px;">
	<p style="margin: 0 0 12px 0; font-size: 15px; line-height: 1.5;">
		Log in or register to review and sign. You can also view your estimate, schedule, project updates, and stay connected with {{ $contractorName }}.
	</p>
	<p style="margin: 0 0 12px 0; font-size: 15px; line-height: 1.5;">
		Registering is easy — just confirm your number, email, and set a password.
	</p>
	<div style="height: 8px; line-height: 8px;">&nbsp;</div>
	<a href="{{ $signingUrl }}" style="display: inline-block; padding: 10px 28px; background-color: #4f46e5; color: #ffffff; font-size: 14px; font-weight: 600; text-decoration: none; border-radius: 6px;">Sign Contract</a>
</div>
</x-mail::message>
