@section('title', 'Estimates & Documents — Hive Contractors')
<x-guest-layout>
    <x-marketing.nav active="estimates" />

    <x-marketing.feature-hero
        icon="document-text"
        eyebrow="{{ __('Estimates & Documents') }}"
        title="{{ __('Win the bid and get signed off—fast') }}"
        body="{{ __('Build professional estimates with AI, send a polished PDF, and collect a client signature right from the link. When they say yes, the estimate becomes an active job—no re-typing, no chasing paperwork.') }}"
    />

    {{-- DEEP FEATURE ROWS --}}
    <div class="py-20 bg-white dark:bg-zinc-950 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8 space-y-24">

            {{-- Estimates --}}
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">{{ __('Estimates that practically write themselves') }}</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        {{ __('Describe the work and let Hive draft a clean, itemized estimate. Adjust line items, add your markup, and send a branded PDF that makes your small shop look like the big firm.') }}
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            __('AI-assisted line items and pricing'),
                            __('Branded, professional PDF estimates and invoices'),
                            __('Reusable templates for the work you bid most'),
                            __('Change orders that keep scope and price honest'),
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
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Estimate #1042') }}</p>
                            <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-amber-100 text-amber-700 dark:bg-amber-400/10 dark:text-amber-400">{{ __('Sent') }}</span>
                        </div>
                        <div class="mt-4 space-y-2 text-sm">
                            <div class="flex justify-between text-gray-700 dark:text-gray-300"><span>{{ __('Demo & haul-away') }}</span><span>$2,400</span></div>
                            <div class="flex justify-between text-gray-700 dark:text-gray-300"><span>{{ __('Rough plumbing') }}</span><span>$5,150</span></div>
                            <div class="flex justify-between text-gray-700 dark:text-gray-300"><span>{{ __('Tile & finishes') }}</span><span>$6,800</span></div>
                            <div class="flex justify-between pt-2 mt-2 font-semibold text-gray-900 border-t border-gray-200 dark:border-zinc-800 dark:text-white"><span>{{ __('Total') }}</span><span>$14,350</span></div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- E-signatures --}}
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div class="lg:order-last">
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">{{ __('Signatures without the back-and-forth') }}</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        {{ __('Clients open a secure link, review the estimate, and sign on their phone—no app, no printing. The moment they approve, you can turn it into a scheduled job and start the work.') }}
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            __('Client e-signature from any device'),
                            __('Secure, account-free links for signers'),
                            __('One-click conversion from approved estimate to job'),
                            __('Bids and proposals tracked from sent to signed'),
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-8 rounded-2xl bg-indigo-600 shadow-xl">
                    <p class="text-xs font-semibold tracking-wide text-indigo-100 uppercase">{{ __('Client approval') }}</p>
                    <div class="p-4 mt-4 rounded-xl bg-white/95 text-gray-900">
                        <p class="text-sm">{!! __('I approve estimate <span class="font-semibold">#1042</span> for <span class="font-semibold">$14,350</span>.') !!}</p>
                        <p class="mt-3 text-2xl text-indigo-700" style="font-family: cursive;">Carri Thompson</p>
                        <p class="text-xs text-gray-500">{{ __('Signed Jun 27, 2026 · 2:14 PM') }}</p>
                    </div>
                    <div class="flex items-center gap-2 mt-4 text-sm font-medium text-white">
                        <flux:icon name="check-badge" class="w-5 h-5" />
                        <span>{{ __('Converted to job & scheduled') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <x-marketing.feature-links area="estimates" heading="{{ __('Every document the job needs') }}" />

    <x-marketing.cta
        heading="{{ __('Send the estimate. Get the signature. Start the job.') }}"
        subheading="{{ __('Turn winning bids into scheduled work without the paperwork dragging behind.') }}"
    />

    <x-marketing.footer />
</x-guest-layout>
