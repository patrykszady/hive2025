@section('title', 'Selections & Allowances — Hive Contractors')
<x-guest-layout>
    <x-marketing.nav active="homeowners" />

    <x-marketing.feature-hero
        icon="swatch"
        eyebrow="Your homeowner portal"
        title="Track your choices and stay on budget"
        body="Every finish you pick—tile, fixtures, countertops—lives in one place, right next to its allowance. See what you have chosen and exactly where each budget stands, with no surprises at the end."
    />

    {{-- DEEP ROWS --}}
    <div class="py-20 bg-white dark:bg-zinc-950 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8 space-y-24">

            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">Every selection in one place</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        No more sticky notes and email chains about which faucet you picked. Your choices are recorded
                        clearly so you and your contractor are always working from the same list.
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            'All of your finish choices in one list',
                            'Product details so nothing is mixed up',
                            'Approve selections as you go',
                            'Shared with your contractor instantly',
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-6 rounded-2xl bg-gray-50 dark:bg-zinc-900 ring-1 ring-gray-200 dark:ring-zinc-800">
                    <p class="text-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">Your selections</p>
                    <div class="mt-4 space-y-3">
                        @php
                            $selections = [
                                ['swatch', 'Floor tile', 'Carrara 12x24 · approved'],
                                ['beaker', 'Faucet', 'Brushed gold · approved'],
                                ['cube', 'Countertop', 'Quartz, Calacatta · pending'],
                            ];
                        @endphp
                        @foreach ($selections as $sel)
                            <div class="flex items-center gap-3 p-3 rounded-xl bg-white dark:bg-zinc-950 ring-1 ring-gray-200 dark:ring-zinc-800">
                                <flux:icon name="{{ $sel[0] }}" class="w-5 h-5 text-indigo-600 dark:text-indigo-400" />
                                <div>
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $sel[1] }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $sel[2] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div class="lg:order-last">
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">See where each allowance stands</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        Each category has a budget, and Hive shows you how your choices stack up against it. If a
                        selection runs over, you see it right away—so you can decide before it ever hits your invoice.
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            'A budget bar for every allowance',
                            'Instant view when something runs over',
                            'Decide before extra costs are committed',
                            'No surprise overages at the end',
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-8 rounded-2xl bg-indigo-600 shadow-xl">
                    <p class="text-xs font-semibold tracking-wide text-indigo-100 uppercase">Tile allowance</p>
                    <div class="p-4 mt-4 rounded-xl bg-white/95 text-gray-900">
                        <div class="flex justify-between text-sm"><span>Budget</span><span class="font-semibold">$2,500</span></div>
                        <div class="flex justify-between mt-2 text-sm"><span>Your selection</span><span class="font-semibold">$2,320</span></div>
                        <div class="h-2 mt-3 rounded-full bg-gray-200">
                            <div class="h-2 rounded-full w-[93%] bg-emerald-500"></div>
                        </div>
                        <p class="mt-2 text-xs font-medium text-emerald-600">$180 under budget</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- DETAIL GRID --}}
    <div class="py-20 bg-gray-100 dark:bg-zinc-900 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8">
            <div class="max-w-2xl mx-auto text-center">
                <h2 class="text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl">Choices without the chaos</h2>
            </div>
            <dl class="grid max-w-xl grid-cols-1 mx-auto mt-16 gap-x-8 gap-y-12 lg:max-w-none lg:grid-cols-3">
                @php
                    $cards = [
                        ['icon' => 'swatch', 'title' => 'Selection tracking', 'body' => 'Every finish choice recorded in one list.'],
                        ['icon' => 'chart-bar', 'title' => 'Allowance balances', 'body' => 'See your picks against each budget.'],
                        ['icon' => 'exclamation-triangle', 'title' => 'Overage visibility', 'body' => 'Know right away if a choice runs over.'],
                        ['icon' => 'check-circle', 'title' => 'Approvals', 'body' => 'Sign off on selections as you finalize them.'],
                        ['icon' => 'tag', 'title' => 'Product details', 'body' => 'Models and finishes so nothing gets confused.'],
                        ['icon' => 'banknotes', 'title' => 'Budget clarity', 'body' => 'Connect selections to your overall cost.'],
                    ];
                @endphp
                @foreach ($cards as $card)
                    <div class="relative pl-16">
                        <dt class="text-base font-semibold leading-7 text-gray-900 dark:text-white">
                            <div class="absolute top-0 left-0 flex items-center justify-center w-10 h-10 bg-indigo-600 rounded-lg">
                                <flux:icon name="{{ $card['icon'] }}" class="w-6 h-6 text-white" />
                            </div>
                            {{ $card['title'] }}
                        </dt>
                        <dd class="mt-2 text-base leading-7 text-gray-600 dark:text-gray-400">{{ $card['body'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </div>

    <x-marketing.portal-links current="selections" heading="Explore the rest of your portal" />

    <x-marketing.homeowner-cta />

    <x-marketing.footer />
</x-guest-layout>
