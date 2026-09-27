{{--
    Ad-campaign landing page (/lp/{slug}) — driven entirely by the
    LandingPage row passed in as $page; this view holds no copy of its
    own. Built from the same marketing components every /{locale}/welcome
    page uses (x-marketing.nav / feature-hero / cta / footer), so a
    campaign page reads as part of the same site rather than a bolted-on
    microsite. ALWAYS noindex — see App\Models\LandingPage::shouldIndex()
    and routes/web.php's docblock for this route — these pages exist to
    receive paid traffic, not to rank.

    No lead form: unlike dawnsellshomes.com's realtor site, Hive has no
    lead-capture pipeline of its own on the marketing site at all — every
    call to action here is the SAME route('registration') link every
    other marketing page uses (see resources/views/welcome.blade.php),
    reusing that page's exact CTA markup rather than inventing a form.
--}}
@php
    $campaignLabel = \App\Models\LandingPage::CAMPAIGN_TYPES[$page->service]
        ?? \Illuminate\Support\Str::of($page->service)->replace('-', ' ')->title()->toString();
@endphp
@section('title', $page->title.' — Hive Contractors')
<x-guest-layout>
    <x-marketing.nav />

    <x-marketing.feature-hero
        icon="sparkles"
        :eyebrow="$campaignLabel.($page->city ? ' · '.$page->city : '')"
        :title="$page->h1"
        :body="$page->intro ?? ''"
    />

    @if (! empty($page->sections))
        <div class="py-20 bg-white dark:bg-zinc-950 sm:py-28">
            <div class="max-w-3xl px-6 mx-auto space-y-16 lg:px-8">
                @foreach ($page->sections as $section)
                    <div>
                        @if (! empty($section['heading']))
                            <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">{{ $section['heading'] }}</h2>
                        @endif
                        @if (! empty($section['body']))
                            <div class="mt-4 space-y-4 text-lg leading-8 text-gray-600 dark:text-gray-300">
                                @foreach (preg_split('/\n\n+/', trim($section['body'])) as $paragraph)
                                    <p>{{ $paragraph }}</p>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if (! empty($page->faq))
        <div class="py-20 bg-gray-100 dark:bg-zinc-900 sm:py-28">
            <div class="max-w-3xl px-6 mx-auto lg:px-8">
                <h2 class="text-3xl font-bold tracking-tight text-center text-gray-900 dark:text-white">
                    {{ __('Common questions') }}
                </h2>
                <flux:accordion class="mt-10">
                    @foreach ($page->faq as $item)
                        @continue(empty($item['q']))
                        <flux:accordion.item>
                            <flux:accordion.heading>{{ $item['q'] }}</flux:accordion.heading>
                            <flux:accordion.content>{{ $item['a'] ?? '' }}</flux:accordion.content>
                        </flux:accordion.item>
                    @endforeach
                </flux:accordion>
            </div>
        </div>
    @endif

    <x-marketing.cta :heading="$page->h1" />

    <x-marketing.footer />
</x-guest-layout>
