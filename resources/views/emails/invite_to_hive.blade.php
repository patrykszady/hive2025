<x-mail::message :show-header-brand="true">
@php
$baseUrl = rtrim((string) config('app.url'), '/');
@endphp

<div style="text-align: center;">
    <h1 class="title" style="text-align: center;">You're Invited to Hive</h1>

    @if($recipientName)
        <p class="text" style="margin-top: 6px; text-align: center; color: #71717a;">Hi {{ $recipientName }},</p>
    @endif

    @if($isClient)
        <p class="text" style="margin-top: 10px; text-align: center; color: #3f3f46; font-size: 15px; line-height: 1.6;">
            <strong>{{ $inviterName }}</strong> uses <a href="{{ $baseUrl }}" style="color: #4f46e5; text-decoration: none; font-weight: 600;">Hive Contractors</a> to manage your project.
            Register today to access your schedule, project, updates, and stay connected with {{ $inviterName }}.
        </p>
    @else
        <p class="text" style="margin-top: 10px; text-align: center; color: #3f3f46; font-size: 15px; line-height: 1.6;">
            <strong>{{ $inviterName }}</strong> uses <a href="{{ $baseUrl }}" style="color: #4f46e5; text-decoration: none; font-weight: 600;">Hive Contractors</a> to manage projects in one place.
            <strong>Finances, Estimates, Timesheets, Schedules</strong>, and so much more.
        </p>
        <p class="text" style="margin-top: 6px; text-align: center; color: #3f3f46; font-size: 15px; line-height: 1.6;">
            Join <a href="{{ $baseUrl }}" style="color: #4f46e5; text-decoration: none; font-weight: 600;">Hive Contractors</a> to connect with {{ $inviterName }} today to manage your construction projects, better, together.
        </p>
    @endif
</div>

<div style="height: 24px; line-height: 24px;">&nbsp;</div>

<div style="text-align: center;">
    <a href="{{ $registerUrl }}" style="display: inline-block; padding: 12px 32px; background-color: #4f46e5; color: #ffffff; font-size: 15px; font-weight: 600; text-decoration: none; border-radius: 6px;">Register</a>
</div>

<div style="height: 16px; line-height: 16px;">&nbsp;</div>

<div style="text-align: center;">
    <p style="font-size: 12px; color: #a1a1aa; margin-top: 16px;">
        If you weren't expecting this invitation, you can safely ignore this email.
    </p>
</div>
</x-mail::message>
