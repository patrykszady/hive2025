@section('title', 'Notifications — Hive Contractors')
<x-guest-layout>
    <x-marketing.nav active="homeowners" />

    <x-marketing.feature-hero
        icon="bell-alert"
        eyebrow="Your homeowner portal"
        title="Get a nudge exactly when you need one"
        body="Hive tells you when something needs your attention or a date moves—by text or email, whichever you prefer. Timely alerts, never noise, so you stay in the loop without lifting a finger."
    />

    {{-- DEEP ROWS --}}
    <div class="py-20 bg-white dark:bg-zinc-950 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8 space-y-24">

            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">Timely alerts, not noise</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        You only hear from Hive when it matters—a new message, a document to sign, a schedule change, or a
                        payment coming due. The things that actually move your project forward.
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            'Only the updates that matter to you',
                            'Know when something needs your action',
                            'Heads-up before dates and visits',
                            'Never blindsided by a change',
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-6 rounded-2xl bg-gray-50 dark:bg-zinc-900 ring-1 ring-gray-200 dark:ring-zinc-800">
                    <p class="text-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">Recent alerts</p>
                    <div class="mt-4 space-y-3">
                        @php
                            $alerts = [
                                ['pencil-square', 'New change order to approve', '2h ago'],
                                ['calendar-date-range', 'Tile start moved to Tue 7/7', 'Yesterday'],
                                ['chat-bubble-left-right', 'Mike replied to your message', 'Yesterday'],
                            ];
                        @endphp
                        @foreach ($alerts as $alert)
                            <div class="flex items-start gap-3 p-3 rounded-xl bg-white dark:bg-zinc-950 ring-1 ring-gray-200 dark:ring-zinc-800">
                                <flux:icon name="{{ $alert[0] }}" class="w-5 h-5 mt-0.5 text-indigo-600 dark:text-indigo-400" />
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $alert[1] }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $alert[2] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div class="lg:order-last">
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">Choose how you hear from us</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        Prefer a text? An email? Both? You decide. Alerts come the way you actually check, so important
                        updates never slip past you—no app notifications to dig through.
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            'Texts, email, or both—your call',
                            'Arrives where you already look',
                            'Tap straight through to your portal',
                            'No app or login hoops to jump through',
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-8 rounded-2xl bg-indigo-600 shadow-xl">
                    <p class="text-xs font-semibold tracking-wide text-indigo-100 uppercase">Text message</p>
                    <div class="p-4 mt-4 rounded-xl bg-white/95 text-gray-900">
                        <p class="text-sm leading-relaxed">Your contractor sent a change order for the recessed lighting. Tap to review &amp; approve: <span class="text-indigo-600 underline">hive.contractors/p/…</span></p>
                    </div>
                    <div class="flex items-center gap-2 mt-4 text-sm font-medium text-white">
                        <flux:icon name="device-phone-mobile" class="w-5 h-5" />
                        <span>Delivered the way you prefer</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- DETAIL GRID --}}
    <div class="py-20 bg-gray-100 dark:bg-zinc-900 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8">
            <div class="max-w-2xl mx-auto text-center">
                <h2 class="text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl">What we will let you know about</h2>
            </div>
            <dl class="grid max-w-xl grid-cols-1 mx-auto mt-16 gap-x-8 gap-y-12 lg:max-w-none lg:grid-cols-3">
                @php
                    $cards = [
                        ['icon' => 'calendar-date-range', 'title' => 'Schedule changes', 'body' => 'Know the moment a date or visit moves.'],
                        ['icon' => 'chat-bubble-left-right', 'title' => 'New messages', 'body' => 'Get pinged when your contractor replies.'],
                        ['icon' => 'pencil-square', 'title' => 'Docs to sign', 'body' => 'A nudge when an estimate or change order is ready.'],
                        ['icon' => 'banknotes', 'title' => 'Payment reminders', 'body' => 'A heads-up before an invoice is due.'],
                        ['icon' => 'flag', 'title' => 'Milestone alerts', 'body' => 'Celebrate the big moments as they happen.'],
                        ['icon' => 'adjustments-horizontal', 'title' => 'Your way', 'body' => 'Choose text, email, or both for every alert.'],
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

    <x-marketing.portal-links current="notifications" heading="Explore the rest of your portal" />

    <x-marketing.homeowner-cta />

    <x-marketing.footer />
</x-guest-layout>
