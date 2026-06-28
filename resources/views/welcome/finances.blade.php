@section('title', 'Finances — Hive Contractors')
<x-guest-layout>
    <x-marketing.nav active="finances" />

    <x-marketing.feature-hero
        icon="credit-card"
        eyebrow="Finances"
        title="Your project money, sorted automatically"
        body="Receipts, bank transactions, payments, checks, and payroll all flow into one place and reconcile themselves. Clean books, accurate job costs, and audit-ready records—without the late-night data entry."
    />

    {{-- DEEP FEATURE ROWS --}}
    <div class="py-20 bg-white dark:bg-zinc-950 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8 space-y-24">

            {{-- Receipts --}}
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">Receipts that read themselves</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        Forward a receipt email or snap a photo from the job site. Hive extracts the vendor, totals, and
                        individual line items, then files it against the right project and transaction.
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            'Email-in and photo upload—no manual typing',
                            'Line-item itemization with automatic categorization',
                            'Auto-matched to the bank transaction it belongs to',
                            'Duplicate detection so nothing gets double-counted',
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-2 rounded-xl bg-gray-900/5 dark:bg-white/5 ring-1 ring-inset ring-gray-900/10 dark:ring-white/10 lg:p-4">
                    <img src="{{ asset('hive_expenses_1.png') }}" alt="Hive receipts and expenses" class="rounded-md shadow-2xl ring-1 ring-gray-900/10">
                </div>
            </div>

            {{-- Books / accounting --}}
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div class="lg:order-last">
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">Books that stay reconciled</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        Connect your bank and your transactions flow in live. Hive matches them to expenses and vendors,
                        keeps distributions straight, and gives you balance sheets and P&amp;L on demand.
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            'Live bank feeds with automatic vendor matching',
                            'Balance sheet and profit & loss in a click',
                            'Distributions and owner draws tracked cleanly',
                            'QuickBooks-ready so tax time is painless',
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-2 rounded-xl bg-gray-900/5 dark:bg-white/5 ring-1 ring-inset ring-gray-900/10 dark:ring-white/10 lg:p-4">
                    <img src="{{ asset('hive_expenses_2.png') }}" alt="Hive books and accounting" class="rounded-md shadow-2xl ring-1 ring-gray-900/10">
                </div>
            </div>
        </div>
    </div>

    <x-marketing.feature-links
        area="finances"
        heading="Everything in the money toolkit"
        subheading="From the first receipt to insurance compliance and final payment—every dollar on the job is covered."
    />

    <x-marketing.cta
        heading="Stop chasing receipts. Start closing your books in minutes."
        subheading="Let Hive automate the bookkeeping so you can spend your evenings off the clock."
    />

    <x-marketing.footer />
</x-guest-layout>
