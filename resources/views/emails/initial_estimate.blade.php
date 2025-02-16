<x-mail::message>
Hi !
<br>
Here are your payment details from <b></b>:

<x-mail::panel>
{{-- if no check nubmer show Transfer/Zelle or Cash --}}
Check # <b></b><br>
Check Date <b></b><br>
Check Total <b></b><br>
</x-mail::panel>

<h3>Project Payments:</h3>
<x-mail::panel>

</x-mail::panel>

<x-mail::subcopy>
Join <a href="https://dashboard.hive.contractors/">Hive Contractors</a> today to flawlessly manage your construction projects, see more details for this payment, add bids, and so much more!<br>
Call Patryk 224-999-3880 to setup for free!
</x-mail::subcopy>
<x-mail::button :url="'https://dashboard.hive.contractors'">
Join Hive
</x-mail::button>
Thanks,<br>
Patryk<br>
<a href="https://dashboard.hive.contractors">Hive Contractors</a>
</x-mail::message>
