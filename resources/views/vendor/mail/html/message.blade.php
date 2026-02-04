@props([
	'hideFooter' => false,
	'showHeaderBrand' => false,
])

<x-mail::layout>
<x-slot:header>
<x-mail::header :url="config('app.url')">
<img src="{{ rtrim((string) config('app.url'), '/') }}/favicon.png" class="logo" alt="Hive Contractors" height="72px">
@if (! empty($showHeaderBrand))
<span style="display: block; margin-top: 10px; font-weight: 700; letter-spacing: 0.02em; text-decoration: none; color: inherit;">Hive Contractors</span>
@endif
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
@if (empty($hideFooter))
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }}
</x-mail::footer>
</x-slot:footer>
@endif
</x-mail::layout>
