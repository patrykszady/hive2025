@section('title', __('Schedule & Reminders — Hive Contractors'))
<x-guest-layout>
    <x-marketing.nav active="homeowners" />

    <x-marketing.feature-hero
        icon="calendar-date-range"
        eyebrow="{{ __('Your homeowner portal') }}"
        title="{{ __('Know when the crew is coming—every time') }}"
        body="{{ __('See your upcoming visits and milestones, get a live schedule link on your phone, and receive a text the moment anything moves. No more wondering whether today is a work day.') }}"
    />

    {{-- DEEP ROWS --}}
    <div class="py-20 bg-white dark:bg-zinc-950 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8 space-y-24">

            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">{{ __('Your upcoming visits at a glance') }}</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        {{ __('A clean, dated list shows what is happening and when—from the next crew visit to inspections and major milestones. Tap the schedule link your contractor texts you and it is always current.') }}
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            __('A live schedule link sent right to your phone'),
                            __('Upcoming visits, inspections, and milestones'),
                            __('Always up to date—no stale printouts'),
                            __('Tied to your specific project'),
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-6 rounded-2xl bg-gray-50 dark:bg-zinc-900 ring-1 ring-gray-200 dark:ring-zinc-800">
                    <p class="text-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">{{ __('What is coming up') }}</p>
                    <div class="mt-4 space-y-3">
                        @php
                            $visits = [
                                ['calendar-date-range', __('Tue 6/30 · 8:00 AM'), __('Electrical rough-in')],
                                ['clipboard-document-check', __('Thu 7/2 · 9:00 AM'), __('City inspection')],
                                ['swatch', __('Mon 7/6 · 8:00 AM'), __('Tile & finishes begin')],
                            ];
                        @endphp
                        @foreach ($visits as $visit)
                            <div class="flex items-center gap-3 p-3 rounded-xl bg-white dark:bg-zinc-950 ring-1 ring-gray-200 dark:ring-zinc-800">
                                <flux:icon name="{{ $visit[0] }}" class="w-5 h-5 text-indigo-600 dark:text-indigo-400" />
                                <div>
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $visit[1] }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $visit[2] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div class="lg:order-last">
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">{{ __('Never get caught off guard') }}</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        {{ __('Weather, a late delivery, or an added task can shift the plan. When it does, Hive texts you the update and reminds you before scheduled work—so you can plan your day with confidence.') }}
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            __('Automatic texts when dates change'),
                            __('Reminders before the crew arrives'),
                            __('Clear reason and new date when things move'),
                            __('Peace of mind without chasing your contractor'),
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-8 rounded-2xl bg-indigo-600 shadow-xl">
                    <p class="text-xs font-semibold tracking-wide text-indigo-100 uppercase">{{ __('Schedule update') }}</p>
                    <div class="p-4 mt-4 rounded-xl bg-white/95 text-gray-900">
                        <p class="text-sm leading-relaxed">{!! __('Heads up—tile delivery slipped a day. We&rsquo;ll now start finishes <span class="font-semibold">Tue 7/7</span> instead of Mon. Everything else is on track 👍') !!}</p>
                    </div>
                    <div class="flex items-center gap-2 mt-4 text-sm font-medium text-white">
                        <flux:icon name="bell-alert" class="w-5 h-5" />
                        <span>{{ __('Sent to you by text & in your portal') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- DETAIL GRID --}}
    <div class="py-20 bg-gray-100 dark:bg-zinc-900 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8">
            <div class="max-w-2xl mx-auto text-center">
                <h2 class="text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl">{{ __('Your schedule, working for you') }}</h2>
            </div>
            <dl class="grid max-w-xl grid-cols-1 mx-auto mt-16 gap-x-8 gap-y-12 lg:max-w-none lg:grid-cols-3">
                @php
                    $cards = [
                        ['icon' => 'link', 'title' => __('Live schedule link'), 'body' => __('One link, texted to you, that is always current.')],
                        ['icon' => 'calendar-date-range', 'title' => __('Upcoming visits'), 'body' => __('See exactly what is planned and on which day.')],
                        ['icon' => 'bell-alert', 'title' => __('Change alerts'), 'body' => __('Get a text the moment a date shifts.')],
                        ['icon' => 'clock', 'title' => __('Reminders'), 'body' => __('A nudge before scheduled work so you can plan around it.')],
                        ['icon' => 'flag', 'title' => __('Milestones'), 'body' => __('Track the big moments from start to finish.')],
                        ['icon' => 'device-phone-mobile', 'title' => __('On any device'), 'body' => __('Open it right in your phone—no app required.')],
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

    <x-marketing.portal-links current="schedule" heading="Explore the rest of your portal" />

    <x-marketing.homeowner-cta />

    <x-marketing.footer />
</x-guest-layout>
