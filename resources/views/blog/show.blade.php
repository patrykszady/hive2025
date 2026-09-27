@php
    $isPreview = $isPreview ?? false;
    $displayDate = $post->published_at ?? $post->updated_at;
@endphp
<x-guest-layout
    :title="($post->meta_title ?: $post->title) . ' — Hive Contractors'"
    :description="$post->meta_description ?: $post->teaser(160)"
>
    <x-marketing.nav active="blog" />

    @if ($isPreview)
        <div class="bg-amber-500 text-center text-sm font-medium text-white py-2 px-6">
            {{ __('Preview — this post is a draft and is not public yet.') }}
        </div>
    @endif

    <div class="py-24 bg-white dark:bg-zinc-950 sm:py-32">
        <div class="px-6 mx-auto max-w-3xl lg:px-8">
            <a href="{{ route('blog.index') }}" wire:navigate.hover class="inline-flex items-center gap-1 text-sm font-semibold text-indigo-600 dark:text-indigo-400">
                <span aria-hidden="true">←</span> {{ __('Back to blog') }}
            </a>

            <p class="mt-6 text-sm text-gray-500 dark:text-gray-400">
                @if ($displayDate)
                    <time datetime="{{ $displayDate->toDateString() }}">{{ $displayDate->translatedFormat('F j, Y') }}</time>
                @endif
            </p>
            <h1 class="mt-2 text-4xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-5xl">{{ $post->title }}</h1>

            @if ($post->cover_url)
                <img src="{{ $post->cover_url }}" alt="" class="w-full mt-10 rounded-2xl object-cover max-h-96">
            @endif

            <div class="mt-10 prose prose-lg prose-indigo dark:prose-invert max-w-none">
                {!! $post->body_html !!}
            </div>
        </div>
    </div>

    <x-marketing.cta />

    <x-marketing.footer />
</x-guest-layout>
