@section('title', 'Secure, Easy Access — Hive Contractors')
<x-guest-layout>
    <x-marketing.nav active="homeowners" />

    <x-marketing.feature-hero
        icon="finger-print"
        eyebrow="Your homeowner portal"
        title="Get in safely—no app, no password"
        body="Your project opens with a private link your contractor sends you. There is nothing to download and no password to remember—just secure, instant access to everything about your job."
    />

    {{-- DEEP ROWS --}}
    <div class="py-20 bg-white dark:bg-zinc-950 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8 space-y-24">

            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">One private link, just for you</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        Your contractor invites you with a secure link tied to your project. Tap it and you are in—on your
                        phone, tablet, or computer. No accounts to create, no app store, no friction.
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            'Open your project with one tap',
                            'Nothing to download or install',
                            'No password to create or remember',
                            'Works on any phone or computer',
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-8 rounded-2xl bg-indigo-600 shadow-xl">
                    <p class="text-xs font-semibold tracking-wide text-indigo-100 uppercase">Your invite</p>
                    <div class="p-4 mt-4 rounded-xl bg-white/95 text-gray-900">
                        <p class="text-sm leading-relaxed">Mike invited you to follow your kitchen remodel on Hive. Tap to open your project:</p>
                        <div class="flex items-center gap-2 p-2 mt-3 text-sm font-medium text-indigo-600 rounded-lg bg-indigo-50">
                            <flux:icon name="link" class="w-4 h-4" />
                            <span class="truncate">hive.contractors/p/maple-st</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 mt-4 text-sm font-medium text-white">
                        <flux:icon name="finger-print" class="w-5 h-5" />
                        <span>Secure &amp; private to you</span>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div class="lg:order-last">
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">Built to keep your project private</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        Only you and your contractor can see your project. Your information is protected and never shared,
                        and getting in is as safe as it is simple.
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            'Private to you and your contractor',
                            'Your details are never sold or shared',
                            'Secure connection from any device',
                            'Free for homeowners—always',
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
                            $points = [
                                ['lock-closed', 'Private', 'Only you and your contractor have access.'],
                                ['device-phone-mobile', 'No app needed', 'Opens right in your browser.'],
                                ['gift', 'Always free', 'Homeowners never pay a cent.'],
                            ];
                        @endphp
                        @foreach ($points as $point)
                            <div class="flex items-start gap-3 p-3 rounded-xl bg-white dark:bg-zinc-950 ring-1 ring-gray-200 dark:ring-zinc-800">
                                <flux:icon name="{{ $point[0] }}" class="w-5 h-5 mt-0.5 text-indigo-600 dark:text-indigo-400" />
                                <div>
                                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $point[1] }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $point[2] }}</p>
                                </div>
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
                <h2 class="text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl">Access made effortless</h2>
            </div>
            <dl class="grid max-w-xl grid-cols-1 mx-auto mt-16 gap-x-8 gap-y-12 lg:max-w-none lg:grid-cols-3">
                @php
                    $cards = [
                        ['icon' => 'link', 'title' => 'Private link', 'body' => 'A secure link that opens only your project.'],
                        ['icon' => 'finger-print', 'title' => 'Passwordless', 'body' => 'Nothing to remember or reset.'],
                        ['icon' => 'arrow-down-tray', 'title' => 'No app to install', 'body' => 'Works right in your phone browser.'],
                        ['icon' => 'device-phone-mobile', 'title' => 'Any device', 'body' => 'Phone, tablet, or computer—your choice.'],
                        ['icon' => 'shield-check', 'title' => 'Private to you', 'body' => 'Only you and your contractor can see it.'],
                        ['icon' => 'gift', 'title' => 'Always free', 'body' => 'Homeowners never pay to use Hive.'],
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

    <x-marketing.portal-links current="access" heading="Explore the rest of your portal" />

    <x-marketing.homeowner-cta />

    <x-marketing.footer />
</x-guest-layout>
