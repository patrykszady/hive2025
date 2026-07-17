@section('title', __('Direct Messaging — Hive Contractors'))
<x-guest-layout>
    <x-marketing.nav active="homeowners" />

    <x-marketing.feature-hero
        icon="chat-bubble-left-right"
        eyebrow="{{ __('Your homeowner portal') }}"
        title="{{ __('One conversation with your contractor') }}"
        body="{{ __('No more scattered texts, emails, and voicemails. Every message, question, and photo lives in one clean thread you can find any time—so nothing about your project gets lost.') }}"
    />

    {{-- DEEP ROWS --}}
    <div class="py-20 bg-white dark:bg-zinc-950 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8 space-y-24">

            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">{{ __('Everything in one thread') }}</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        {{ __('Ask a question, share a concern, or get an answer—all in one place tied to your project. You will always know where to look, and so will your contractor.') }}
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            __('A single thread for your whole project'),
                            __('No more digging through texts and email'),
                            __('Your contractor sees it alongside your job'),
                            __('Searchable any time you need it'),
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
                        <div class="max-w-[80%] p-3 rounded-2xl rounded-bl-sm bg-white dark:bg-zinc-950 ring-1 ring-gray-200 dark:ring-zinc-800">
                            <p class="text-sm text-gray-900 dark:text-white">{{ __('Quick question—can we move the island 6 inches toward the window?') }}</p>
                            <p class="mt-1 text-xs text-gray-400">{{ __('You · 9:14 AM') }}</p>
                        </div>
                        <div class="max-w-[80%] ml-auto p-3 rounded-2xl rounded-br-sm bg-indigo-600 text-white">
                            <p class="text-sm">{!! __('Absolutely. I&rsquo;ll update the plan and send a quick change order to confirm.') !!}</p>
                            <p class="mt-1 text-xs text-indigo-200">{{ __('Mike · 9:21 AM') }}</p>
                        </div>
                        <div class="max-w-[80%] ml-auto p-3 rounded-2xl rounded-br-sm bg-indigo-600 text-white">
                            <flux:icon name="photo" class="w-5 h-5 mb-1 text-indigo-200" />
                            <p class="text-sm">{{ __('Here is the layout with the new spacing.') }}</p>
                            <p class="mt-1 text-xs text-indigo-200">{{ __('Mike · 9:22 AM') }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div class="lg:order-last">
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">{{ __('Share photos and get answers') }}</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        {{ __('Send a photo of the spot you are asking about, point to a detail, and keep the conversation moving. It is the easiest way to stay on the same page without a single phone tag round.') }}
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            __('Attach photos right in the conversation'),
                            __('Reference exactly what you mean'),
                            __('Get notified when your contractor replies'),
                            __('Keep a record of every decision'),
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-8 rounded-2xl bg-indigo-600 shadow-xl">
                    <p class="text-xs font-semibold tracking-wide text-indigo-100 uppercase">Why homeowners love it</p>
                    <div class="mt-4 space-y-3">
                        @foreach ([
                            'No lost texts buried in your inbox',
                            'One place both of you check',
                            'Every answer saved for later',
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
                <h2 class="text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl">Messaging that keeps it simple</h2>
            </div>
            <dl class="grid max-w-xl grid-cols-1 mx-auto mt-16 gap-x-8 gap-y-12 lg:max-w-none lg:grid-cols-3">
                @php
                    $cards = [
                        ['icon' => 'chat-bubble-left-right', 'title' => 'One thread', 'body' => 'All of your project communication in a single place.'],
                        ['icon' => 'photo', 'title' => 'Photo sharing', 'body' => 'Attach pictures so there is no confusion.'],
                        ['icon' => 'magnifying-glass', 'title' => 'Searchable history', 'body' => 'Find any past message or decision in seconds.'],
                        ['icon' => 'bell-alert', 'title' => 'Reply alerts', 'body' => 'Know the moment your contractor responds.'],
                        ['icon' => 'shield-check', 'title' => 'Private to you', 'body' => 'Only you and your contractor see the conversation.'],
                        ['icon' => 'device-phone-mobile', 'title' => 'On your phone', 'body' => 'Message from anywhere—no app to install.'],
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

    <x-marketing.portal-links current="messaging" heading="Explore the rest of your portal" />

    <x-marketing.homeowner-cta />

    <x-marketing.footer />
</x-guest-layout>
