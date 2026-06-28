@section('title', 'Photos & Progress — Hive Contractors')
<x-guest-layout>
    <x-marketing.nav active="homeowners" />

    <x-marketing.feature-hero
        icon="photo"
        eyebrow="Your homeowner portal"
        title="Watch your project come together"
        body="Even when you cannot be there, you can see it. Job-site photos give you a front-row seat to the work—organized, time-stamped, and yours to keep forever."
    />

    {{-- DEEP ROWS --}}
    <div class="py-20 bg-white dark:bg-zinc-950 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8 space-y-24">

            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">Progress photos from the job site</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        Your contractor captures the work as it happens. You see real progress between visits—no need to
                        drive over or ask for an update.
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            'See real progress between visits',
                            'Photos time-stamped and in order',
                            'Catch details you would otherwise miss',
                            'Feel confident the work is moving',
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-6 rounded-2xl bg-gray-50 dark:bg-zinc-900 ring-1 ring-gray-200 dark:ring-zinc-800">
                    <div class="grid grid-cols-2 gap-3">
                        @foreach ([
                            ['Framing', 'from-amber-200 to-amber-400'],
                            ['Drywall', 'from-sky-200 to-sky-400'],
                            ['Cabinets', 'from-emerald-200 to-emerald-400'],
                            ['Countertops', 'from-violet-200 to-violet-400'],
                        ] as $photo)
                            <div class="overflow-hidden rounded-xl ring-1 ring-gray-200 dark:ring-zinc-800">
                                <div class="flex items-end h-28 p-3 bg-gradient-to-br {{ $photo[1] }}">
                                    <span class="px-2 py-1 text-xs font-semibold text-gray-900 rounded bg-white/80">{{ $photo[0] }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div class="lg:order-last">
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">A visual record you keep</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        Photos stay organized by phase so you can look back at any stage—even what is behind the walls.
                        It is a documented history of your home you will be glad to have long after the job is done.
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            'Organized by phase and date',
                            'A record of what is behind the walls',
                            'Before-and-after for every space',
                            'Always available when you need it',
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-8 rounded-2xl bg-indigo-600 shadow-xl">
                    <p class="text-xs font-semibold tracking-wide text-indigo-100 uppercase">Why it matters</p>
                    <div class="mt-4 space-y-3">
                        @foreach ([
                            'Proof of quality work at every stage',
                            'Know where pipes and wiring run',
                            'Memories of your home transformation',
                        ] as $point)
                            <div class="flex items-center gap-3 p-3 rounded-xl bg-white/95 text-gray-900">
                                <flux:icon name="check-circle" class="w-5 h-5 text-indigo-600" />
                                <span class="text-sm font-medium">{{ $point }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- DETAIL GRID --}}
    <div class="py-20 bg-gray-100 dark:bg-zinc-900 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8">
            <div class="max-w-2xl mx-auto text-center">
                <h2 class="text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl">Your project, documented</h2>
            </div>
            <dl class="grid max-w-xl grid-cols-1 mx-auto mt-16 gap-x-8 gap-y-12 lg:max-w-none lg:grid-cols-3">
                @php
                    $cards = [
                        ['icon' => 'camera', 'title' => 'Job-site photos', 'body' => 'See the work as it happens, straight from the crew.'],
                        ['icon' => 'rectangle-stack', 'title' => 'Organized by phase', 'body' => 'Photos grouped by stage so they are easy to follow.'],
                        ['icon' => 'arrows-right-left', 'title' => 'Before & after', 'body' => 'Compare how each space started and finished.'],
                        ['icon' => 'clock', 'title' => 'Time-stamped', 'body' => 'Every photo dated so the timeline is clear.'],
                        ['icon' => 'cloud-arrow-down', 'title' => 'Always available', 'body' => 'Revisit your photos any time, even after the job.'],
                        ['icon' => 'shield-check', 'title' => 'Private to you', 'body' => 'Only you and your contractor can see them.'],
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

    <x-marketing.portal-links current="photos" heading="Explore the rest of your portal" />

    <x-marketing.homeowner-cta />

    <x-marketing.footer />
</x-guest-layout>
