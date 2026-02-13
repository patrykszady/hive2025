<x-mail::message :show-header-brand="true">
<h1 style="text-align: center; margin: 0 0 14px 0;">You're Invited to Hive</h1>

@if($recipientName)
<p style="text-align: center; margin: 0 0 10px 0;">Hi {{ $recipientName }},</p>
@endif

<p style="text-align: center; margin: 0 0 14px 0;">
	<strong>{{ $contractorName }}</strong> uses <a href="{{ rtrim((string) config('app.url'), '/') }}">Hive Contractors</a> to manage your project.
	Register today to access your schedule, project updates, and stay connected with {{ $contractorName }}.
</p>

<div style="text-align: center; margin: 0 0 20px 0;">
	<div style="display: inline-block; text-align: left;">
		<div>• View your <strong>project details</strong> and progress</div>
		<div>• See <strong>upcoming tasks</strong> and scheduled work</div>
		<div>• Stay <strong>connected</strong> with {{ $contractorName }}</div>
	</div>
</div>

<x-mail::button :url="$registerUrl">
Register
</x-mail::button>
</x-mail::message>
