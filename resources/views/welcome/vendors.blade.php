@section('title', __('Vendors & Compliance — Hive Contractors'))
<x-guest-layout>
    <x-marketing.nav active="vendors" />

    <x-marketing.feature-hero
        icon="user-group"
        eyebrow="{{ __('Vendors & Compliance') }}"
        title="{{ __('Keep your subs in sync—and your jobs covered') }}"
        body="{!! __('Manage every subcontractor and supplier in one directory, collaborate on bids and payments, and stay on top of insurance certificates and workers&rsquo; comp so you are never caught uninsured at audit time.') !!}"
    />

    {{-- DEEP FEATURE ROWS --}}
    <div class="py-20 bg-white dark:bg-zinc-950 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8 space-y-24">

            {{-- Vendor network --}}
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">{{ __('Your whole sub network in one place') }}</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        {!! __('Keep every vendor&rsquo;s contact info, trade, rates, and history together. Request availability, collaborate on bids, and pay them—all tied to the right job without leaving Hive.') !!}
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            __('Vendor directory with trades, rates, and history'),
                            __('Vendor-to-vendor bids and collaboration'),
                            __('Availability requests answered by text link'),
                            __('Vendor payments tied to the right project'),
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-6 rounded-2xl bg-gray-50 dark:bg-zinc-900 ring-1 ring-gray-200 dark:ring-zinc-800">
                    <p class="text-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">{{ __('Vendors') }}</p>
                    <div class="mt-4 space-y-3">
                        @php
                            $vendors = [
                                [__('Summit Plumbing'), __('Plumbing'), __('Available')],
                                [__('Bright Spark Electric'), __('Electrical'), __('On a job')],
                                [__('Pro Tile Co.'), __('Tile & stone'), __('Available')],
                            ];
                        @endphp
                        @foreach ($vendors as $vendor)
                            <div class="flex items-center justify-between p-3 rounded-xl bg-white dark:bg-zinc-950 ring-1 ring-gray-200 dark:ring-zinc-800">
                                <div>
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $vendor[0] }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $vendor[1] }}</p>
                                </div>
                                <span class="text-xs font-semibold text-indigo-600 dark:text-indigo-400">{{ $vendor[2] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Compliance --}}
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div class="lg:order-last">
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">{{ __('Never get caught uninsured') }}</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        {!! __('Hive stores every certificate of insurance and workers&rsquo; comp document, watches expiration dates, and flags anything lapsing—so a missing COI never stalls a job or fails an audit.') !!}
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            __('Certificate of insurance (COI) tracking'),
                            __('Workers&rsquo; comp verification with expiry alerts'),
                            __('Automated document audits and storage'),
                            __('Insurance agent contacts kept on hand'),
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-8 rounded-2xl bg-indigo-600 shadow-xl">
                    <p class="text-xs font-semibold tracking-wide text-indigo-100 uppercase">{{ __('Insurance status') }}</p>
                    <div class="mt-4 space-y-3">
                        <div class="flex items-center justify-between p-3 rounded-xl bg-white/95 text-gray-900">
                            <span class="text-sm font-medium">{{ __('Summit Plumbing · COI') }}</span>
                            <span class="flex items-center gap-1 text-xs font-semibold text-emerald-600"><flux:icon name="check-badge" class="w-4 h-4" /> {{ __('Current') }}</span>
                        </div>
                        <div class="flex items-center justify-between p-3 rounded-xl bg-white/95 text-gray-900">
                            <span class="text-sm font-medium">{!! __('Bright Spark · Workers&rsquo; comp') !!}</span>
                            <span class="flex items-center gap-1 text-xs font-semibold text-amber-600"><flux:icon name="exclamation-triangle" class="w-4 h-4" /> {{ __('Expires 14d') }}</span>
                        </div>
                        <div class="flex items-center justify-between p-3 rounded-xl bg-white/95 text-gray-900">
                            <span class="text-sm font-medium">{{ __('Pro Tile Co. · COI') }}</span>
                            <span class="flex items-center gap-1 text-xs font-semibold text-emerald-600"><flux:icon name="check-badge" class="w-4 h-4" /> {{ __('Current') }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <x-marketing.feature-links area="vendors" heading="{{ __('Subs and coverage, handled') }}" />

    <x-marketing.cta
        heading="{{ __('Keep your subs close and your coverage current.') }}"
        subheading="{{ __('Manage vendors and compliance in one place so a missing COI never stalls a job.') }}"
    />

    <x-marketing.footer />
</x-guest-layout>
