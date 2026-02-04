@section('title', 'Hive Contractors')
<x-guest-layout>
    @php
        $navItems = [
            ['href' => '#features', 'label' => 'Features', 'icon' => 'sparkles', 'anchor' => true],
            ['href' => route('legal.privacy'), 'label' => 'Privacy', 'icon' => 'shield-check', 'anchor' => false],
            ['href' => route('legal.terms'), 'label' => 'Terms', 'icon' => 'document-text', 'anchor' => false],
        ];
    @endphp
    <flux:header sticky container class="!bg-white/70 dark:!bg-zinc-900/90 !backdrop-blur border-b border-zinc-200/80 dark:border-zinc-700/80 !py-2 lg:!py-1 !items-center !top-0 !z-50">
        <div class="flex items-center gap-2 flex-1">
            <flux:brand 
                href="{{ route('welcome') }}" 
                logo="{{ asset('favicon.png') }}" 
                name="Hive Contractors" 
                class="dark:hidden" 
                x-on:click.prevent="if (window.smoothScrollTo) { window.smoothScrollTo(0, 800); history.pushState(null, '', '/'); }"
            />
            <flux:brand 
                href="{{ route('welcome') }}" 
                logo="{{ asset('favicon.png') }}" 
                name="Hive Contractors" 
                class="hidden! dark:flex" 
                x-on:click.prevent="if (window.smoothScrollTo) { window.smoothScrollTo(0, 800); history.pushState(null, '', '/'); }"
            />
        </div>

        <div class="flex-1 justify-center max-lg:hidden flex">
            <flux:navbar class="-mb-px">
                @foreach ($navItems as $item)
                    @if ($item['anchor'])
                        <flux:navbar.item
                            href="{{ $item['href'] }}"
                            x-on:click.prevent="history.pushState(null, '', '{{ $item['href'] }}'); const el = document.querySelector('{{ $item['href'] }}'); if (el && window.smoothScrollTo) { window.smoothScrollTo(el.getBoundingClientRect().top + window.pageYOffset - 80, 800); }"
                        >{{ $item['label'] }}</flux:navbar.item>
                    @else
                        <flux:navbar.item href="{{ $item['href'] }}" wire:navigate.hover>{{ $item['label'] }}</flux:navbar.item>
                    @endif
                @endforeach
            </flux:navbar>
        </div>

        <div class="flex items-center gap-2 flex-1 justify-end">
            <flux:button
                href="{{ route('login') }}"
                class="max-lg:hidden !px-3 !py-2 text-sm font-semibold border border-transparent hover:border-zinc-300 dark:hover:border-zinc-600"
                wire:navigate.hover
            >
                Sign in
            </flux:button>
            <flux:button href="{{ route('registration') }}" class="!bg-indigo-600 hover:!bg-indigo-500 !text-white font-semibold !px-3 !py-2 text-sm" wire:navigate.hover>Get started</flux:button>

            <flux:dropdown class="lg:hidden" position="bottom" align="end">
                <flux:button variant="ghost" icon="bars-2" aria-label="Menu" class="!px-2 !py-2" />
                <flux:navmenu>
                    @foreach ($navItems as $item)
                        @if ($item['anchor'])
                            <flux:navmenu.item
                                href="{{ $item['href'] }}"
                                icon="{{ $item['icon'] }}"
                                x-on:click.prevent="history.pushState(null, '', '{{ $item['href'] }}'); const el = document.querySelector('{{ $item['href'] }}'); if (el && window.smoothScrollTo) { window.smoothScrollTo(el.getBoundingClientRect().top + window.pageYOffset - 80, 800); }"
                            >
                                {{ $item['label'] }}
                            </flux:navmenu.item>
                        @else
                            <flux:navmenu.item href="{{ $item['href'] }}" icon="{{ $item['icon'] }}" wire:navigate.hover>
                                {{ $item['label'] }}
                            </flux:navmenu.item>
                        @endif
                    @endforeach
                    <flux:navmenu.separator />
                    <flux:navmenu.item href="{{ route('login') }}" icon="arrow-right-end-on-rectangle" wire:navigate.hover>Sign in</flux:navmenu.item>
                </flux:navmenu>
            </flux:dropdown>
        </div>
    </flux:header>

    <div class="relative overflow-hidden bg-white isolate">
        <div class="px-6 pt-10 pb-24 mx-auto max-w-7xl sm:pb-32 lg:flex lg:px-8 lg:py-40">
            <div class="max-w-2xl mx-auto lg:mx-0 lg:max-w-xl lg:shrink-0 lg:pt-8">
                <a href="{{route('registration')}}" wire:navigate.hover>
                    <img class="h-36" src="{{ asset('favicon.png') }}" alt="{{env('APP_NAME')}}">
                </a>
                <div class="mt-24 sm:mt-32 lg:mt-16">
                    <a href="{{route('registration')}}" class="inline-flex space-x-6" wire:navigate.hover>
                        <span
                            class="px-3 py-1 text-sm font-semibold leading-6 text-indigo-600 rounded-full bg-indigo-600/10 ring-1 ring-inset ring-indigo-600/10">
                            Start Today
                        </span>
                        <span class="inline-flex items-center space-x-2 text-sm font-medium leading-6 text-gray-600">
                            <span>
                                See Why
                            </span>
                            <svg class="w-5 h-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z"
                                    clip-rule="evenodd" />
                            </svg>
                        </span>
                    </a>
                </div>
                <h1 class="mt-10 text-4xl font-bold tracking-tight text-gray-900 sm:text-6xl scroll-mt-24" id="features">
                    The CRM built for small contractors
                </h1>
                <p class="mt-6 text-lg leading-8 text-gray-600">
                    Hive Contractors brings your business under one roof. Automate finances, vendor collaboration,
                    homeowner updates, schedules, timesheets, COIs, receipts, and audits—so you can focus on building.
                    <br>
                    <b>Made by Contractors. For Contractors.<b>
                </p>
                <div class="flex items-center mt-10 gap-x-6">
                    <a href="{{route('registration')}}" class="rounded-md bg-indigo-600 px-3.5 py-1.5 text-base font-semibold leading-7 text-white shadow-xs hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600" wire:navigate.hover>
                        Create your Hive
                    </a>
                    <a href="{{route('login')}}" class="text-base font-semibold leading-7 text-gray-900" wire:navigate.hover>Log in<span aria-hidden="true">→</span></a>
                </div>
            </div>
            <div class="flex max-w-2xl mx-auto mt-16 sm:mt-24 lg:ml-10 lg:mr-0 lg:mt-0 lg:max-w-none lg:flex-none xl:ml-32">
                <div class="flex-none max-w-3xl sm:max-w-5xl lg:max-w-none">
                    <div
                        class="p-2 -m-2 rounded-xl bg-gray-900/5 ring-1 ring-inset ring-gray-900/10 lg:-m-4 lg:rounded-2xl lg:p-4">

                        <img
                            src="{{ asset('hive_expenses_1.png') }}"
                            alt="App screenshot"
                            width="850"
                            class="w-[76rem] rounded-md shadow-2xl ring-1 ring-gray-900/10"
                            >
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- SECTIONS --}}
    <div class="py-24 overflow-hidden bg-gray-100 sm:py-32">
        <div class="px-6 mx-auto max-w-7xl lg:px-8">
            <div
                class="grid max-w-2xl grid-cols-1 mx-auto gap-x-8 gap-y-16 sm:gap-y-20 lg:mx-0 lg:max-w-none lg:grid-cols-2">
                <div class="lg:ml-auto lg:pl-4 lg:pt-4">
                    <div class="lg:max-w-lg">
                        <a href="{{route('registration')}}" class="inline-flex space-x-6" wire:navigate.hover>
                            <h2 class="text-base font-semibold leading-7 text-indigo-600">
                                Join Hive Contractors
                            </h2>
                        </a>

                        <p class="mt-2 text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">Automation that pays for itself</p>
                        <p class="mt-6 text-lg font-normal leading-8 text-gray-600">
                            Hive Contractors is a CRM built for small contractors—combining finances, scheduling,
                            and project management in one automated workspace. Everything connects, so work moves
                            faster with less effort.
                        </p>
                        <dl class="max-w-xl mt-10 space-y-8 text-base leading-7 text-gray-600 lg:max-w-none">
                            <div class="relative pl-9">
                                <dt class="inline font-semibold text-gray-900">
                                    <svg class="absolute w-5 h-5 text-indigo-600 left-1 top-1" viewBox="0 0 20 20"
                                        fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                                    </svg>
                                    Finances + QuickBooks sync.
                                </dt>
                                <dd class="inline font-normal">
                                    Automatically consolidate receipts and transactions, with clean books and quick access
                                    to balance sheets and profit & loss statements.
                                </dd>
                            </div>
                            <div class="relative pl-9">
                                <dt class="inline font-semibold text-gray-900">
                                    <svg class="absolute w-5 h-5 text-indigo-600 left-1 top-1" viewBox="0 0 20 20"
                                        fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                                    </svg>
                                    Vendor-to-vendor collaboration.
                                </dt>
                                <dd class="inline font-normal">
                                    Streamline bids, payments, and certificates of insurance across your vendor network.
                                </dd>
                            </div>
                            <div class="relative pl-9">
                                <dt class="inline font-semibold text-gray-900">
                                    <svg class="absolute w-5 h-5 text-indigo-600 left-1 top-1" viewBox="0 0 20 20"
                                        fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                                    </svg>
                                    Estimates, invoices, and change orders.
                                </dt>
                                <dd class="inline font-normal">
                                    Win bids faster with professional docs and one-click conversions.
                                </dd>
                            </div>
                            <div class="relative pl-9">
                                <dt class="inline font-semibold text-gray-900">
                                    <svg class="absolute w-5 h-5 text-indigo-600 left-1 top-1" viewBox="0 0 20 20"
                                        fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                                    </svg>
                                    Project schedules (Gantt + Kanban).
                                </dt>
                                <dd class="inline font-normal">
                                    Keep teams aligned with live timelines, dependencies, and task boards.
                                </dd>
                            </div>
                            <div class="relative pl-9">
                                <dt class="inline font-semibold text-gray-900">
                                    <svg class="absolute w-5 h-5 text-indigo-600 left-1 top-1" viewBox="0 0 20 20"
                                        fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                                    </svg>
                                    Team schedules + timesheets.
                                </dt>
                                <dd class="inline font-normal">
                                    Track time, availability, and payroll across jobs without spreadsheets.
                                </dd>
                            </div>
                            <div class="relative pl-9">
                                <dt class="inline font-semibold text-gray-900">
                                    <svg class="absolute w-5 h-5 text-indigo-600 left-1 top-1" viewBox="0 0 20 20"
                                        fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                                    </svg>
                                    Homeowner hubs + COI + audits.
                                </dt>
                                <dd class="inline font-normal">
                                    Give clients real-time project updates, keep COIs current, and run one‑click audits.
                                </dd>
                            </div>
                        </dl>
                    </div>
                    <div class="mt-8">
                        <a href="{{route('registration')}}" class="inline-flex rounded-md bg-indigo-600 px-3.5 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600" wire:navigate.hover>
                            Create you Hive
                        </a>
                    </div>
                </div>
                <div class="flex items-start justify-end lg:order-first">
                    <div class="flex-none max-w-3xl sm:max-w-5xl lg:max-w-none">
                        <div
                            class="p-2 -m-2 rounded-xl bg-gray-900/5 ring-1 ring-inset ring-gray-900/10 lg:-m-4 lg:rounded-2xl lg:p-4">

                            <img
                                src="{{ asset('hive_expenses_2.png') }}"
                                alt="Project details screenshot"
                                width="850"
                                class="w-[76rem] rounded-md shadow-2xl ring-1 ring-gray-900/10"
                                >
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
    {{-- SECTIONS --}}
    {{-- <div class="py-24 bg-white sm:py-32">
        <div class="px-6 mx-auto max-w-7xl lg:px-8">
            <div class="max-w-2xl mx-auto lg:text-center">
                <h2 class="text-base font-semibold leading-7 text-indigo-600">
                    Automate your construction project finances.
                </h2>
                <p class="mt-2 text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">
                    Everything you need to automate your project finances.
                </p>
                <p class="mt-6 text-lg leading-8 text-gray-600">
                    <i>Your project finances sorted & organized automatically.</i>
                    <br>
                    Subcontractor bids & payments. Receipts & invoices.
                    <br>
                    Employee timesheets & payments. Bank transactions & expenses.
                    <br>Audits & taxes. Client & employee reimburesements.
                </p>
                <h2 class="text-base font-semibold leading-7 text-indigo-600">
                    All automated specifically for your construction business.
                </h2>
            </div>

            <div class="max-w-2xl m-10 mx-auto lg:text-center gap-x-6">
                <a href="{{route('registration')}}" class="px-4 py-3 text-base font-semibold leading-7 text-white bg-indigo-600 rounded-md shadow-xs hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600" wire:navigate.hover>
                    Create your Hive
                </a>
            </div>
            <div class="max-w-2xl mx-auto mt-16 sm:mt-20 lg:mt-24 lg:max-w-4xl">
                <dl class="grid max-w-xl grid-cols-1 gap-x-8 gap-y-10 lg:max-w-none lg:grid-cols-2 lg:gap-y-16">
                    <div class="relative pl-16">
                        <dt class="text-base font-semibold leading-7 text-gray-900">
                            <div
                                class="absolute top-0 left-0 flex items-center justify-center w-10 h-10 bg-indigo-600 rounded-lg">
                                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                    stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z" />
                                </svg>
                            </div>
                            Receipts Automated
                        </dt>
                        <dd class="mt-2 text-base leading-7 text-gray-600">
                            Your email and uploaded receipts sorted and linked with your bank transactions.
                        </dd>
                    </div>
                    <div class="relative pl-16">
                        <dt class="text-base font-semibold leading-7 text-gray-900">
                            <div
                                class="absolute top-0 left-0 flex items-center justify-center w-10 h-10 bg-indigo-600 rounded-lg">
                                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                    stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                </svg>
                            </div>
                            Your transactions matched with expenses.
                        </dt>
                        <dd class="mt-2 text-base leading-7 text-gray-600">
                            Your transactions on business accounts are automatically linked with expenses and catagorized.
                        </dd>
                    </div>
                    <div class="relative pl-16">
                        <dt class="text-base font-semibold leading-7 text-gray-900">
                            <div
                                class="absolute top-0 left-0 flex items-center justify-center w-10 h-10 bg-indigo-600 rounded-lg">
                                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                    stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                                </svg>
                            </div>
                            Your employees track thier Hours and Timesheets.
                        </dt>
                        <dd class="mt-2 text-base leading-7 text-gray-600">
                            Pay your employees with a click.
                        </dd>
                    </div>
                    <div class="relative pl-16">
                        <dt class="text-base font-semibold leading-7 text-gray-900">
                            <div
                                class="absolute top-0 left-0 flex items-center justify-center w-10 h-10 bg-indigo-600 rounded-lg">
                                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                    stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M7.864 4.243A7.5 7.5 0 0119.5 10.5c0 2.92-.556 5.709-1.568 8.268M5.742 6.364A7.465 7.465 0 004.5 10.5a7.464 7.464 0 01-1.15 3.993m1.989 3.559A11.209 11.209 0 008.25 10.5a3.75 3.75 0 117.5 0c0 .527-.021 1.049-.064 1.565M12 10.5a14.94 14.94 0 01-3.6 9.75m6.633-4.596a18.666 18.666 0 01-2.485 5.33" />
                                </svg>
                            </div>
                            Interconectivyy between you and you GCs and Subcontractors So everyone involved on each project is able to stay ahead, know what's coming in and who is owed.
                        </dt>
                        <dd class="mt-2 text-base leading-7 text-gray-600">So everyone involved on each project is able to stay ahead, know what's coming in and who is owed.</dd>
                    </div>
                </dl>
            </div>
        </div>
    </div> --}}

    {{-- CALL TO ACTION DARK --}}
    <div class="bg-indigo-700">
        <div class="px-6 py-24 sm:px-6 sm:py-32 lg:px-8">
            <div class="max-w-2xl mx-auto text-center">
                <h2 class="text-3xl font-bold tracking-tight text-white sm:text-4xl">
                    Focus on your projects.
                    <br>
                    Leave bookkeeping to us.
                    {{-- We will take care of your bookkeeping. --}}
                </h2>
                <p class="max-w-xl mx-auto mt-6 text-lg font-normal leading-8 text-indigo-200">
                    Managing projects is hard enough. Let us take care of the details.
                </p>
                {{-- <p class="max-w-xl mx-auto mt-6 text-lg leading-8 text-indigo-200">
                    Managing projects is hard enough. Let us take care of the details.
                </p> --}}
                <div class="flex items-center justify-center mt-10 gap-x-6">
                    <a href="{{route('registration')}}" wire:navigate.hover
                        class="rounded-md bg-white px-3.5 py-2.5 text-sm font-semibold text-indigo-600 shadow-xs hover:bg-indigo-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                        Create your Hive
                    </a>
                    <a href="#" class="text-sm font-semibold leading-6 text-white">
                        Learn more
                        <span
                            aria-hidden="true">→
                        </span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <footer class="bg-white dark:bg-gray-900">
        <div class="mx-auto max-w-7xl px-6 pt-16 pb-8 sm:pt-24 lg:px-8 lg:pt-28">
            <div class="grid gap-12 lg:grid-cols-3">
                <div class="space-y-6">
                    <div class="flex items-center gap-3">
                        <img src="{{ asset('favicon.png') }}" alt="Hive Contractors" class="h-9" />
                        <a href="{{ route('welcome') }}" class="text-base font-semibold text-gray-900 hover:text-gray-700 dark:text-white dark:hover:text-gray-300" wire:navigate.hover>Hive Contractors</a>
                    </div>
                    <p class="text-sm/6 text-balance text-gray-600 dark:text-gray-400 font-normal">
                        Purpose-built CRM for contractors—streamline projects, vendors, finances, and client updates in one place.
                    </p>
                    <div class="text-sm/6 text-gray-600 dark:text-gray-400 font-normal">
                        <div>305 S Ridge St, PO Box 1504</div>
                        <div>Breckenridge, CO 80424</div>
                        <div>
                            <a href="tel:+12249993880" class="hover:text-gray-900 dark:hover:text-white font-normal">(224) 999-3880</a>
                        </div>
                        <div>
                            <a href="mailto:{{ config('mail.from.address') }}" class="hover:text-gray-900 dark:hover:text-white font-normal">{{ config('mail.from.address') }}</a>
                        </div>
                    </div>
                </div>
                <div class="grid gap-8 sm:grid-cols-2 lg:col-span-2">
                    <div>
                        <h3 class="text-sm/6 font-semibold text-gray-900 dark:text-white">Product</h3>
                        <ul role="list" class="mt-4 space-y-3">
                            <li>
                                <a href="#features" class="text-sm/6 text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-300 font-normal">Features</a>
                            </li>
                            <li>
                                <a href="{{ route('registration') }}" class="text-sm/6 text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-300 font-normal" wire:navigate.hover>Get started</a>
                            </li>
                            <li>
                                <a href="{{ route('login') }}" class="text-sm/6 text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-300 font-normal" wire:navigate.hover>Sign in</a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="mt-12 border-t border-gray-900/10 pt-6 text-sm/6 text-gray-600 dark:border-white/10 dark:text-gray-400 font-normal">
                <div class="flex flex-col items-center gap-3 sm:flex-row sm:justify-center sm:gap-6">
                    <span>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</span>
                    <div class="flex items-center gap-4">
                        <a href="{{ route('legal.terms') }}" class="text-sm/6 text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-300 font-normal" wire:navigate.hover>Terms of service</a>
                        <a href="{{ route('legal.privacy') }}" class="text-sm/6 text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-300 font-normal" wire:navigate.hover>Privacy policy</a>
                    </div>
                </div>
            </div>
        </div>
    </footer>
</x-guest-layout>

