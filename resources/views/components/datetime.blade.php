@props([
    'date' => null,
    'format' => 'default', // default, date, time, relative, short
])

@if($date)
    <time 
        x-datetime="{{ $date instanceof \Carbon\Carbon ? $date->toIso8601String() : $date }}"
        x-datetime-format="{{ $format }}"
        {{ $attributes }}
    >
        {{ $date instanceof \Carbon\Carbon ? $date->format('M d, Y g:i A') : $date }}
    </time>
@endif
