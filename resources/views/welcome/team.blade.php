@section('title', __('Team & Time — Hive Contractors'))
<x-guest-layout>
    <x-marketing.nav active="team" />

    <x-marketing.feature-hero
        icon="clock"
        eyebrow="{{ __('Team & Time') }}"
        title="{{ __('Track hours and run payroll—without spreadsheets') }}"
        body="{{ __('Crews clock time against the right job from their phone. You review, approve, and pay from the same screen—so labor cost lands on every project and your team gets paid right, on time.') }}"
    />

    {{-- DEEP FEATURE ROWS --}}
    <div class="py-20 bg-white dark:bg-zinc-950 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8 space-y-24">

            {{-- Time tracking --}}
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">{{ __('Hours logged from the job site') }}</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        {{ __('Your crew logs hours against the right project from their phone—no paper timecards, no end-of-week guessing. You see labor as it happens and approve timesheets in a few taps.') }}
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            __('Mobile time tracking by job and task'),
                            __('Timesheet review and one-tap approval'),
                            __('Labor cost flows straight into job costing'),
                            __('No paper timecards or weekend spreadsheets'),
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-6 rounded-2xl bg-gray-50 dark:bg-zinc-900 ring-1 ring-gray-200 dark:ring-zinc-800">
                    <p class="text-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">{{ __('This week · 123 Maple St') }}</p>
                    <div class="mt-4 space-y-3">
                        @php
                            $hours = [
                                ['Greg M.', __('Plumbing'), '32.5 hrs'],
                                ['Tony R.', __('Demo & framing'), '28.0 hrs'],
                                ['Sam K.', __('Tile'), '18.0 hrs'],
                            ];
                        @endphp
                        @foreach ($hours as $h)
                            <div class="flex items-center justify-between p-3 rounded-xl bg-white dark:bg-zinc-950 ring-1 ring-gray-200 dark:ring-zinc-800">
                                <div>
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $h[0] }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $h[1] }}</p>
                                </div>
                                <span class="text-sm font-semibold text-indigo-600 dark:text-indigo-400">{{ $h[2] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Payroll --}}
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div class="lg:order-last">
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">{{ __('Pay your crew from the same screen') }}</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        {{ __('Approved hours roll straight into payments. Track running balances for every worker, record payouts, and keep roles tight so people only see what they should.') }}
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            __('Payroll and timesheet payments in one flow'),
                            __('Running balance for every worker'),
                            __('Roles and permissions for your team'),
                            __('Records that match your books automatically'),
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-8 rounded-2xl bg-indigo-600 shadow-xl">
                    <p class="text-xs font-semibold tracking-wide text-indigo-100 uppercase">{{ __('Payout · Greg M.') }}</p>
                    <div class="p-4 mt-4 rounded-xl bg-white/95 text-gray-900">
                        <div class="flex justify-between text-sm"><span>32.5 hrs @ $42</span><span>$1,365.00</span></div>
                        <div class="flex justify-between mt-1 text-sm"><span>{{ __('Prior balance') }}</span><span>$0.00</span></div>
                        <div class="flex justify-between pt-2 mt-2 font-semibold border-t border-gray-200"><span>{{ __('Pay this week') }}</span><span>$1,365.00</span></div>
                    </div>
                    <div class="flex items-center gap-2 mt-4 text-sm font-medium text-white">
                        <flux:icon name="check-badge" class="w-5 h-5" />
                        <span>{{ __('Recorded & synced to books') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <x-marketing.feature-links area="team" heading="{{ __('Time and pay, in sync') }}" />

    <x-marketing.cta
        heading="{{ __('Get hours and payroll off your kitchen table.') }}"
        subheading="{{ __('Let your crew clock in from the field and pay them right from the same place.') }}"
    />

    <x-marketing.footer />
</x-guest-layout>
