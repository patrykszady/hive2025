<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    @include('components.layouts.head')

    <body>
        <div class="h-screen font-sans antialiased text-gray-900">
            {{ $slot }}
        </div>
    </body>
</html>
