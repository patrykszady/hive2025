<x-mail::message :show-header-brand="true">
<h1 style="text-align: center; margin: 0 0 14px 0;">Contract Fully Signed</h1>

@if($recipientName)
<p style="text-align: center; margin: 0 0 10px 0;">Hi {{ $recipientName }},</p>
@endif

<p style="text-align: center; margin: 0 0 14px 0;">
	All parties have signed Estimate #{{ $estimateNumber }}@if($projectLabel) | {{ $projectLabel }}@endif. A copy of the signed contract is attached to this email.
</p>

@if($isClient)
<div style="height: 8px; line-height: 8px;">&nbsp;</div>

<div style="text-align: center; background-color: #f4f4f5; border-radius: 8px; padding: 20px 24px; margin: 0 auto; max-width: 420px;">
	<p style="margin: 0 0 8px 0; font-size: 15px; color: #3f3f46; line-height: 1.5;">
		<strong>{{ $contractorName }}</strong> uses <strong style="color: #4f46e5;">Hive Contractors</strong> to manage your project. Log in to view your estimate, schedule, project updates, and stay connected with {{ $contractorName }}.
	</p>
	<div style="height: 12px; line-height: 12px;">&nbsp;</div>
	<a href="{{ $loginUrl }}" style="display: inline-block; padding: 10px 28px; background-color: #4f46e5; color: #ffffff; font-size: 14px; font-weight: 600; text-decoration: none; border-radius: 6px;">Log In to Hive</a>
</div>
@endif
</x-mail::message>
