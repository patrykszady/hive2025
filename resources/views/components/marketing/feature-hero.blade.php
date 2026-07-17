@props(['eyebrow', 'title', 'body', 'icon' => 'sparkles'])

<div class="relative overflow-hidden bg-white dark:bg-zinc-950 isolate">
    <div class="absolute inset-x-0 -top-40 -z-10 transform-gpu overflow-hidden blur-3xl sm:-top-80" aria-hidden="true">
        <div class="relative left-[calc(50%-11rem)] aspect-[1155/678] w-[36.125rem] -translate-x-1/2 rotate-[30deg] bg-gradient-to-tr from-indigo-300 to-indigo-600 opacity-20 sm:left-[calc(50%-30rem)] sm:w-[72.1875rem]"></div>
    </div>
    <div class="px-6 pt-20 pb-16 mx-auto max-w-4xl text-center sm:pt-28 lg:px-8">
        <div class="flex items-center justify-center w-14 h-14 mx-auto bg-indigo-600 rounded-2xl">
            <flux:icon name="{{ $icon }}" class="w-8 h-8 text-white" />
        </div>
        <p class="mt-6 text-base font-semibold leading-7 text-indigo-600 dark:text-indigo-400">{{ $eyebrow }}</p>
        <h1 class="mt-2 text-4xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-5xl text-balance">{{ $title }}</h1>
        <p class="mt-6 text-lg leading-8 text-gray-600 dark:text-gray-300">{{ $body }}</p>
        <div class="flex flex-wrap items-center justify-center mt-10 gap-x-6 gap-y-4">
            <a href="{{ route('registration') }}" class="rounded-md bg-indigo-600 px-3.5 py-2.5 text-base font-semibold leading-7 text-white shadow-xs hover:bg-indigo-500" wire:navigate.hover>{{ __('Create your Hive') }}</a>
            <a href="{{ route('welcome') }}#features" class="text-base font-semibold leading-7 text-gray-900 dark:text-white" wire:navigate.hover>{{ __('See all features') }} <span aria-hidden="true">→</span></a>
        </div>
    </div>
</div>
