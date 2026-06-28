@php
    $cols = [
        [
            'heading' => 'For contractors',
            'links' => [
                ['label' => 'Finances & Receipts',  'route' => 'welcome.finances'],
                ['label' => 'Estimates & Documents', 'route' => 'welcome.estimates'],
                ['label' => 'Leads & Clients',       'route' => 'welcome.clients'],
                ['label' => 'Vendors & Compliance',  'route' => 'welcome.vendors'],
                ['label' => 'Planning & Scheduling', 'route' => 'welcome.planning'],
                ['label' => 'Team & Time',           'route' => 'welcome.team'],
                ['label' => 'Communication',         'route' => 'welcome.communication'],
                ['label' => 'Automation & AI',       'route' => 'welcome.automation'],
            ],
        ],
        [
            'heading' => 'For homeowners',
            'links' => [
                ['label' => 'Project status',       'route' => 'welcome.homeowners.status'],
                ['label' => 'Schedule & reminders', 'route' => 'welcome.homeowners.schedule'],
                ['label' => 'Messaging',            'route' => 'welcome.homeowners.messaging'],
                ['label' => 'Photos',               'route' => 'welcome.homeowners.photos'],
                ['label' => 'Documents',            'route' => 'welcome.homeowners.documents'],
                ['label' => 'Payments',             'route' => 'welcome.homeowners.payments'],
                ['label' => 'Selections',           'route' => 'welcome.homeowners.selections'],
                ['label' => 'Notifications',        'route' => 'welcome.homeowners.notifications'],
                ['label' => 'Access & login',       'route' => 'welcome.homeowners.access'],
            ],
        ],
        [
            'heading' => 'Get started',
            'links' => [
                ['label' => 'Create your Hive', 'route' => 'registration'],
                ['label' => 'Sign in',           'route' => 'login'],
                ['label' => 'FAQ',               'route' => 'welcome.faq'],
                ['label' => 'Homeowner portal',  'route' => 'welcome.homeowners'],
            ],
        ],
        [
            'heading' => 'Legal',
            'links' => [
                ['label' => 'Terms of service', 'route' => 'legal.terms'],
                ['label' => 'Privacy policy',   'route' => 'legal.privacy'],
            ],
        ],
    ];
@endphp

<footer class="bg-white dark:bg-gray-900 border-t border-gray-900/10 dark:border-white/10">
    <div class="mx-auto max-w-7xl px-6 pt-16 pb-8 sm:pt-24 lg:px-8 lg:pt-28">
        <div class="grid gap-12 lg:grid-cols-5">
            {{-- Brand column --}}
            <div class="space-y-6 lg:col-span-1">
                <div class="flex items-center gap-3">
                    <x-hive-logo class="h-9 w-9" />
                    <a href="{{ route('welcome') }}" class="text-base font-semibold text-gray-900 hover:text-gray-700 dark:text-white dark:hover:text-gray-300" wire:navigate.hover>Hive Contractors</a>
                </div>
                <p class="text-sm/6 text-balance text-gray-600 dark:text-gray-400 font-normal">
                    Purpose-built CRM for contractors—streamline projects, vendors, finances, and client updates in one place. Made by contractors, for contractors.
                </p>
                <div class="text-sm/6 text-gray-600 dark:text-gray-400 font-normal space-y-1">
                    <div>305 S Ridge St, PO Box 1504</div>
                    <div>Breckenridge, CO 80424</div>
                    <div>
                        <a href="tel:+12249993880" class="hover:text-gray-900 dark:hover:text-white">(224) 999-3880</a>
                    </div>
                    <div>
                        <a href="mailto:{{ config('mail.from.address') }}" class="hover:text-gray-900 dark:hover:text-white">{{ config('mail.from.address') }}</a>
                    </div>
                </div>
            </div>

            {{-- Link columns --}}
            <div class="grid gap-8 sm:grid-cols-2 lg:col-span-4 lg:grid-cols-4">
                @foreach ($cols as $col)
                    <div>
                        <h3 class="text-sm/6 font-semibold text-gray-900 dark:text-white">{{ $col['heading'] }}</h3>
                        <ul role="list" class="mt-4 space-y-2">
                            @foreach ($col['links'] as $link)
                                <li>
                                    <a href="{{ route($link['route']) }}" class="text-sm/6 text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-300 font-normal" wire:navigate.hover>{{ $link['label'] }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="mt-12 border-t border-gray-900/10 pt-8 dark:border-white/10">
            <p class="text-xs/6 font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-500">Contractors running on Hive</p>
            <ul role="list" class="mt-4 flex flex-wrap items-center gap-x-8 gap-y-3">
                @foreach ([['name' => 'GS Construction', 'url' => 'https://gs.construction', 'favicon' => 'https://gs.construction/favicon.svg']] as $company)
                    <li>
                        <a href="{{ $company['url'] }}" target="_blank" rel="noopener" class="flex items-center gap-2 text-sm font-semibold text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">
                            <img src="{{ $company['favicon'] }}" alt="{{ $company['name'] }}" class="w-5 h-5 rounded" loading="lazy" />
                            {{ $company['name'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="mt-8 border-t border-gray-900/10 pt-6 text-sm/6 text-gray-600 dark:border-white/10 dark:text-gray-400 font-normal">
            <div class="flex flex-col items-center gap-3 sm:flex-row sm:justify-between sm:gap-6">
                <span>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</span>
                <span>Made by Contractors. For Contractors.</span>
            </div>
        </div>
    </div>
</footer>

