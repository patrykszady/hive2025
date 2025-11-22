<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    {{-- HEAD --}}
    @include('components.layouts.head')

    {{-- BODY --}}
    {{-- $fullscreenClasses prop in render of Planner/Board --}}
    <body class="{{isset($fullscreenClasses) ? 'h-screen ' : 'min-h-screen '}} bg-zinc-100 dark:bg-zinc-800">
        <flux:sidebar sticky collapsible class="bg-zinc-50 dark:bg-zinc-900 border-r border-zinc-200 dark:border-zinc-700">
            <flux:sidebar.header>
                @php
                    $isVendorRoute = Route::is(['vendor_selection', 'vendor_registration']);
                    $href = $isVendorRoute ? null : route('dashboard'); // Set href for non-vendor routes
                    $logo = asset('favicon.png'); // Logo remains the same
                    $name = \Illuminate\Support\Str::limit($isVendorRoute ? env('APP_NAME') : auth()->user()->vendor->name, 15); // limit to 15 chars
                @endphp
                <flux:sidebar.brand
                    href="{{ $href }}"
                    logo="{{ $logo }}"
                    logo:dark="{{ $logo }}"
                    name="{!! $name !!}"
                />
                <flux:sidebar.collapse class="in-data-flux-sidebar-on-desktop:not-in-data-flux-sidebar-collapsed-desktop:-mr-2" />
            </flux:sidebar.header>

            @php
                $accountingGroups = ['banks*', 'distributions*', 'sheets*'];
                $accountingExpanded = request()->is($accountingGroups) || request()->routeIs($accountingGroups);
                $globalActionsExpanded = request()->is('transactions/match_vendor') || request()->is('transactions/bulk_match');
                $settingsGroups = ['email_templates*', 'company_emails*', 'vendor_docs*'];
                $settingsExpanded = request()->is($settingsGroups) || request()->routeIs($settingsGroups);
            @endphp

            @if(!Route::is(['vendor_selection', 'vendor_registration']))
                <flux:sidebar.nav>
                    {{-- SYSTEM ERRORS --}}

                    {{-- BANK ERRORS --}}
                    @can('viewAny', App\Models\Bank::class)
                        @if(!auth()->user()->vendor->banks()->whereNotNull('plaid_access_token')->get()->where('plaid_options.error', '!=', FALSE)->isEmpty())
                            <flux:sidebar.item wire:navigate.hover icon="building-library" href="/banks" badge="Error">Banks</flux:sidebar.item>
                            <flux:separator class="my-2" />
                        @endif
                    @endcan

                    {{-- NAVIGATION --}}
                    <flux:sidebar.item wire:navigate.hover icon="home" href="/dashboard">Home</flux:sidebar.item>

                    @can('viewAny', App\Models\Lead::class)
                        <flux:sidebar.item wire:navigate.hover icon="magnifying-glass-plus" href="/leads">Leads</flux:sidebar.item>
                    @endcan

                    <flux:sidebar.item wire:navigate.hover icon="folder" href="/projects">Projects</flux:sidebar.item>
                    <flux:sidebar.item wire:navigate.hover icon="calendar" href="{{ route('planner.gantt') }}">Planner</flux:sidebar.item>

                    @canany(['viewAny', 'create'], App\Models\Expense::class)
                        <flux:sidebar.group expandable heading="Finances" icon="credit-card" class="grid" :expanded="true">
                            <flux:sidebar.item wire:navigate.hover href="/expenses" icon="credit-card">Expenses</flux:sidebar.item>
                            @can('viewAny', App\Models\Bank::class)
                                <flux:sidebar.item wire:navigate.hover href="/payments" icon="banknotes">Payments</flux:sidebar.item>
                            @endcan

                            @can('viewAny', App\Models\Check::class)
                                <flux:sidebar.item wire:navigate.hover href="/checks" icon="pencil-square">Checks</flux:sidebar.item>
                            @endcan
                        </flux:sidebar.group>
                    @endcanany

                    <flux:sidebar.item wire:navigate.hover icon="user-group" href="/vendors">Vendors</flux:sidebar.item>
                    @can('viewAny', App\Models\Client::class)
                        <flux:sidebar.item wire:navigate.hover icon="users" href="/clients">Clients</flux:sidebar.item>
                    @endcan
                    
                    @canany(['create', 'viewAny', 'viewAnyPayment', 'viewPayment'], [
                        App\Models\Hour::class, 
                        App\Models\Timesheet::class, 
                        [App\Models\Timesheet::class, auth()->user()]
                    ])
                        <flux:sidebar.group expandable heading="Timesheets" class="grid" icon="clock" :expanded="true">
                            @can('create', App\Models\Hour::class)
                                <flux:sidebar.item wire:navigate.hover href="/hours/create" icon="clock">Hours</flux:sidebar.item>
                            @endcan
                            @can('viewAny', App\Models\Timesheet::class)
                                <flux:sidebar.item wire:navigate.hover href="/timesheets" icon="document-currency-dollar">Timesheets</flux:sidebar.item>
                            @endcan
    
                            @can('viewAnyPayment', App\Models\Timesheet::class)
                                <flux:sidebar.item wire:navigate.hover href="/timesheets/payments" icon="currency-dollar">Payments</flux:sidebar.item>
                            @else
                                @can('viewPayment', [App\Models\Timesheet::class, auth()->user()])
                                    <flux:sidebar.item wire:navigate.hover href="/timesheets/payment/{{auth()->id()}}" icon="currency-dollar">Balance</flux:sidebar.item>
                                @endcan
                            @endcan
                        </flux:sidebar.group>
                    @endcanany

                    @can('viewAny', App\Models\Bank::class)
                        <flux:sidebar.group expandable heading="Accounting" class="grid" icon="document-currency-dollar" :expanded="$accountingExpanded">
                            <flux:sidebar.item wire:navigate.hover href="/banks" icon="building-library">Banks</flux:sidebar.item>
                            <flux:sidebar.item wire:navigate.hover href="/distributions" icon="receipt-percent">Distributions</flux:sidebar.item>
                            <flux:sidebar.item wire:navigate.hover href="/sheets" icon="document-currency-dollar">Sheets</flux:sidebar.item>
                        </flux:sidebar.group>
                    @endcan
                </flux:sidebar.nav>
            @endif

            <flux:sidebar.spacer />

            <flux:sidebar.nav>
                @can('admin_login_as_user', App\Models\User::class)
                    <flux:sidebar.group expandable heading="Global Actions" class="grid" icon="eye-slash" :expanded="$globalActionsExpanded">
                        <flux:sidebar.item wire:navigate.hover href="/transactions/match_vendor">Match Vendor</flux:sidebar.item>
                        <flux:sidebar.item wire:navigate.hover href="/transactions/bulk_match">Match Transactions</flux:sidebar.item>
                    </flux:sidebar.group>
                @endcan
                
                @if(
                    auth()->user()->can('viewAny', App\Models\EmailTemplate::class)
                    || auth()->user()->can('viewAny', App\Models\CompanyEmail::class)
                    || auth()->user()->can('viewAny', App\Models\VendorDoc::class)
                )
                    <flux:sidebar.group expandable heading="Settings" class="grid" icon="cog-6-tooth" :expanded="$settingsExpanded">
                        @can('viewAny', App\Models\EmailTemplate::class)
                            <flux:sidebar.item wire:navigate.hover href="/email_templates" icon="envelope-open">Email Templates</flux:sidebar.item>
                        @endcan

                        @can('viewAny', App\Models\CompanyEmail::class)
                            <flux:sidebar.item wire:navigate.hover href="/company_emails" icon="inbox-stack">Company Emails</flux:sidebar.item>
                        @endcan

                        @can('viewAny', App\Models\VendorDoc::class)
                            <flux:sidebar.item wire:navigate.hover href="/vendor_docs" icon="eye-slash">Vendor Docs</flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endif
            </flux:sidebar.nav>

            <flux:dropdown position="top" align="start">
                <flux:sidebar.profile avatar:color="indigo" name="{{auth()->user()->full_name}}" />
                <flux:menu>
                    <flux:menu.item href="{{route('vendor_selection')}}">Switch Account</flux:menu.item>
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

        {{-- <div class="fixed top-4 left-6 lg:hidden print:hidden">
            <flux:sidebar.toggle
                icon="bars-2"
                class="backdrop-blur-lg shadow-lg shadow-gray-500/50 bg-black/5 ring-1 ring-black/15 hover:bg-black/10 hover:shadow-xl transition-all duration-200"
            />
        </div> --}}
        <flux:header class="fixed top-4 lg:hidden print:hidden">
            <flux:sidebar.toggle class="lg:hidden backdrop-blur-lg shadow-lg shadow-gray-500/50 bg-black/5 ring-1 ring-black/15 hover:bg-black/10 hover:shadow-xl transition-all duration-200" icon="bars-2" inset="left" />
        </flux:header>

        <flux:main 
            :class="$fullscreenClasses ?? null"
            {{-- x-data
            x-cloak --}}
            >
            {{-- <flux:heading size="xl" level="1">Good afternoon, Olivia</flux:heading>
            <flux:text class="mt-2 mb-6 text-base">Here's what's new today</flux:text>
            <flux:separator variant="subtle" /> --}}
            
            {{ $slot }}
        </flux:main>

        @persist('toast')
            <flux:toast position="top end" />
        @endpersist

        @fluxScripts
    </body>
</html>