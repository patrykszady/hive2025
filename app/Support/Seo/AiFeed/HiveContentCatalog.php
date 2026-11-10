<?php

namespace App\Support\Seo\AiFeed;

use App\Models\BlogPost;
use App\Support\MarketingPageList;
use SsSystems\Platform\Seo\AiFeed\Contracts\ContentCatalog;

/**
 * This site's `stories` for /ai-feed.json: the marketing pages (the same
 * English list /llms.txt carries — App\Support\MarketingPageList) and then
 * the published blog posts, newest first, each with its own summary. The
 * contract calls these "stories" after gs.construction's project
 * write-ups; a software site's equivalent is its product pages and its
 * blog, told apart by `type`.
 */
final class HiveContentCatalog implements ContentCatalog
{
    public function stories(): array
    {
        $pages = collect(MarketingPageList::english())->map(fn (array $page): array => self::row([
            'type' => 'page',
            'title' => $page['title'],
            'url' => $page['url'],
            'summary' => $page['description'],
        ]));

        $posts = BlogPost::published()
            ->orderByDesc('published_at')
            ->limit(50)
            ->get()
            ->map(fn (BlogPost $post): array => self::row([
                'type' => 'post',
                'id' => $post->id,
                'title' => $post->title,
                'url' => marketing_url('en/blog/'.$post->slug),
                'summary' => $post->teaser(),
                'date' => $post->published_at?->toDateString(),
            ]));

        return $pages->concat($posts)->values()->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function row(array $row): array
    {
        return array_filter($row, static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
