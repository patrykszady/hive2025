{{-- @props([
    'accordian' => NULL,
]) --}}

<ul role="list" {{ $attributes->merge(['class' => 'divide-y divide-gray-200']) }}
    {{-- {{isset($accordian) ? 'x-disclosure' : ''}}
    {{$accordian == "OPENED" ? 'default-open' : ''}} --}}
    {{-- {{ $attributes->merge(['class' => 'mx-auto']) }} --}}
    >
    {{$slot}}
</ul>
