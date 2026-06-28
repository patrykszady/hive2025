@php
    $rows = $card['rows'] ?? [];
    $features = $card['features'] ?? [];
    $cta = $card['cta'] ?? [];
@endphp

<x-guest-layout :title="$card['title'] . ' — Hive Contractors'">
    <x-marketing.nav :active="$areaKey" />

    <x-marketing.feature-hero
        :icon="$card['icon']"
        :eyebrow="$area['eyebrow']"
        :title="$card['hero']"
        :body="$card['lead']"
    />

    {{-- DEEP ROWS --}}
    <div class="py-20 bg-white dark:bg-zinc-950 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8 space-y-24">
            @foreach ($rows as $i => $row)
                <div class="grid grid-cols-1 gap-12 lg:grid-cols-2 lg:items-center">
                    <div @class(['lg:order-last' => $i % 2 === 1])>
                        <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">{!! $row['heading'] !!}</h2>
                        <p class="mt-4 text-lg leading-8 text-gray-600 dark:text-gray-300">{!! $row['text'] !!}</p>
                        <ul class="mt-8 space-y-4">
                            @foreach ($row['points'] as $point)
                                <li class="flex gap-3 text-base text-gray-700 dark:text-gray-300">
                                    <flux:icon name="check-circle" class="w-6 h-6 shrink-0 text-indigo-600 dark:text-indigo-400" />
                                    <span>{!! $point !!}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    @php $panel = $row['panel']; @endphp
                    @if (($panel['style'] ?? 'gray') === 'indigo')
                        <div class="p-8 rounded-2xl bg-indigo-600 shadow-xl">
                            <p class="text-xs font-semibold tracking-wide text-indigo-100 uppercase">{!! $panel['label'] ?? 'Why it matters' !!}</p>
                            <p class="mt-4 text-lg font-medium leading-8 text-white">{!! $panel['note'] !!}</p>
                        </div>
                    @else
                        <div class="p-6 rounded-2xl bg-gray-50 dark:bg-zinc-900 ring-1 ring-gray-200 dark:ring-zinc-800">
                            @if (!empty($panel['title']))
                                <p class="text-sm font-semibold text-gray-900 dark:text-white">{!! $panel['title'] !!}</p>
                            @endif
                            <div class="mt-4 space-y-3">
                                @if (($panel['type'] ?? 'list') === 'stat')
                                    @foreach ($panel['rows'] as $stat)
                                        <div class="flex items-center justify-between p-3 rounded-xl bg-white dark:bg-zinc-950 ring-1 ring-gray-200 dark:ring-zinc-800">
                                            <span class="text-sm text-gray-600 dark:text-gray-400">{!! $stat['label'] !!}</span>
                                            <span class="text-sm font-semibold text-gray-900 dark:text-white">{!! $stat['value'] !!}</span>
                                        </div>
                                    @endforeach
                                @else
                                    @foreach ($panel['rows'] as $line)
                                        <div class="flex items-center gap-3 p-3 rounded-xl bg-white dark:bg-zinc-950 ring-1 ring-gray-200 dark:ring-zinc-800">
                                            <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-indigo-600 shrink-0">
                                                <flux:icon name="{{ $line['icon'] }}" class="w-5 h-5 text-white" />
                                            </div>
                                            <div class="min-w-0">
                                                <p class="text-sm font-medium text-gray-900 dark:text-white truncate">{!! $line['label'] !!}</p>
                                                <p class="text-xs text-gray-500 dark:text-gray-400 truncate">{!! $line['sub'] !!}</p>
                                            </div>
                                        </div>
                                    @endforeach
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- DETAIL GRID --}}
    <div class="py-20 bg-gray-100 dark:bg-zinc-900 sm:py-28">
        <div class="px-6 mx-auto max-w-7xl lg:px-8">
            <div class="max-w-2xl mx-auto text-center">
                <h2 class="text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl">What you get</h2>
            </div>
            <dl class="grid max-w-xl grid-cols-1 mx-auto mt-16 gap-x-8 gap-y-12 lg:max-w-none lg:grid-cols-3">
                @foreach ($features as $feature)
                    <div class="relative pl-16">
                        <dt class="text-base font-semibold leading-7 text-gray-900 dark:text-white">
                            <div class="absolute top-0 left-0 flex items-center justify-center w-10 h-10 bg-indigo-600 rounded-lg">
                                <flux:icon name="{{ $feature['icon'] }}" class="w-6 h-6 text-white" />
                            </div>
                            {!! $feature['title'] !!}
                        </dt>
                        <dd class="mt-2 text-base leading-7 text-gray-600 dark:text-gray-400">{!! $feature['body'] !!}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </div>

    <x-marketing.feature-links :area="$areaKey" :current="$cardKey" :heading="'Explore the rest of ' . $area['label']" />

    <x-marketing.cta
        :heading="$cta['heading'] ?? 'Run your whole business in one place.'"
        :subheading="$cta['sub'] ?? 'Join the contractors who trust Hive to keep every job on track.'"
    />

    <x-marketing.footer />
</x-guest-layout>
