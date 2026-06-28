@section('title', 'Planning — Hive Contractors')
<x-guest-layout>
    <x-marketing.nav active="planning" />

    <x-marketing.feature-hero
        icon="calendar"
        eyebrow="Planning"
        title="Plan the job once—keep everyone in sync"
        body="Schedule every task on a drag-and-drop Gantt timeline or Kanban board, set dependencies, and share exactly what is coming up with clients and crews. When the plan changes, everyone hears about it automatically."
    />

    {{-- DEEP FEATURE ROWS --}}
    <div class="py-20 bg-white dark:bg-zinc-950 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8 space-y-24">

            {{-- Gantt --}}
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">A timeline you can drag</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        See every active job on one Gantt timeline. Drag a task to reschedule, link dependencies, and
                        instantly see what slips when a delivery is late or weather rolls in.
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            'Drag-and-drop rescheduling across projects',
                            'Task dependencies and critical-path visibility',
                            'Milestones, meetings, and reminders in one view',
                            'Live timelines your whole team shares',
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-6 rounded-2xl bg-gray-50 dark:bg-zinc-900 ring-1 ring-gray-200 dark:ring-zinc-800">
                    <div class="space-y-3">
                        @php
                            $bars = [
                                ['Demo', 'w-1/4', 'ml-0', 'bg-indigo-600'],
                                ['Rough plumbing', 'w-1/3', 'ml-[20%]', 'bg-indigo-500'],
                                ['Rough electrical', 'w-1/3', 'ml-[28%]', 'bg-indigo-500'],
                                ['Inspection', 'w-1/6', 'ml-[55%]', 'bg-emerald-500'],
                                ['Drywall', 'w-1/4', 'ml-[62%]', 'bg-indigo-400'],
                            ];
                        @endphp
                        @foreach ($bars as $bar)
                            <div>
                                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ $bar[0] }}</p>
                                <div class="h-3 rounded-full bg-gray-200 dark:bg-zinc-800">
                                    <div class="h-3 rounded-full {{ $bar[1] }} {{ $bar[2] }} {{ $bar[3] }}"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Sharing --}}
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div class="lg:order-last">
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">Share the plan, skip the phone tag</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        Send homeowners and crews a live "what is next" schedule by text. They always know the next step,
                        and you stop fielding "when are you coming?" calls.
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            'Live client schedule links sent by text',
                            'Crew assignments and availability in one place',
                            'Automatic reminders before scheduled work',
                            'Real-time updates when the plan moves',
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-8 rounded-2xl bg-indigo-600 shadow-xl">
                    <p class="text-xs font-semibold text-indigo-100 uppercase tracking-wide">Client schedule</p>
                    <div class="mt-4 space-y-3">
                        <div class="flex items-center gap-3 p-3 rounded-xl bg-white/95 text-gray-900">
                            <flux:icon name="calendar-date-range" class="w-5 h-5 text-indigo-600" />
                            <span class="text-sm font-medium">Tue 6/30 · Demo @ 8AM</span>
                        </div>
                        <div class="flex items-center gap-3 p-3 rounded-xl bg-indigo-500/50 text-white">
                            <flux:icon name="wrench-screwdriver" class="w-5 h-5" />
                            <span class="text-sm font-medium">Pending · Rough plumbing</span>
                        </div>
                        <div class="flex items-center gap-3 p-3 rounded-xl bg-indigo-500/50 text-white">
                            <flux:icon name="bolt" class="w-5 h-5" />
                            <span class="text-sm font-medium">Pending · Rough electrical</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <x-marketing.feature-links area="planning" heading="Plan, assign, and track it all" />

    <x-marketing.cta
        heading="Build the schedule once. Let Hive keep everyone on it."
        subheading="Plan every job on a timeline your crews and clients can actually follow."
    />

    <x-marketing.footer />
</x-guest-layout>
