<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    @include('components.layouts.head')

    <body class="{{ $bodyClass ?? 'lg:bg-gradient-to-r lg:from-white lg:from-50% lg:to-indigo-900 lg:to-50%' }}">
        {{-- Guests only need the timezone sync once they log in — and on
             CachePublicPage-cached pages a rendered Livewire component would
             carry the FIRST visitor's snapshot + CSRF token to everyone else. --}}
        @auth
            <livewire:browser-timezone />
        @endauth
        <div class="min-h-screen font-sans antialiased text-gray-900">
            <div data-page-fade class="transition-opacity duration-100">
                {{ $slot }}
            </div>
        </div>

        
        <flux:toast />

        @stack('scripts')

        @fluxScripts
        {{-- Render Livewire's JS (Alpine included) in the template rather than
             relying on post-request auto-injection: CachePublicPage captures
             the body BEFORE that injection runs, so cache-hit visitors were
             served pages with no Alpine at all — dead nav dropdown, language
             switcher, and marketing interactions. An explicit render is part
             of the cached body; the auto-injector sees it and skips. --}}
        @livewireScripts
    </body>
</html>
