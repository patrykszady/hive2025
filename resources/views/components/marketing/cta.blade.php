@props(['heading' => 'Run your whole business from one Hive.', 'subheading' => 'Join the contractors automating their finances, scheduling, and client updates—so they can get back to building.'])

<div class="bg-indigo-700">
    <div class="px-6 py-24 sm:px-6 sm:py-32 lg:px-8">
        <div class="max-w-2xl mx-auto text-center">
            <h2 class="text-3xl font-bold tracking-tight text-white sm:text-4xl text-balance">
                {{ $heading }}
            </h2>
            <p class="max-w-xl mx-auto mt-6 text-lg font-normal leading-8 text-indigo-200">
                {{ $subheading }}
            </p>
            <div class="flex items-center justify-center mt-10 gap-x-6">
                <a href="{{ route('registration') }}" wire:navigate.hover
                    class="rounded-md bg-white px-3.5 py-2.5 text-sm font-semibold text-indigo-600 shadow-xs hover:bg-indigo-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                    Create your Hive
                </a>
                <a href="{{ route('login') }}" class="text-sm font-semibold leading-6 text-white" wire:navigate.hover>
                    Log in
                    <span aria-hidden="true">→</span>
                </a>
            </div>
        </div>
    </div>
</div>
