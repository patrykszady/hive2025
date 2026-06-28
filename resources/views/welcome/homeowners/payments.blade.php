@section('title', 'Invoices & Payments — Hive Contractors')
<x-guest-layout>
    <x-marketing.nav active="homeowners" />

    <x-marketing.feature-hero
        icon="banknotes"
        eyebrow="Your homeowner portal"
        title="Always know what you owe and what you've paid"
        body="Clear, itemized invoices and a running total in one place. See exactly where your money has gone, what is left, and when payments are due—no spreadsheets or guesswork."
    />

    {{-- DEEP ROWS --}}
    <div class="py-20 bg-white dark:bg-zinc-950 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8 space-y-24">

            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">Invoices that make sense</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        Each invoice is itemized so you can see what you are paying for and when it is due. No surprise
                        line items, no math to do yourself—just a clear bill you can trust.
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            'Itemized so you see every charge',
                            'Clear due dates on every invoice',
                            'Tied to the work that was completed',
                            'Easy to review on your phone',
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-6 rounded-2xl bg-gray-50 dark:bg-zinc-900 ring-1 ring-gray-200 dark:ring-zinc-800">
                    <div class="flex items-center justify-between pb-3 border-b border-gray-200 dark:border-zinc-800">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">Invoice #318</p>
                        <span class="px-2 py-1 text-xs font-semibold text-indigo-700 rounded bg-indigo-100">Due 7/10</span>
                    </div>
                    <div class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between text-gray-700 dark:text-gray-300"><span>Progress payment · Rough-in</span><span>$4,200</span></div>
                        <div class="flex justify-between text-gray-700 dark:text-gray-300"><span>Materials</span><span>$1,180</span></div>
                        <div class="flex justify-between pt-2 mt-2 font-semibold text-gray-900 border-t border-gray-200 dark:text-white dark:border-zinc-800"><span>Amount due</span><span>$5,380</span></div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div class="lg:order-last">
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">A running total you can trust</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        See the full picture: contract total, paid to date, and remaining balance. Every payment is
                        recorded, so you always know where you stand financially on your project.
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            'Contract total and balance at a glance',
                            'Every payment recorded automatically',
                            'No more wondering what is left',
                            'Tracks alongside your selections and allowances',
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-8 rounded-2xl bg-indigo-600 shadow-xl">
                    <p class="text-xs font-semibold tracking-wide text-indigo-100 uppercase">Project balance</p>
                    <div class="p-4 mt-4 rounded-xl bg-white/95 text-gray-900">
                        <div class="flex justify-between text-sm"><span>Contract total</span><span class="font-semibold">$48,000</span></div>
                        <div class="flex justify-between mt-2 text-sm"><span>Paid to date</span><span class="font-semibold text-emerald-600">$31,200</span></div>
                        <div class="h-2 mt-3 rounded-full bg-gray-200">
                            <div class="h-2 rounded-full w-[65%] bg-indigo-600"></div>
                        </div>
                        <div class="flex justify-between mt-3 text-sm font-semibold"><span>Remaining</span><span>$16,800</span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- DETAIL GRID --}}
    <div class="py-20 bg-gray-100 dark:bg-zinc-900 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8">
            <div class="max-w-2xl mx-auto text-center">
                <h2 class="text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl">Money matters, made clear</h2>
            </div>
            <dl class="grid max-w-xl grid-cols-1 mx-auto mt-16 gap-x-8 gap-y-12 lg:max-w-none lg:grid-cols-3">
                @php
                    $cards = [
                        ['icon' => 'document-text', 'title' => 'Itemized invoices', 'body' => 'See exactly what each charge is for.'],
                        ['icon' => 'clock', 'title' => 'Payment history', 'body' => 'A record of every payment you have made.'],
                        ['icon' => 'calculator', 'title' => 'Running balance', 'body' => 'Always know paid-to-date and what is left.'],
                        ['icon' => 'swatch', 'title' => 'Allowance tracking', 'body' => 'Watch your selections against the budget.'],
                        ['icon' => 'calendar-date-range', 'title' => 'Clear due dates', 'body' => 'Never miss a payment with up-front dates.'],
                        ['icon' => 'receipt-percent', 'title' => 'Receipts', 'body' => 'Keep a clean record for your own files.'],
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

    <x-marketing.portal-links current="payments" heading="Explore the rest of your portal" />

    <x-marketing.homeowner-cta />

    <x-marketing.footer />
</x-guest-layout>
