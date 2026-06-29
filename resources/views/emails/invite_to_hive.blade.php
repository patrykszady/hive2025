<x-mail::message :show-header-brand="true">
@php
$baseUrl = rtrim((string) config('app.url'), '/');
@endphp

<div style="text-align: center; margin: 2px 0 10px 0;">
    <span style="display: inline-block; padding: 5px 12px; border-radius: 999px; background: #eef2ff; color: #4338ca; font-size: 12px; font-weight: 700; letter-spacing: .02em;">
        INVITE TO HIVE
    </span>
</div>

<h1 style="text-align: center; margin: 0 0 10px 0; color: #111827; font-size: 28px; line-height: 1.2; font-weight: 800;">
    You're Invited to Hive
</h1>

@if($recipientName)
    <p style="text-align: center; margin: 0 0 12px 0; color: #4b5563; font-size: 16px;">
        Hi {{ $recipientName }},
    </p>
@endif

@if($isClient)
    <div style="text-align: center; margin: 0 0 18px 0;">
        <p style="margin: 0 auto; max-width: 560px; color: #374151; font-size: 15px; line-height: 1.65; text-align: center;">
            <strong style="color: #111827;">{{ $inviterName }}</strong> uses
            <a href="{{ $baseUrl }}" style="color: #4f46e5; text-decoration: none; font-weight: 700;">Hive Contractors</a>
            to manage your project. Register today to access your schedule, project updates, and stay connected with {{ $inviterName }}.
        </p>
    </div>

    <div style="margin: 0 auto 22px auto; max-width: 560px; border: 1px solid #e5e7eb; border-radius: 14px; background: #fafbff; padding: 14px 18px;">
        <div style="font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; font-weight: 700; margin-bottom: 8px;">
            What you'll get in Hive
        </div>
        <div style="color: #374151; font-size: 15px; line-height: 1.7; text-align: left;">
            <div>- View your <strong>project details</strong> and progress</div>
            <div>- See <strong>upcoming tasks</strong> and scheduled work</div>
            <div>- Stay <strong>connected</strong> with {{ $inviterName }}</div>
        </div>
    </div>
@else
    <div style="text-align: center; margin: 0 0 18px 0;">
        <p style="margin: 0 auto; max-width: 560px; color: #374151; font-size: 15px; line-height: 1.65; text-align: center;">
            <strong style="color: #111827;">{{ $inviterName }}</strong> uses
            <a href="{{ $baseUrl }}" style="color: #4f46e5; text-decoration: none; font-weight: 700;">Hive Contractors</a>
            to manage projects in one place.
        </p>
    </div>

    <div style="margin: 0 auto 22px auto; max-width: 560px; border: 1px solid #e5e7eb; border-radius: 14px; background: #fafbff; padding: 14px 18px;">
        <div style="font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; font-weight: 700; margin-bottom: 8px;">
            What you'll get in Hive
        </div>
        <div style="color: #374151; font-size: 15px; line-height: 1.7; text-align: left;">
            <div>- Manage <strong>finances, estimates, and timesheets</strong></div>
            <div>- Coordinate <strong>schedules and project updates</strong></div>
            <div>- Collaborate with {{ $inviterName }} in one place</div>
        </div>
    </div>
@endif

<div style="text-align: center;">
    <a href="{{ $registerUrl }}" style="display: inline-block; padding: 12px 32px; background-color: #4f46e5; color: #ffffff; font-size: 15px; font-weight: 600; text-decoration: none; border-radius: 6px;">Register</a>
</div>

<p style="margin: 14px 0 0 0; text-align: center; color: #6b7280; font-size: 13px; line-height: 1.5;">
    Want to learn more first?
    <a href="https://hive.contractors/welcome/homeowners" style="color: #4f46e5; font-weight: 700; text-decoration: none;">Visit our homeowner guide</a>.
</p>

</x-mail::message>
