@php
    $baseUrl = rtrim((string) config('app.url'), '/');
@endphp
YOU'RE INVITED TO HIVE
@if($recipientName)

Hi {!! $recipientName !!},
@endif
@if($isClient)

{!! $inviterName !!} uses Hive Contractors ({!! $baseUrl !!}) to manage your
project. Register today to access your schedule, project updates, and stay
connected with {!! $inviterName !!}.

WHAT YOU'LL GET IN HIVE

- View your project details and progress
- See upcoming tasks and scheduled work
- Stay connected with {!! $inviterName !!}
@else

{!! $inviterName !!} uses Hive Contractors ({!! $baseUrl !!}) to manage projects
in one place.

WHAT YOU'LL GET IN HIVE

- Manage finances, estimates, and timesheets
- Coordinate schedules and project updates
- Collaborate with {!! $inviterName !!} in one place
@endif

Register here:
{!! $registerUrl !!}

Want to learn more first? Visit our homeowner guide:
https://hive.contractors/welcome/homeowners

--
{!! config('app.name') !!}
