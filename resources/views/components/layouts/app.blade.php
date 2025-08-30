<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    {{-- HEAD --}}
    @include('components.layouts.head')

    {{-- BODY --}}
    {{-- $fullscreenClasses prop in render of Planner/Board --}}
    <body class="{{isset($fullscreenClasses) ? 'h-screen ' : 'min-h-screen '}} bg-gray-100 dark:bg-gray-800">
    <flux:sidebar sticky stashable class="bg-gray-50 dark:bg-zinc-900 border-r rtl:border-r-0 rtl:border-l border-gray-200 dark:border-gray-700 print:hidden">
            @php
                $isVendorRoute = Route::is(['vendor_selection', 'vendor_registration']);
                $href = $isVendorRoute ? null : route('dashboard'); // Set href for non-vendor routes
                $logo = asset('favicon.png'); // Logo remains the same
                $name = $isVendorRoute ? env('APP_NAME') : auth()->user()->vendor->name; // Automatically decoded via model accessor
            @endphp

            <flux:sidebar.toggle class="lg:hidden print:hidden" icon="x-mark" />

            <flux:brand href="{{ $href }}" logo="{{ $logo }}" name="{!! $name !!}" class="px-2 dark:hidden" />
            {{-- DARK MODE LOGO --}}
            <flux:brand href="{{ $href }}" logo="{{ $logo }}" name="{!! $name !!}" class="px-2 hidden dark:flex" />

            {{-- <flux:input as="button" variant="filled" placeholder="Search..." icon="magnifying-glass" /> --}}

            @if(!Route::is(['vendor_selection', 'vendor_registration']))
                <flux:navlist variant="outline">
                    {{-- SYSTEM ERRORS --}}

                    {{-- BANK ERRORS --}}
                    @can('viewAny', App\Models\Bank::class)
                        @if(!auth()->user()->vendor->banks()->whereNotNull('plaid_access_token')->get()->where('plaid_options.error', '!=', FALSE)->isEmpty())
                            <flux:navlist.item wire:navigate.hover icon="building-library" href="/banks">
                                Banks
                                <flux:badge color="red" size="sm" inset="top bottom">
                                    Error
                                </flux:badge>
                            </flux:navlist.item>
                            <flux:separator class="my-2" />
                        @endif
                    @endcan

                    {{-- NAVIGATION --}}
                    <flux:navlist.item wire:navigate.hover icon="home" href="/dashboard">Home</flux:navlist.item>

                    @can('viewAny', App\Models\Lead::class)
                        <flux:navlist.item wire:navigate.hover icon="magnifying-glass-plus" href="/leads">Leads</flux:navlist.item>
                    @endcan

                    <flux:navlist.item wire:navigate icon="folder" href="/projects">Projects</flux:navlist.item>
                    <flux:navlist.item icon="calendar" href="{{ route('planner.gantt') }}">Planner</flux:navlist.item>

                    @canany(['viewAny', 'create'], App\Models\Expense::class)
                        <flux:navlist.group expandable heading="Finances">
                            <flux:navlist.item wire:navigate.hover href="/expenses" icon="credit-card">Expenses</flux:navlist.item>
                            @can('viewAny', App\Models\Bank::class)
                                <flux:navlist.item wire:navigate.hover href="/payments" icon="banknotes">Payments</flux:navlist.item>
                            @endcan

                            @can('viewAny', App\Models\Check::class)
                                <flux:navlist.item wire:navigate.hover href="/checks" icon="pencil-square">Checks</flux:navlist.item>
                            @endcan
                        </flux:navlist.group>
                    @endcanany

                    <flux:navlist.item wire:navigate.hover icon="user-group" href="/vendors">Vendors</flux:navlist.item>
                    @can('viewAny', App\Models\Client::class)
                        <flux:navlist.item wire:navigate.hover icon="users" href="/clients">Clients</flux:navlist.item>
                    @endcan
                    
                    @canany(['create', 'viewAny', 'viewAnyPayment', 'viewPayment'], [
                        App\Models\Hour::class, 
                        App\Models\Timesheet::class, 
                        [App\Models\Timesheet::class, auth()->user()]
                    ])
                        <flux:navlist.group expandable heading="Timesheets">
                            @can('create', App\Models\Hour::class)
                                <flux:navlist.item wire:navigate.hover href="/hours/create" icon="clock">Hours</flux:navlist.item>
                            @endcan
                            @can('viewAny', App\Models\Timesheet::class)
                                <flux:navlist.item wire:navigate.hover href="/timesheets" icon="document-currency-dollar">Timesheets</flux:navlist.item>
                            @endcan
    
                            @can('viewAnyPayment', App\Models\Timesheet::class)
                                <flux:navlist.item wire:navigate.hover href="/timesheets/payments" icon="currency-dollar">Payments</flux:navlist.item>
                            @else
                                @can('viewPayment', [App\Models\Timesheet::class, auth()->user()])
                                    <flux:navlist.item wire:navigate.hover href="/timesheets/payment/{{auth()->id()}}" icon="currency-dollar">Balance</flux:navlist.item>
                                @endcan
                            @endcan
                        </flux:navlist.group>
                    @endcanany

                    @can('viewAny', App\Models\Bank::class)
                        <flux:navlist.group expandable heading="Accounting">
                            <flux:navlist.item href="/banks" icon="building-library">Banks</flux:navlist.item>
                            <flux:navlist.item wire:navigate.hover href="/distributions" icon="receipt-percent">Distributions</flux:navlist.item>
                            <flux:navlist.item wire:navigate.hover href="/sheets" icon="document-currency-dollar">Sheets</flux:navlist.item>
                            <flux:navlist.item wire:navigate.hover href="/company_emails" icon="inbox-stack">Company Emails</flux:navlist.item>

                            @if(auth()->user()->vendor_role === 'Admin')
                                <flux:navlist.item wire:navigate.hover href="/vendor_docs" icon="eye-slash">Vendor Docs</flux:navlist.item>
                            @endif
                        </flux:navlist.group>
                    @endcan

                    @if(auth()->user()->id === 1)
                        <flux:navlist.group expandable heading="Global Actions">
                            <flux:navlist.item wire:navigate.hover href="/transactions/match_vendor" icon="eye-slash">Match Vendor</flux:navlist.item>
                            <flux:navlist.item wire:navigate.hover href="/transactions/bulk_match" icon="eye-slash">Match Transactions</flux:navlist.item>
                        </flux:navlist.group>
                    @endif
                </flux:navlist>
            @endif

            <flux:spacer />

            <flux:navlist variant="outline">
                <flux:navlist.item wire:navigate.hover icon="cog-6-tooth" href="#">Settings</flux:navlist.item>
                <flux:navlist.item wire:navigate.hover icon="information-circle" href="#">Help</flux:navlist.item>
            </flux:navlist>

            <flux:dropdown position="top" align="start">
                <flux:profile avatar:color="indigo" name="{{auth()->user()->full_name}}" />

                <flux:menu>
                    <flux:menu.item href="{{route('vendor_selection')}}">Switch Account</flux:menu.item>
                    @can('admin_login_as_user', App\Models\User::class)
                        <flux:menu.item href="{{route('admin_login_as_user')}}">Incognito</flux:menu.item>
                    @endcan

                    <flux:menu.separator />

                    <flux:menu.item href="{{route('logout')}}" onclick="event.preventDefault(); document.getElementById('logout-form').submit();" icon="arrow-right-start-on-rectangle">Logout</flux:menu.item>
                    <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display: none;">
                        {{ csrf_field() }}
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:sidebar>

        <flux:main
            x-data
            x-cloak
            {{-- @navigate.window="Livewire.navigate($event.detail)" --}}
            {{-- $fullscreenClasses prop in render of Planner/Board --}}
            :class="$fullscreenClasses ?? null"
            >
            {{ $slot }}
        </flux:main>

    <div class="fixed top-4 left-6 lg:hidden print:hidden">
            <flux:sidebar.toggle
                icon="bars-2"
                class="backdrop-blur-lg shadow-lg shadow-gray-500/50 bg-black/5 ring-1 ring-black/15 hover:bg-black/10 hover:shadow-xl transition-all duration-200"
            />
        </div>

        @persist('toast')
            <flux:toast />
        @endpersist

        @fluxScripts
    </body>
</html>
