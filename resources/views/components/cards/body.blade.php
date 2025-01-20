@aware([
    'accordian' => NULL,
])

<div {{isset($accordian) ? 'x-disclosure:panel x-collapse' : ''}} {{ $attributes->merge(['class' => '']) }}>
    {{$slot}}
</div>
