@section('title', __('Estimates & E-Sign — Hive Contractors'))
<x-guest-layout>
    <x-marketing.nav active="homeowners" />

    <x-marketing.feature-hero
        icon="pencil-square"
        eyebrow="{{ __('Your homeowner portal') }}"
        title="{{ __('Review and sign—right from your phone') }}"
        body="{{ __('Estimates and change orders come to you as clean, easy-to-read documents. Read every line, ask questions, and approve with a tap. No printing, scanning, or driving anywhere.') }}"
    />

    {{-- DEEP ROWS --}}
    <div class="py-20 bg-white dark:bg-zinc-950 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8 space-y-24">

            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">{{ __('Documents you can actually read') }}</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        {{ __('No more cryptic, handwritten quotes. Your estimate is itemized in plain language so you know exactly what you are paying for before you agree to anything.') }}
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            'Clear, itemized line items',
                            'Plain-language descriptions',
                            'See exactly what is included',
                            'Ask questions before you approve',
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
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">Estimate #1042</p>
                        <span class="px-2 py-1 text-xs font-semibold text-amber-700 rounded bg-amber-100">Awaiting approval</span>
                    </div>
                    <div class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between text-gray-700 dark:text-gray-300"><span>Cabinetry & install</span><span>$8,400</span></div>
                        <div class="flex justify-between text-gray-700 dark:text-gray-300"><span>Countertops</span><span>$3,950</span></div>
                        <div class="flex justify-between text-gray-700 dark:text-gray-300"><span>Tile & backsplash</span><span>$2,100</span></div>
                        <div class="flex justify-between pt-2 mt-2 font-semibold text-gray-900 border-t border-gray-200 dark:text-white dark:border-zinc-800"><span>Total</span><span>$14,450</span></div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div class="lg:order-last">
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">Sign in seconds, no printing</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        Approve estimates and change orders with a tap. When the scope changes mid-project, you will see a
                        clear change order with the new cost—so there are never any surprises on the final bill.
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            'Approve with a single tap',
                            'Clear change orders for any new work',
                            'See the cost impact before you agree',
                            'A signed record kept for both of you',
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-8 rounded-2xl bg-indigo-600 shadow-xl">
                    <p class="text-xs font-semibold tracking-wide text-indigo-100 uppercase">Change order · Add can lighting</p>
                    <div class="p-4 mt-4 rounded-xl bg-white/95 text-gray-900">
                        <div class="flex justify-between text-sm"><span>6 recessed lights, installed</span><span class="font-semibold">+$1,250</span></div>
                        <p class="mt-2 text-xs text-gray-500">Adds approx. 1 day to the schedule.</p>
                    </div>
                    <button type="button" class="flex items-center justify-center w-full gap-2 py-3 mt-4 text-sm font-semibold text-indigo-700 bg-white rounded-xl">
                        <flux:icon name="pencil-square" class="w-5 h-5" />
                        Tap to approve &amp; sign
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- DETAIL GRID --}}
    <div class="py-20 bg-gray-100 dark:bg-zinc-900 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8">
            <div class="max-w-2xl mx-auto text-center">
                <h2 class="text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl">Paperwork made painless</h2>
            </div>
            <dl class="grid max-w-xl grid-cols-1 mx-auto mt-16 gap-x-8 gap-y-12 lg:max-w-none lg:grid-cols-3">
                @php
                    $cards = [
                        ['icon' => 'document-text', 'title' => 'Clear estimates', 'body' => 'Itemized quotes you can understand at a glance.'],
                        ['icon' => 'pencil-square', 'title' => 'E-signatures', 'body' => 'Approve and sign with a tap—no printer needed.'],
                        ['icon' => 'arrow-path', 'title' => 'Change orders', 'body' => 'See new work and its cost before you say yes.'],
                        ['icon' => 'list-bullet', 'title' => 'Itemized detail', 'body' => 'Every line spelled out so nothing is hidden.'],
                        ['icon' => 'clock', 'title' => 'Version history', 'body' => 'Track what changed and when it was approved.'],
                        ['icon' => 'lock-closed', 'title' => 'Securely stored', 'body' => 'Your signed documents are saved and safe.'],
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

    <x-marketing.portal-links current="documents" heading="Explore the rest of your portal" />

    <x-marketing.homeowner-cta />

    <x-marketing.footer />
</x-guest-layout>
