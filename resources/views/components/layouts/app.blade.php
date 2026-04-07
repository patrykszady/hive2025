<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" translate="no">
    {{-- HEAD --}}
    @include('components.layouts.head')

    {{-- BODY --}}
    {{-- $fullscreenClasses prop in render of Planner/Board --}}
    <body 
        class="{{isset($fullscreenClasses) ? 'h-screen overflow-hidden ' : 'min-h-screen '}} bg-zinc-100 dark:bg-zinc-800"
        @if(env('FAKE_BROWSER_DATE')) data-fake-today="{{ browser_today()->format('Y-m-d') }}" @endif
    >
        <livewire:browser-timezone />

        <flux:sidebar sticky collapsible class="bg-zinc-50 dark:bg-zinc-900 border-r border-zinc-200 dark:border-zinc-700 overflow-x-hidden flux-no-scrollbar">
            @persist('sidebar-header')
            <flux:sidebar.header>
                @php
                    $isVendorRoute = Route::is(['vendor_registration']);
                    $isVendorSelection = Route::is(['account_selection']);
                    $href = ($isVendorRoute || $isVendorSelection) ? null : route('dashboard');
                    $logo = asset('favicon.svg');
                    $name = $isVendorRoute || $isVendorSelection || !auth()->user()?->vendor
                        ? config('app.name')
                        : auth()->user()->vendor->shortName;
                @endphp
                <flux:sidebar.brand
                    href="{{ $href }}"
                    logo="{{ $logo }}"
                    logo:dark="{{ $logo }}"
                    :name="$name"
                />
                <flux:sidebar.collapse class="in-data-flux-sidebar-on-desktop:not-in-data-flux-sidebar-collapsed-desktop:-mr-2" />
            </flux:sidebar.header>
            @endpersist

            @if(!Route::is(['account_selection', 'vendor_registration']) && (auth()->user()->primary_vendor_id || auth()->user()->is_browsing_as_client))
                <livewire:app-sidebar />
            @else
                <flux:sidebar.spacer />
            @endif

            <flux:dropdown position="top" align="start" class="shrink-0">
                <flux:sidebar.profile avatar:color="indigo" name="{{auth()->user()->full_name}}" />
                <flux:menu>
                    <flux:menu.item wire:navigate.hover href="{{route('users.show', auth()->id())}}" icon="user">Profile</flux:menu.item>
                    <flux:menu.item href="{{route('account_selection')}}">Switch Account</flux:menu.item>
                    @can('admin_login_as_user', App\Models\User::class)
                        <flux:menu.item href="{{route('admin_login_as_user')}}">Incognito</flux:menu.item>
                    @endcan
                    <flux:menu.separator />
                    <form action="{{ route('logout') }}" method="POST">
                        @csrf
                        <flux:menu.item type="submit" icon="arrow-right-start-on-rectangle">Logout</flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:sidebar>

        {{-- Sidebar FOUC prevention: set collapsed attribute before browser paints main content.
             Runs synchronously after sidebar is parsed, before flux:main renders. --}}
        <script>
            try {
                if (JSON.parse(localStorage.getItem('flux-sidebar-collapsed-desktop'))) {
                    var sb = document.querySelector('[data-flux-sidebar]');
                    if (sb) sb.setAttribute('data-flux-sidebar-collapsed-desktop', '');
                }
                document.documentElement.removeAttribute('data-sidebar-collapsed');
            } catch(e) {}
        </script>

        <flux:header class="lg:hidden lg:pointer-events-none print:hidden">
            <flux:button
                class="lg:hidden bg-white/60 dark:bg-zinc-900/50 backdrop-blur-[2px] border border-zinc-200/60 dark:border-zinc-700/60 shadow-sm rounded-lg"
                variant="subtle"
                square
                inset="left"
                x-data
                x-on:click="$dispatch('flux-sidebar-toggle')"
                aria-label="Toggle sidebar"
                data-flux-sidebar-toggle
            >
                <svg class="text-zinc-500 dark:text-zinc-400" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M7.5 3.75V16.25M3.4375 16.25H16.5625C17.08 16.25 17.5 15.83 17.5 15.3125V4.6875C17.5 4.17 17.08 3.75 16.5625 3.75H3.4375C2.92 3.75 2.5 4.17 2.5 4.6875V15.3125C2.5 15.83 2.92 16.25 3.4375 16.25Z" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"></path>
                </svg>
            </flux:button>
        </flux:header>

        <flux:main
            class="min-h-0"
            :class="$fullscreenClasses ?? null"
        >
            {{-- <flux:heading size="xl" level="1">Good afternoon, Olivia</flux:heading>
            <flux:text class="mt-2 mb-6 text-base">Here's what's new today</flux:text>
            <flux:separator variant="subtle" /> --}}

            <div data-page-fade class="transition-opacity duration-[250ms] {{ isset($fullscreenClasses) ? 'h-full min-h-0 flex flex-col' : '' }}">
                {{ $slot }}
            </div>
        </flux:main>

        <flux:toast position="top end" />

        @fluxScripts

        {{-- Close stale modals/toasts and sidebar on wire:navigate --}}
        <script>
            function flushStaleToasts() {
                document.querySelectorAll('ui-toast[popover], ui-toast-group[popover]').forEach(el => {
                    el.querySelectorAll('[data-flux-toast-dialog]').forEach(t => t.remove());
                    try { el.hidePopover(); } catch (e) {}
                });
            }

            {{-- Strip toasts and close modals BEFORE the page gets cached --}}
            document.addEventListener('livewire:navigating', () => {
                document.querySelectorAll('dialog[open]').forEach(d => d.close());
                document.dispatchEvent(new CustomEvent('modal-close', { detail: {} }));
                flushStaleToasts();

                const sidebar = document.querySelector('ui-sidebar');
                if (sidebar && sidebar.hasAttribute('data-flux-sidebar-on-mobile') && !sidebar.hasAttribute('data-flux-sidebar-collapsed-mobile')) {
                    document.dispatchEvent(new CustomEvent('flux-sidebar-toggle'));
                }
            });

            {{-- Safety net: clean up after the cached page is restored --}}
            document.addEventListener('livewire:navigated', () => {
                document.querySelectorAll('dialog[open]').forEach(d => d.close());
                flushStaleToasts();
                {{-- Delayed pass in case Livewire replays effects after navigated --}}
                setTimeout(flushStaleToasts, 50);
            });

            {{-- Handle bfcache page restoration --}}
            window.addEventListener('pageshow', (event) => {
                if (event.persisted) {
                    document.querySelectorAll('dialog[open]').forEach(d => d.close());
                    flushStaleToasts();
                }
            });
        </script>
    </body>
</html>