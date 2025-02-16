<x-mail::layout>
<x-slot:header>
<x-mail::header :url="'https://dashboard.hive.contractors'">
<img src="https://dashboard.hive.contractors/favicon.png" class="logo" alt="Hive Contractors" height="72px">
</x-mail::header>
</x-slot:header>
{{ $slot }}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{{ $subcopy }}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
