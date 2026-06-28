@section('title', 'Live Project Status — Hive Contractors')
<x-guest-layout>
    <x-marketing.nav active="homeowners" />

    <x-marketing.feature-hero
        icon="eye"
        eyebrow="Your homeowner portal"
        title="See exactly where your project stands—right now"
        body="No more guessing or waiting for a callback. Open Hive and instantly see what is finished, what the crew is doing today, and what is coming next—updated live as your contractor works."
    />

    {{-- DEEP ROWS --}}
    <div class="py-20 bg-white dark:bg-zinc-950 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8 space-y-24">

            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">A clear picture, updated live</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        Your project is laid out as simple stages—done, in progress, and up next. As your contractor
                        finishes work, the board updates on its own, so what you see is always current.
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            'Completed, in-progress, and upcoming work at a glance',
                            'Updates the moment your contractor marks progress',
                            'No app refresh or phone call needed',
                            'Check it any time, day or night',
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-8 rounded-2xl bg-indigo-600 shadow-xl">
                    <p class="text-xs font-semibold tracking-wide text-indigo-100 uppercase">Your project · 123 Maple St</p>
                    <div class="mt-4 space-y-3">
                        <div class="flex items-center gap-3 p-3 rounded-xl bg-white/95 text-gray-900">
                            <flux:icon name="check-circle" class="w-5 h-5 text-emerald-600" />
                            <span class="text-sm font-medium line-through opacity-70">Demo complete</span>
                        </div>
                        <div class="flex items-center gap-3 p-3 rounded-xl bg-white/95 text-gray-900">
                            <flux:icon name="check-circle" class="w-5 h-5 text-emerald-600" />
                            <span class="text-sm font-medium line-through opacity-70">Rough plumbing complete</span>
                        </div>
                        <div class="flex items-center gap-3 p-3 rounded-xl bg-white text-gray-900 ring-2 ring-white">
                            <flux:icon name="wrench-screwdriver" class="w-5 h-5 text-indigo-600" />
                            <span class="text-sm font-semibold">In progress · Electrical rough-in</span>
                        </div>
                        <div class="flex items-center gap-3 p-3 rounded-xl bg-indigo-500/50 text-white">
                            <flux:icon name="calendar-date-range" class="w-5 h-5" />
                            <span class="text-sm font-medium">Up next · Inspection, Thu 7/2</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div class="lg:order-last">
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">Understand every stage</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        See how far along the whole project is and which phase you are in. A simple progress view tells
                        you the story at a glance—no construction jargon required.
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            'Overall progress for the entire project',
                            'Phase-by-phase breakdown in plain language',
                            'A history of what was completed and when',
                            'Always tied to your specific job',
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-6 rounded-2xl bg-gray-50 dark:bg-zinc-900 ring-1 ring-gray-200 dark:ring-zinc-800">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">Kitchen remodel</p>
                        <span class="text-sm font-semibold text-indigo-600 dark:text-indigo-400">62% complete</span>
                    </div>
                    <div class="mt-3 h-3 rounded-full bg-gray-200 dark:bg-zinc-800">
                        <div class="h-3 rounded-full w-[62%] bg-indigo-600"></div>
                    </div>
                    <div class="mt-5 space-y-2 text-sm">
                        <div class="flex justify-between text-gray-700 dark:text-gray-300"><span>Demolition</span><span class="text-emerald-600">Done</span></div>
                        <div class="flex justify-between text-gray-700 dark:text-gray-300"><span>Rough-in</span><span class="text-indigo-600 dark:text-indigo-400">In progress</span></div>
                        <div class="flex justify-between text-gray-500"><span>Finishes</span><span>Upcoming</span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- DETAIL GRID --}}
    <div class="py-20 bg-gray-100 dark:bg-zinc-900 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8">
            <div class="max-w-2xl mx-auto text-center">
                <h2 class="text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl">What live status shows you</h2>
            </div>
            <dl class="grid max-w-xl grid-cols-1 mx-auto mt-16 gap-x-8 gap-y-12 lg:max-w-none lg:grid-cols-3">
                @php
                    $cards = [
                        ['icon' => 'bolt', 'title' => 'Real-time updates', 'body' => 'Progress changes the instant your contractor logs it.'],
                        ['icon' => 'list-bullet', 'title' => 'Phase breakdown', 'body' => 'Each stage of the job spelled out in plain language.'],
                        ['icon' => 'arrow-right-circle', 'title' => 'What is next', 'body' => 'Always know the very next step on your project.'],
                        ['icon' => 'chart-bar', 'title' => 'Percent complete', 'body' => 'A simple overall progress bar for the whole job.'],
                        ['icon' => 'users', 'title' => 'On-site activity', 'body' => 'See what the crew is working on right now.'],
                        ['icon' => 'clock', 'title' => 'History log', 'body' => 'Look back at what was completed and when.'],
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

    <x-marketing.portal-links current="status" heading="Explore the rest of your portal" />

    <x-marketing.homeowner-cta />

    <x-marketing.footer />
</x-guest-layout>
