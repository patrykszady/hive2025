<x-mail::subcopy>
{{$sending_vendor}} uses <a href="{{ config('app.url') }}">Hive Contractors</a> to manage projects in one place. <b>Finances, Estimates, Timesheets, Schedules</b>, and so much more.
<br>
Join <a href="{{ config('app.url') }}">Hive Contractors</a> to connect with {{$sending_vendor}} today to manage your construction projects, better, together.
</x-mail::subcopy>
<x-mail::button :url="config('app.url')">
Create Your Hive
</x-mail::button>
