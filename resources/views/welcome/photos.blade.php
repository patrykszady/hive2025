@section('title', __('Photos & Timelapses — Hive Contractors'))
<x-guest-layout>
    <x-marketing.nav active="photos" />

    <x-marketing.feature-hero
        icon="camera"
        eyebrow="{{ __('Jobsite photos & timelapses') }}"
        title="{{ __('Document every job as it happens') }}"
        body="{{ __('Crews shoot progress from their phone straight into the job. Hive files it, lines the frames up into a timelapse, evens out the light, and blurs the faces—so you finish each project with a record worth showing.') }}"
    />

    <div class="py-20 bg-white dark:bg-zinc-950 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8 space-y-24">

            {{-- Timelapse, shown rather than described --}}
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">{{ __('Months of work, eight seconds of playback') }}</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        {{ __('Shoot the same view each visit—the last frame ghosts over your viewfinder so it takes a second. Hive registers every frame onto one steady viewpoint and plays the whole build back as a sequence.') }}
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            __('Onion-skin camera keeps every shot on the same view'),
                            __('Handheld shift and tilt corrected automatically'),
                            __('Straight lines stay straight—nothing is warped'),
                            __('Or build one from photos you already took'),
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <x-marketing.timelapse-demo />
            </div>

            {{-- Consistency --}}
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div class="lg:order-last">
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">{{ __('Consistent light, no flicker') }}</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        {{ __('A 7am shot and a December shot do not match on their own. Hive evens the tone across the sequence so the same materials read the same throughout, while the day still looks like the day.') }}
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            __('Exposure and color cast evened between frames'),
                            __('Works across hours, days and seasons'),
                            __('Whole-image only—nothing selectively repainted'),
                            __('Runs automatically on every frame'),
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-6 rounded-2xl bg-gray-50 dark:bg-zinc-900 ring-1 ring-gray-200 dark:ring-zinc-800">
                    <div class="grid grid-cols-4 gap-2">
                        @foreach ([
                            [__('7 AM'), 'from-amber-100 to-amber-200'],
                            [__('Noon'), 'from-sky-100 to-sky-200'],
                            [__('4 PM'), 'from-orange-100 to-orange-200'],
                            [__('Dec'), 'from-slate-100 to-slate-300'],
                        ] as $shot)
                            <div class="overflow-hidden rounded-lg ring-1 ring-gray-200 dark:ring-zinc-800">
                                <div class="h-16 bg-gradient-to-br {{ $shot[1] }}"></div>
                                <p class="px-2 py-1 text-[11px] font-semibold text-center text-gray-600 dark:text-gray-400">{{ $shot[0] }}</p>
                            </div>
                        @endforeach
                    </div>
                    <flux:icon name="arrow-down" class="w-5 h-5 mx-auto my-3 text-gray-400" />
                    <div class="grid grid-cols-4 gap-2">
                        @foreach ([__('7 AM'), __('Noon'), __('4 PM'), __('Dec')] as $shot)
                            <div class="overflow-hidden rounded-lg ring-1 ring-gray-200 dark:ring-zinc-800">
                                <div class="h-16 bg-gradient-to-br from-sky-100 to-sky-200"></div>
                                <p class="px-2 py-1 text-[11px] font-semibold text-center text-gray-600 dark:text-gray-400">{{ $shot }}</p>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-3 text-xs text-center text-gray-500 dark:text-gray-400">{{ __('Same materials, same color, every visit') }}</p>
                </div>
            </div>

            {{-- Privacy --}}
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">{{ __('Faces blurred before anyone looks') }}</h2>
                    <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                        {{ __('Jobsite photos catch people. Hive finds faces and blurs them on every copy that gets viewed or shared—automatically, with nothing to remember and no step to skip.') }}
                    </p>
                    <ul class="mt-8 space-y-4">
                        @foreach ([
                            __('Runs on upload and on every derived copy'),
                            __('Covers photos and timelapse frames alike'),
                            __('Catches people across the room, not just close-ups'),
                            __('The untouched original stays for your records'),
                        ] as $item)
                            <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="p-8 rounded-2xl bg-indigo-600 shadow-xl">
                    <p class="text-xs font-semibold tracking-wide text-indigo-100 uppercase">{{ __('Why it matters') }}</p>
                    <p class="mt-3 text-lg font-medium text-white">
                        {{ __('Sharing progress should never mean sharing someone\'s face. Your crew, your subs, and the homeowner walking through all stay unidentifiable in anything you send.') }}
                    </p>
                    <p class="mt-4 text-sm text-indigo-100">
                        {{ __('The full-resolution original is kept intact for the record—reachable only by the person who took it and the company that owns the job.') }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    <x-marketing.feature-links area="photos" heading="{{ __('Everything in the jobsite camera') }}" />

    <x-marketing.cta
        heading="{{ __('Finish every job with proof.') }}"
        subheading="{{ __('Photos filed as they are shot, timelapses built automatically, privacy handled for you.') }}"
    />

    <x-marketing.footer />
</x-guest-layout>
