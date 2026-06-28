@section('title', 'Automation & AI — Hive Contractors')
<x-guest-layout>
    <x-marketing.nav active="automation" />

    <x-marketing.feature-hero
        icon="sparkles"
        eyebrow="Automation & AI"
        title="Let the busywork run itself"
        body="Hive reads your receipts, matches your transactions, summarizes your calls, and turns texts into tasks—quietly, in the background. The office work that used to eat your evenings just happens."
    />

    {{-- DEEP FEATURE ROWS --}}
    <div class="py-20 bg-white dark:bg-zinc-950 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8 space-y-24">

            {{-- Receipt AI --}}
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">AI that reads your receipts</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        Forward an email or snap a photo and Hive pulls the vendor, totals, and every line item—even
                        handwritten notes. It can even pull itemized receipts straight from supplier accounts.
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            'OCR + AI itemization on emailed and photo receipts',
                            'Retailer receipt pull from Home Depot, Menards, Amazon & more',
                            'Handwritten note understanding',
                            'Duplicate detection so nothing double-counts',
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-6 rounded-2xl bg-gray-50 dark:bg-zinc-900 ring-1 ring-gray-200 dark:ring-zinc-800">
                    <div class="p-5 rounded-xl bg-white dark:bg-zinc-950 ring-1 ring-gray-200 dark:ring-zinc-800">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">The Home Depot</p>
                            <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300">Read by AI</span>
                        </div>
                        <div class="mt-4 space-y-2 text-sm">
                            <div class="flex justify-between text-gray-700 dark:text-gray-300"><span>2x4 stud · 24</span><span>$118.80</span></div>
                            <div class="flex justify-between text-gray-700 dark:text-gray-300"><span>Drywall screws</span><span>$14.97</span></div>
                            <div class="flex justify-between text-gray-700 dark:text-gray-300"><span>Joint compound</span><span>$22.40</span></div>
                            <div class="flex justify-between pt-2 mt-2 font-semibold text-gray-900 border-t border-gray-200 dark:border-zinc-800 dark:text-white"><span>Matched to · 123 Maple St</span><span>$156.17</span></div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Matching & summaries --}}
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div class="lg:order-last">
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">Smart matching and instant summaries</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        Hive matches bank transactions to the right vendor and expense, summarizes recorded calls into
                        action items, and turns an incoming text into a scheduled task—reviewed before it saves.
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            'Automatic vendor and transaction matching',
                            'AI call summaries with action items',
                            'Turn a text message into a scheduled task',
                            'Address autocomplete and job-site maps',
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-8 rounded-2xl bg-indigo-600 shadow-xl">
                    <p class="text-xs font-semibold tracking-wide text-indigo-100 uppercase">From a text → a task</p>
                    <div class="p-4 mt-4 rounded-xl bg-white/95 text-gray-900">
                        <p class="text-sm">&ldquo;Can you get the inspection scheduled for Thursday morning?&rdquo;</p>
                    </div>
                    <div class="flex items-center justify-center my-3 text-indigo-100">
                        <flux:icon name="arrow-down" class="w-5 h-5" />
                    </div>
                    <div class="flex items-center gap-3 p-3 rounded-xl bg-white/95 text-gray-900">
                        <flux:icon name="calendar-date-range" class="w-5 h-5 text-indigo-600" />
                        <div>
                            <p class="text-sm font-medium">Task · Schedule inspection</p>
                            <p class="text-xs text-gray-500">Thu 7/2 · 8:00 AM</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <x-marketing.feature-links area="automation" heading="The work that runs in the background" />

    <x-marketing.cta
        heading="Put the busywork on autopilot."
        subheading="Let Hive handle the receipts, matching, and notes so you can stay on the tools."
    />

    <x-marketing.footer />
</x-guest-layout>
