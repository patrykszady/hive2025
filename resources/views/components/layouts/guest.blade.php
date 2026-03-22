<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    @include('components.layouts.head')

    <body class="{{ $bodyClass ?? 'lg:bg-gradient-to-r lg:from-white lg:from-50% lg:to-indigo-900 lg:to-50%' }}">
        <livewire:browser-timezone />
        <div class="min-h-screen font-sans antialiased text-gray-900">
            <div data-page-fade class="transition-opacity duration-100">
                {{ $slot }}
            </div>
        </div>

        
        <flux:toast />

        @fluxScripts
    </body>
</html>
