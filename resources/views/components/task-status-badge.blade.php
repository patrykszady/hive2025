@props([
    'status' => null,
])

@php
    $statusMap = [
        'confirmed' => ['color' => 'green', 'icon' => 'check'],
        'rejected' => ['color' => 'red', 'icon' => 'x-mark'],
        'proposed' => ['color' => 'indigo', 'icon' => 'calendar'],
    ];

    $config = $statusMap[$status] ?? ['color' => 'yellow', 'icon' => 'clock'];
@endphp

<flux:badge :color="$config['color']" size="sm" {{ $attributes->class('px-0! py-0! size-6 items-center justify-center') }}>
    <flux:icon :icon="$config['icon']" class="size-3.5" />
</flux:badge>
