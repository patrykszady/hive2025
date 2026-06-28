@props([
    'current' => null,
    'heading' => 'Explore your homeowner portal',
    'subheading' => null,
])

@php
    $portalItems = [
        ['key' => 'status', 'icon' => 'eye', 'title' => 'Live project status', 'body' => 'See what is done, in progress, and coming up at any time.'],
        ['key' => 'schedule', 'icon' => 'calendar-date-range', 'title' => 'Schedule & reminders', 'body' => 'Know when the crew is coming and get a heads-up before each visit.'],
        ['key' => 'messaging', 'icon' => 'chat-bubble-left-right', 'title' => 'Direct messaging', 'body' => 'One clean thread with your contractor—photos included.'],
        ['key' => 'photos', 'icon' => 'photo', 'title' => 'Photos & progress', 'body' => 'Watch your project come together with job-site photos.'],
        ['key' => 'documents', 'icon' => 'pencil-square', 'title' => 'Estimates & e-sign', 'body' => 'Review and approve estimates and change orders on your phone.'],
        ['key' => 'payments', 'icon' => 'banknotes', 'title' => 'Invoices & payments', 'body' => 'See invoices and a running total of what has been paid.'],
        ['key' => 'selections', 'icon' => 'swatch', 'title' => 'Selections & allowances', 'body' => 'Track your choices and where each allowance stands.'],
        ['key' => 'notifications', 'icon' => 'bell-alert', 'title' => 'Notifications', 'body' => 'Get a text or alert when something needs you or a date moves.'],
        ['key' => 'access', 'icon' => 'finger-print', 'title' => 'Secure, easy access', 'body' => 'Sign in with a private link—no app, no password to remember.'],
    ];

    $items = $current
        ? array_values(array_filter($portalItems, fn ($item) => $item['key'] !== $current))
        : $portalItems;
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
            @foreach ($items as $item)
                <a href="{{ route('welcome.homeowners.' . $item['key']) }}" wire:navigate.hover
                    class="group flex flex-col p-6 bg-white dark:bg-zinc-950 rounded-2xl ring-1 ring-gray-200 dark:ring-zinc-800 shadow-sm transition hover:ring-indigo-400 hover:shadow-md">
                    <div class="flex items-center justify-center w-10 h-10 bg-indigo-600 rounded-lg">
                        <flux:icon name="{{ $item['icon'] }}" class="w-6 h-6 text-white" />
                    </div>
                    <h3 class="mt-4 text-base font-semibold text-gray-900 dark:text-white">{{ $item['title'] }}</h3>
                    <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-400">{{ $item['body'] }}</p>
                    <span class="inline-flex items-center gap-1 mt-4 text-sm font-semibold text-indigo-600 dark:text-indigo-400 group-hover:text-indigo-500">
                        Learn more <span aria-hidden="true" class="transition group-hover:translate-x-0.5">→</span>
                    </span>
                </a>
            @endforeach
        </div>
    </div>
</div>
