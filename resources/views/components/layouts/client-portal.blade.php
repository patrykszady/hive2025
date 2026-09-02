<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    @include('components.layouts.head')

    <body class="min-h-screen bg-zinc-100 dark:bg-zinc-900" data-authenticated="true">
        <livewire:browser-timezone />

        {{-- Simple header for client portal --}}
        <header class="bg-white dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center h-16">
                    <div class="flex items-center gap-3">
                        <x-hive-logo class="size-8" />
                        <flux:heading size="lg">My Projects</flux:heading>
                    </div>
                    
                    <div class="flex items-center gap-4">
                        <flux:text class="text-sm text-zinc-500">
                            {{ auth()->user()->full_name }}
                        </flux:text>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <flux:button type="submit" variant="ghost" size="sm" icon="arrow-right-start-on-rectangle">
                                Logout
                            </flux:button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        {{-- Main content --}}
        <main class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            {{ $slot }}
        </main>

        {{-- Footer --}}
        <footer class="border-t border-zinc-200 dark:border-zinc-700 mt-auto">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                <div class="text-center text-sm text-zinc-400">
                    <a href="{{ url('/') }}" class="inline-flex items-center gap-1.5 hover:text-zinc-600 dark:hover:text-zinc-300 transition-colors">
                        <x-hive-logo class="size-4" />
                        <span>Powered by Hive Contractors</span>
                    </a>
                </div>
            </div>
        </footer>

        {{-- Toast without @persist to prevent stale toasts across wire:navigate --}}
        <flux:toast />

        @fluxScripts
    </body>
</html>
