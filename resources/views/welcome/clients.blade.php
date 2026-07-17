@section('title', 'Leads & Clients — Hive Contractors')
<x-guest-layout>
    <x-marketing.nav active="clients" />

    <x-marketing.feature-hero
        icon="users"
        eyebrow="{{ __('Leads & Clients') }}"
        title="{{ __('From first call to happy homeowner') }}"
        body="{{ __('Catch every lead, follow up on time, and turn the ones that close into clients without re-typing a thing. Then give homeowners a live window into their project so they stop wondering what is happening next.') }}"
    />

    {{-- DEEP FEATURE ROWS --}}
    <div class="py-20 bg-white dark:bg-zinc-950 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8 space-y-24">

            {{-- Leads --}}
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">{{ __('Never let a lead go cold') }}</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        {{ __('Every inquiry lands in one pipeline you actually work. Hive flags duplicates, keeps the conversation history, and converts a won lead into a client and project in a single click.') }}
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            __('Capture and track leads in one pipeline'),
                            __('Duplicate detection against existing clients'),
                            __('Lead-to-client conversion with no re-typing'),
                            __('Full contact history so follow-ups land right'),
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-6 rounded-2xl bg-gray-50 dark:bg-zinc-900 ring-1 ring-gray-200 dark:ring-zinc-800">
                    <p class="text-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">{{ __('Lead pipeline') }}</p>
                    <div class="mt-4 space-y-3">
                        @php
                            $leads = [
                                [__('Kitchen remodel · Carri T.'), __('New'), 'bg-indigo-100 text-indigo-700 dark:bg-indigo-400/10 dark:text-indigo-300'],
                                [__('Bath addition · Mike R.'), __('Estimating'), 'bg-amber-100 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300'],
                                [__('Deck rebuild · Dana P.'), __('Won'), 'bg-emerald-100 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300'],
                            ];
                        @endphp
                        @foreach ($leads as $lead)
                            <div class="flex items-center justify-between p-3 rounded-xl bg-white dark:bg-zinc-950 ring-1 ring-gray-200 dark:ring-zinc-800">
                                <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $lead[0] }}</span>
                                <span class="px-2 py-0.5 text-xs font-semibold rounded-full {{ $lead[2] }}">{{ $lead[1] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Client portal --}}
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div class="lg:order-last">
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">{{ __('Give homeowners a window in') }}</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        {{ __('Each client gets a real-time portal and a live schedule link by text. They can see what is done, what is next, and when you are coming—so the "any update?" calls stop on their own.') }}
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            __('Real-time homeowner portal for every project'),
                            __('Live "what is next" schedule links by text'),
                            __('Client directory with full job history'),
                            __('Contacts synced from your email automatically'),
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-8 rounded-2xl bg-indigo-600 shadow-xl">
                    <p class="text-xs font-semibold tracking-wide text-indigo-100 uppercase">{{ __('Your project · 123 Maple St') }}</p>
                    <div class="mt-4 space-y-3">
                        <div class="flex items-center gap-3 p-3 rounded-xl bg-white/95 text-gray-900">
                            <flux:icon name="check-circle" class="w-5 h-5 text-emerald-600" />
                            <span class="text-sm font-medium line-through opacity-70">{{ __('Demo complete') }}</span>
                        </div>
                        <div class="flex items-center gap-3 p-3 rounded-xl bg-white/95 text-gray-900">
                            <flux:icon name="wrench-screwdriver" class="w-5 h-5 text-indigo-600" />
                            <span class="text-sm font-medium">{{ __('In progress · Rough plumbing') }}</span>
                        </div>
                        <div class="flex items-center gap-3 p-3 rounded-xl bg-indigo-500/50 text-white">
                            <flux:icon name="calendar-date-range" class="w-5 h-5" />
                            <span class="text-sm font-medium">{{ __('Up next · Inspection Thu 7/2') }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <x-marketing.feature-links area="clients" heading="{{ __('Relationships, organized') }}" />

    <x-marketing.cta
        heading="{{ __('Turn more inquiries into signed jobs.') }}"
        subheading="{{ __('Work every lead and keep every client in the loop—without the busywork.') }}"
    />

    <x-marketing.footer />
</x-guest-layout>
