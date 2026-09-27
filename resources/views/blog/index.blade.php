<x-guest-layout
    :title="__('Blog') . ' — Hive Contractors'"
    :description="__('Field notes on running a small contracting business — finances, scheduling, client communication, and the trades.')"
>
    <x-marketing.nav active="blog" />

    <div class="py-24 bg-white dark:bg-zinc-950 sm:py-32">
        <div class="px-6 mx-auto max-w-4xl lg:px-8">
            <div class="text-center">
                <p class="text-base font-semibold text-indigo-600 dark:text-indigo-400">{{ __('Blog') }}</p>
                <h1 class="mt-2 text-4xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-5xl">{{ __('Notes for contractors') }}</h1>
                <p class="mt-6 text-lg text-gray-600 dark:text-gray-400">{{ __('Finances, scheduling, client communication, and the rest of running a trades business.') }}</p>
            </div>

            @if ($posts->isEmpty())
                <div class="mt-16 text-center">
                    <p class="text-base text-gray-500 dark:text-gray-400">{{ __('Nothing published yet — check back soon.') }}</p>
                </div>
            @else
                <div class="mt-16 space-y-10">
                    @foreach ($posts as $post)
                        <article class="pb-10 border-b border-gray-200 dark:border-white/10 last:border-0 last:pb-0">
                            <a href="{{ route('blog.show', ['slug' => $post->slug]) }}" wire:navigate.hover class="flex flex-col gap-6 sm:flex-row">
                                @if ($post->cover_url)
                                    <img src="{{ $post->cover_url }}" alt="" class="object-cover w-full h-48 rounded-2xl sm:w-56 sm:h-36 shrink-0">
                                @else
                                    <div class="hidden sm:block w-56 h-36 rounded-2xl bg-gray-100 dark:bg-zinc-900 shrink-0"></div>
                                @endif
                                <div class="min-w-0">
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        @if ($post->published_at)
                                            <time datetime="{{ $post->published_at->toDateString() }}">{{ $post->published_at->translatedFormat('F j, Y') }}</time>
                                        @endif
                                    </p>
                                    <h2 class="mt-1 text-xl font-semibold text-gray-900 dark:text-white group-hover:text-indigo-600">{{ $post->title }}</h2>
                                    <p class="mt-2 text-base leading-7 text-gray-600 dark:text-gray-300">{{ $post->teaser() }}</p>
                                    <span class="inline-flex items-center gap-1 mt-3 text-sm font-semibold text-indigo-600 dark:text-indigo-400">
                                        {{ __('Read more') }} <span aria-hidden="true">→</span>
                                    </span>
                                </div>
                            </a>
                        </article>
                    @endforeach
                </div>

                <div class="mt-12">
                    {{ $posts->links() }}
                </div>
            @endif
        </div>
    </div>

    <x-marketing.cta />

    <x-marketing.footer />
</x-guest-layout>
