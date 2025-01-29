<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    {{-- HEAD --}}
    @include('components.layouts.head')

    {{-- BODY --}}
    <body class="min-h-screen bg-gray-100 dark:bg-zinc-800">
        <flux:sidebar sticky stashable class="bg-zinc-50 dark:bg-zinc-900 border-r border-zinc-200 dark:border-zinc-700">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />
            <flux:brand href="{{route('dashboard')}}" logo="{{ asset('favicon.png') }}" name="{{ env('APP_NAME') }}" />

            @if(!Route::is(['vendor_selection', 'vendor_registration']))
                {{-- <flux:input as="button" variant="filled" placeholder="Search..." icon="magnifying-glass" /> --}}
                <flux:heading href="{{route('dashboard')}}" class="mt-0 pt-0 mb-0 pb-0">{!! auth()->user()->vendor->name !!}</flux:heading>
                {{-- <flux:brand href="{{route('dashboard')}}" name="{!! auth()->user()->vendor->name !!}" /> --}}
                <flux:navlist variant="outline">
                    {{-- BANK ERRORS --}}
                    @can('viewAny', App\Models\Bank::class)
                        @if(!auth()->user()->vendor->banks()->whereNotNull('plaid_access_token')->get()->where('plaid_options.error', '!=', FALSE)->isEmpty())
                            <flux:navlist.item wire:navigate.hover icon="building-library" href="/banks">
                                Banks
                                <flux:badge color="red" size="sm" inset="top bottom">
                                    Error
                                </flux:badge>
                            </flux:navlist.item>
                        @endif
                    @endcan

                    {{-- COMPANY EMAILS ERRORS --}}
                    {{-- @if(auth()->user()->vendor->company_emails()->get()->whereNotNull('api_json.errors')->isNotEmpty())
                        <li>
                            <a
                                wire:navigate.hover
                                href="{{route('company_emails.index')}}" class="flex p-2 text-sm leading-6 text-red-400 rounded-md hover:text-white hover:bg-red-700 group gap-x-3"
                                >
                                <svg class="w-6 h-6 text-red-400 shrink-0 group-hover:text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 9v.906a2.25 2.25 0 0 1-1.183 1.981l-6.478 3.488M2.25 9v.906a2.25 2.25 0 0 0 1.183 1.981l6.478 3.488m8.839 2.51-4.66-2.51m0 0-1.023-.55a2.25 2.25 0 0 0-2.134 0l-1.022.55m0 0-4.661 2.51m16.5 1.615a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V8.844a2.25 2.25 0 0 1 1.183-1.981l7.5-4.039a2.25 2.25 0 0 1 2.134 0l7.5 4.039a2.25 2.25 0 0 1 1.183 1.98V19.5Z" />
                                </svg>
                                Email Accounts
                                <span class="ml-auto w-9 min-w-max whitespace-nowrap rounded-full bg-red-600 px-2.5 py-0.5 text-center text-xs font-medium leading-5 text-white ring-1 ring-inset ring-red-500" aria-hidden="true">Error</span>
                            </a>
                        </li>
                    @endif --}}

                    {{-- RECEIPT ACCOUNTS ERRORS --}}
                    @if(auth()->user()->vendor->receipt_accounts()->get()->whereNotNull('options.errors')->isNotEmpty())
                        <flux:badge variant="solid" color="red" icon="exclamation-triangle" class="mb-4">
                            <a wire:navigate.hover href="/company_emails">
                                Account Error
                            </a>
                        </flux:badge>
                    @endif

                    <flux:separator class="m-2" />

                    <flux:navlist.item wire:navigate.hover icon="home" href="/dashboard">Home</flux:navlist.item>
                    @can('viewAny', App\Models\Lead::class)
                        <flux:navlist.item wire:navigate.hover icon="magnifying-glass-plus" href="/leads">Leads</flux:navlist.item>
                    @endcan
                    <flux:navlist.item wire:navigate.hover icon="folder" href="/projects">Projects</flux:navlist.item>
                    <flux:navlist.item icon="calendar" href="/planner">Planner</flux:navlist.item>

                    @canany(['viewAny', 'create'], App\Models\Expense::class)
                        <flux:navlist.group expandable heading="Finances">
                            <flux:navlist.item wire:navigate.hover href="/expenses" icon="credit-card">Expenses</flux:navlist.item>
                            @can('viewAny', App\Models\Bank::class)
                                <flux:navlist.item wire:navigate.hover href="/payments" icon="banknotes">Payments</flux:navlist.item>
                            @endcan
                            <flux:navlist.item wire:navigate.hover href="/checks" icon="pencil-square">Checks</flux:navlist.item>
                        </flux:navlist.group>
                    @endcanany

                    <flux:navlist.item wire:navigate.hover icon="user-group" href="/vendors">Vendors</flux:navlist.item>
                    <flux:navlist.item wire:navigate.hover icon="users" href="/clients">Clients</flux:navlist.item>

                    <flux:navlist.group expandable heading="Timesheets">
                        <flux:navlist.item wire:navigate.hover href="/hours/create" icon="clock">Hours</flux:navlist.item>
                        <flux:navlist.item wire:navigate.hover href="/timesheets" icon="document-currency-dollar">Timesheets</flux:navlist.item>
                        @can('viewPayment', App\Models\Timesheet::class)
                            <flux:navlist.item wire:navigate.hover href="/timesheets/payments" icon="currency-dollar">Payments</flux:navlist.item>
                        @endcan
                    </flux:navlist.group>

                    @can('viewAny', App\Models\Bank::class)
                        <flux:navlist.group expandable heading="Accounting">
                            <flux:navlist.item href="/banks" icon="building-library">Banks</flux:navlist.item>
                            <flux:navlist.item wire:navigate.hover href="/distributions" icon="receipt-percent">Distributions</flux:navlist.item>
                            <flux:navlist.item wire:navigate.hover href="/sheets" icon="document-currency-dollar">Sheets</flux:navlist.item>
                            <flux:navlist.item wire:navigate.hover href="/company_emails" icon="inbox-stack">Company Emails</flux:navlist.item>

                            @if(auth()->user()->primary_vendor->pivot->role_id === 1)
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

            <flux:dropdown position="top" align="left">
                <flux:profile name="{{auth()->user()->full_name}}" />

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

        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
        </flux:header>

        <flux:main>
            {{ $slot }}
        </flux:main>

        @persist('toast')
            <flux:toast />
        @endpersist

        @fluxScripts
    </body>
</html>
