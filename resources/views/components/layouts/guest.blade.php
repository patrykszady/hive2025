<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    @include('components.layouts.head')

    <body>
        <livewire:browser-timezone />
        <div class="min-h-screen font-sans antialiased text-gray-900">
            <div data-page-fade class="transition-opacity duration-200">
                {{ $slot }}
            </div>
        </div>

        
        @persist('toast')
            <flux:toast />
        @endpersist

        @fluxScripts
    </body>
</html>
