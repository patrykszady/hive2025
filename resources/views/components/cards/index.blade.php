@props([
    'accordian' => NULL,
])

<div x-data>
    <div
        {{isset($accordian) ? 'x-disclosure' : ''}}
        {{$accordian == "OPENED" ? 'default-open' : ''}}
        {{ $attributes->merge(['class' => 'mx-auto']) }}
        >
        {{-- overflow-hidden --}}
        <div class="rounded-lg bg-white shadow-md">
            {{$slot}}
        </div>
    </div>
</div>
