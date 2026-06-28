@props([
    'area',
    'current' => null,
    'heading' => null,
    'subheading' => null,
])

@php
    $areaConfig = config("marketing.areas.$area", []);
    $cards = $areaConfig['cards'] ?? [];
    $heading = $heading ?? ($areaConfig['grid_heading'] ?? 'Explore the toolkit');

    $items = $current
        ? array_filter($cards, fn ($key) => $key !== $current, ARRAY_FILTER_USE_KEY)
        : $cards;
@endphp

<div {{ $attributes->merge(['class' => 'py-20 bg-gray-100 dark:bg-zinc-900 sm:py-28']) }}>
    <div class="px-6 mx-auto max-w-7xl lg:px-8">
        <div class="max-w-2xl mx-auto text-center">
            <h2 class="text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl">{{ $heading }}</h2>
            @if ($subheading)
                <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">{{ $subheading }}</p>
            @endif
        </div>
        <div class="grid max-w-2xl grid-cols-1 mx-auto mt-16 gap-6 lg:max-w-none lg:grid-cols-3">
            @foreach ($items as $slug => $card)
                <a href="{{ route('welcome.feature', ['area' => $area, 'card' => $slug]) }}" wire:navigate.hover
                    class="group flex flex-col p-6 bg-white dark:bg-zinc-950 rounded-2xl ring-1 ring-gray-200 dark:ring-zinc-800 shadow-sm transition hover:ring-indigo-400 hover:shadow-md">
                    <div class="flex items-center justify-center w-10 h-10 bg-indigo-600 rounded-lg">
                        <flux:icon name="{{ $card['icon'] }}" class="w-6 h-6 text-white" />
                    </div>
                    <h3 class="mt-4 text-base font-semibold text-gray-900 dark:text-white">{{ $card['title'] }}</h3>
                    <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-400">{!! $card['body'] !!}</p>
                    <span class="inline-flex items-center gap-1 mt-4 text-sm font-semibold text-indigo-600 dark:text-indigo-400 group-hover:text-indigo-500">
                        Learn more <span aria-hidden="true" class="transition group-hover:translate-x-0.5">→</span>
                    </span>
                </a>
            @endforeach
        </div>
    </div>
</div>
