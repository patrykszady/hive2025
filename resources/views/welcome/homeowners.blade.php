@section('title', __('For Homeowners — Hive Contractors'))
<x-guest-layout>
    <x-marketing.nav active="homeowners" />

    <x-marketing.feature-hero
        icon="home-modern"
        :eyebrow="__('For Homeowners')"
        :title="__('Always know exactly where your project stands')"
        :body="__('When your contractor works in Hive, you get a private window into your whole project—what is done, what is happening today, and what is coming next. No more wondering, no more &ldquo;any update?&rdquo; texts that go unanswered.')"
    />

    {{-- WHAT YOU GET --}}
    <div class="py-20 bg-white dark:bg-zinc-950 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8 space-y-24">

            {{-- Live status --}}
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">{{ __('See your whole project at a glance') }}</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        {{ __('Log in and instantly see the big picture: completed work, what the crew is doing right now, and what is up next. Everything updates in real time as your contractor moves the job forward.') }}
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            __('Real-time status for every stage of the work'),
                            __('A clear &ldquo;what is next&rdquo; so there are no surprises'),
                            __('Progress you can check anytime, day or night'),
                            __('One place for the whole project—not scattered texts'),
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{!! $item !!}</span>
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

            {{-- Schedule --}}
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div class="lg:order-last">
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">Your schedule, always up to date</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        Know when the crew is coming and what they will be working on. When the plan changes—weather, a
                        late delivery, an added task—you get a text, so you are never caught off guard.
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            'Upcoming visits and milestones you can see anytime',
                            'A live schedule link sent right to your phone',
                            'Automatic texts when dates move',
                            'Reminders before scheduled work',
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-6 rounded-2xl bg-gray-50 dark:bg-zinc-900 ring-1 ring-gray-200 dark:ring-zinc-800">
                    <p class="text-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">What is coming up</p>
                    <div class="mt-4 space-y-3">
                        @php
                            $visits = [
                                ['calendar-date-range', 'Tue 6/30 · 8:00 AM', 'Electrical rough-in'],
                                ['clipboard-document-check', 'Thu 7/2 · 9:00 AM', 'City inspection'],
                                ['swatch', 'Mon 7/6 · 8:00 AM', 'Tile & finishes begin'],
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

            {{-- Messaging --}}
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">Talk to your contractor in one place</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        Ask questions, share photos, and get answers without digging through old text threads. Every
                        message about your project lives together, so nothing important gets lost.
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            'One clean conversation tied to your project',
                            'Send and receive photos from the job',
                            'A history you can scroll back through anytime',
                            'Replies in your language if you prefer',
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="rounded-2xl bg-indigo-600 p-8 sm:p-10 shadow-xl">
                    <div class="space-y-4">
                        <div class="max-w-[85%] rounded-2xl bg-white/95 px-4 py-3 text-gray-900">
                            <p class="text-xs font-semibold text-gray-500">Your contractor</p>
                            <p class="mt-1 text-sm leading-relaxed">Electrical rough-in is done—here are a couple photos before drywall 👍</p>
                        </div>
                        <div class="ml-auto max-w-[85%] rounded-2xl bg-indigo-500/60 px-4 py-3 text-white">
                            <p class="text-xs font-semibold text-indigo-100">You</p>
                            <p class="mt-1 text-sm leading-relaxed">Looks great! Are we still on for inspection Thursday?</p>
                        </div>
                        <div class="max-w-[85%] rounded-2xl bg-white/95 px-4 py-3 text-gray-900">
                            <p class="text-xs font-semibold text-gray-500">Your contractor</p>
                            <p class="mt-1 text-sm leading-relaxed">Yes—9 AM Thursday. I&rsquo;ll text you when the inspector confirms ✅</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Documents & payments --}}
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div class="lg:order-last">
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">Review, approve, and pay—from your phone</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        Estimates, change orders, and invoices arrive as clean documents you can read and sign on any
                        device. See exactly what you approved, what you have paid, and where your allowances stand.
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            'Review and e-sign estimates and change orders',
                            'See invoices and a running total of what is paid',
                            'Track selections and allowances in one place',
                            'No printing, no app to download',
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
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">Change order · Tile upgrade</p>
                            <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-amber-100 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300">Needs your OK</span>
                        </div>
                        <div class="mt-4 space-y-2 text-sm">
                            <div class="flex justify-between text-gray-700 dark:text-gray-300"><span>Upgraded porcelain tile</span><span>+$1,400</span></div>
                            <div class="flex justify-between text-gray-700 dark:text-gray-300"><span>Allowance remaining</span><span>$600</span></div>
                            <div class="flex justify-between pt-2 mt-2 font-semibold text-gray-900 border-t border-gray-200 dark:border-zinc-800 dark:text-white"><span>Your cost</span><span>$800</span></div>
                        </div>
                        <button type="button" class="w-full px-3 py-2 mt-4 text-sm font-semibold text-white bg-indigo-600 rounded-md">Review &amp; sign</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- PORTAL FEATURE GRID --}}
    <x-marketing.portal-links
        heading="Everything in your homeowner portal"
        subheading="One simple, private place for your entire project—tap any area to see how it works."
    />

    {{-- HOW TO GET STARTED --}}
    <div class="py-20 bg-white dark:bg-zinc-950 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8">
            <div class="max-w-2xl mx-auto text-center">
                <h2 class="text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl">Getting in takes about 30 seconds</h2>
                <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                    There is nothing to buy and nothing to install. If your contractor uses Hive, you are a tap away.
                </p>
            </div>
            <div class="grid max-w-2xl grid-cols-1 mx-auto mt-16 gap-8 lg:max-w-none lg:grid-cols-3">
                @php
                    $steps = [
                        ['1', 'envelope', 'Your contractor invites you', 'You get a text or email with a secure link to your project—no account setup required.'],
                        ['2', 'finger-print', 'Open your private link', 'Tap to sign in safely. No app to download and no password to remember.'],
                        ['3', 'home-modern', 'See everything about your project', 'Status, schedule, messages, documents, and payments—all in one simple place.'],
                    ];
                @endphp
                @foreach ($steps as $step)
                    <div class="flex flex-col p-8 bg-gray-50 dark:bg-zinc-900 rounded-2xl ring-1 ring-gray-200 dark:ring-zinc-800">
                        <div class="flex items-center gap-3">
                            <span class="flex items-center justify-center w-9 h-9 text-base font-bold text-white bg-indigo-600 rounded-full">{{ $step[0] }}</span>
                            <flux:icon name="{{ $step[1] }}" class="w-6 h-6 text-indigo-600 dark:text-indigo-400" />
                        </div>
                        <h3 class="mt-5 text-lg font-semibold text-gray-900 dark:text-white">{{ $step[2] }}</h3>
                        <p class="mt-2 text-base leading-7 text-gray-600 dark:text-gray-400">{{ $step[3] }}</p>
                    </div>
                @endforeach
            </div>

            {{-- Reassurance strip --}}
            <div class="grid max-w-3xl grid-cols-1 mx-auto mt-16 gap-6 sm:grid-cols-3">
                @php
                    $assurances = [
                        ['gift', 'Free for homeowners', 'You never pay to view your project.'],
                        ['lock-closed', 'Private & secure', 'Only you and your contractor can see it.'],
                        ['device-phone-mobile', 'No app to download', 'Works right in your phone&rsquo;s browser.'],
                    ];
                @endphp
                @foreach ($assurances as $assurance)
                    <div class="flex flex-col items-center text-center">
                        <div class="flex items-center justify-center w-11 h-11 bg-indigo-600 rounded-xl">
                            <flux:icon name="{{ $assurance[0] }}" class="w-6 h-6 text-white" />
                        </div>
                        <p class="mt-3 text-sm font-semibold text-gray-900 dark:text-white">{{ $assurance[1] }}</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{!! $assurance[2] !!}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- CTA (homeowner-specific) --}}
    <x-marketing.homeowner-cta />

    <x-marketing.footer />
</x-guest-layout>
