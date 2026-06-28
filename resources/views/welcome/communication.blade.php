@section('title', 'Communication — Hive Contractors')
<x-guest-layout>
    <x-marketing.nav active="communication" />

    <x-marketing.feature-hero
        icon="chat-bubble-left-right"
        eyebrow="Communication"
        title="One inbox for clients, vendors, and crews"
        body="Text and call everyone on the job from a single shared thread. Calls are recorded and transcribed, messages translate across languages, and any text can turn into a scheduled task—so nothing gets lost in a group chat."
    />

    {{-- DEEP FEATURE ROWS --}}
    <div class="py-20 bg-white dark:bg-zinc-950 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8 space-y-24">

            {{-- Messaging --}}
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">Group texting that stays organized</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        Every job gets its own thread, so the homeowner, the subs, and your crew are all on the same page.
                        Send photos, share schedules, and keep a clean history you can search later.
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            'SMS and MMS with photos from the field',
                            'One thread per job—no more scattered group chats',
                            'Send clients a live schedule link in a tap',
                            'Templates for the messages you send over and over',
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
                        <div class="ml-auto max-w-[85%] rounded-2xl bg-indigo-500/60 px-4 py-3 text-white">
                            <p class="text-xs font-semibold text-indigo-100">GS Crew · 5:29 PM</p>
                            <p class="mt-1 text-sm leading-relaxed">Hi Carri, Debra &amp; Alan — upcoming tasks:<br>Next up Tue 6/30: Demo @ 8AM.<br>View Schedule →</p>
                        </div>
                        <div class="max-w-[85%] rounded-2xl bg-white/95 px-4 py-3 text-gray-900">
                            <p class="text-xs font-semibold text-gray-500">Greg (Plumbing)</p>
                            <p class="mt-1 text-sm leading-relaxed">Rough plumbing wrapped. Sending photos now 👍</p>
                        </div>
                        <div class="ml-auto max-w-[85%] rounded-2xl bg-indigo-500/60 px-4 py-3 text-white">
                            <p class="text-xs font-semibold text-indigo-100">You</p>
                            <p class="mt-1 text-sm leading-relaxed">Perfect—created a task for inspection Thursday ✅</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Calls --}}
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div class="lg:order-last">
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">Calls you never have to remember</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        Make and take calls through Hive and every one is recorded, transcribed, and summarized. The
                        details—prices, change requests, promises—are captured automatically and attached to the job.
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            'High-quality call recording on inbound and outbound',
                            'Automatic transcription you can search',
                            'AI summaries with action items pulled out',
                            'Caller ID and history tied to the right contact',
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-8 rounded-2xl bg-gray-50 dark:bg-zinc-900 ring-1 ring-gray-200 dark:ring-zinc-800">
                    <div class="flex items-center gap-3">
                        <div class="flex items-center justify-center w-10 h-10 bg-indigo-600 rounded-full">
                            <flux:icon name="phone" class="w-5 h-5 text-white" />
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">Inbound call · 7:12</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Recorded &amp; transcribed</p>
                        </div>
                    </div>
                    <div class="mt-5 p-4 rounded-xl bg-white dark:bg-zinc-950 ring-1 ring-gray-200 dark:ring-zinc-800">
                        <p class="text-xs font-semibold text-indigo-600 dark:text-indigo-400 uppercase tracking-wide">AI summary</p>
                        <p class="mt-2 text-sm leading-6 text-gray-700 dark:text-gray-300">
                            Homeowner approved the tile upgrade (+$1,400) and asked to move the walkthrough to Friday.
                            Action: send updated change order, reschedule walkthrough.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <x-marketing.feature-links area="communication" heading="Built for how contractors actually talk" />

    <x-marketing.cta
        heading="Keep every job on the same page."
        subheading="Bring your texts, calls, and client updates into one place your whole crew can see."
    />

    <x-marketing.footer />
</x-guest-layout>
