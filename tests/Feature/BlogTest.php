<?php

use App\Models\BlogPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(fn () => Cache::forget('marketing-sitemap-xml'));

function makeBlogPost(array $overrides = []): BlogPost
{
    return BlogPost::create(array_merge([
        'slug' => 'five-tips-for-contractors',
        'title' => 'Five Tips For Contractors',
        'excerpt' => null,
        'body_html' => '<p>Some field notes for the trades.</p>',
        'meta_title' => null,
        'meta_description' => null,
        'status' => 'published',
        'published_at' => now()->subDay(),
        'cover_url' => null,
    ], $overrides));
}

/*
|--------------------------------------------------------------------------
| Public index
|--------------------------------------------------------------------------
*/

it('lists published posts newest first and never a draft', function () {
    $old = makeBlogPost(['slug' => 'older-post', 'title' => 'Older Post', 'published_at' => now()->subWeek()]);
    $new = makeBlogPost(['slug' => 'newer-post', 'title' => 'Newer Post', 'published_at' => now()->subDay()]);
    makeBlogPost(['slug' => 'a-draft', 'title' => 'A Secret Draft', 'status' => 'draft', 'published_at' => null]);

    $response = $this->get('/en/blog')->assertOk();

    $response->assertSeeInOrder([$new->title, $old->title])
        ->assertDontSee('A Secret Draft');
});

it('paginates the index', function () {
    for ($i = 1; $i <= 12; $i++) {
        makeBlogPost(['slug' => "post-{$i}", 'title' => "Post {$i}", 'published_at' => now()->subDays($i)]);
    }

    $this->get('/en/blog')->assertOk()->assertSee('Post 1')->assertDontSee('Post 11');
    $this->get('/en/blog?page=2')->assertOk()->assertSee('Post 11');
});

it('falls back to a teaser from the body when a post has no excerpt', function () {
    makeBlogPost(['excerpt' => null, 'body_html' => '<p>A body with no excerpt at all, long enough to matter.</p>']);

    $this->get('/en/blog')->assertOk()->assertSee('A body with no excerpt at all, long enough to matter.');
});

/*
|--------------------------------------------------------------------------
| Public post page
|--------------------------------------------------------------------------
*/

it('renders a published post with its title, date, body and meta tags', function () {
    $post = makeBlogPost([
        'title' => 'Winter Job Site Prep',
        'body_html' => '<p>Cover your <strong>materials</strong>.</p>',
        'meta_title' => 'Winter Job Site Prep — Guide',
        'meta_description' => 'How to prep a job site for winter.',
        'published_at' => now()->subDays(3),
    ]);

    $response = $this->get('/en/blog/'.$post->slug)->assertOk();

    $response->assertSee('Winter Job Site Prep')
        ->assertSee('Cover your', false)
        ->assertSee('<title>Winter Job Site Prep — Guide — Hive Contractors | Hive Contractors</title>', false)
        ->assertSee('<meta name="description" content="How to prep a job site for winter.">', false);
});

it('404s a draft on its public address', function () {
    $post = makeBlogPost(['slug' => 'unfinished', 'status' => 'draft', 'published_at' => null]);

    $this->get('/en/blog/'.$post->slug)->assertNotFound();
});

it('404s an unknown slug', function () {
    $this->get('/en/blog/does-not-exist')->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Signed preview — see App\Models\BlogPost::previewUrl()
|--------------------------------------------------------------------------
*/

it('opens a draft through its signed preview link', function () {
    $post = makeBlogPost(['slug' => 'preview-me', 'title' => 'Preview Me', 'status' => 'draft', 'published_at' => null]);

    $previewUrl = $post->previewUrl();
    expect($previewUrl)->not->toBeNull();

    $this->get($previewUrl)->assertOk()->assertSee('Preview Me')
        ->assertSee('this post is a draft and is not public yet', false);
});

it('never mints a preview link once a post is published', function () {
    $post = makeBlogPost();

    expect($post->previewUrl())->toBeNull();
});

it('refuses a preview link with no valid signature', function () {
    $post = makeBlogPost(['slug' => 'tampered', 'status' => 'draft', 'published_at' => null]);

    $this->get('/en/blog/'.$post->slug.'/preview?expires=9999999999&signature=not-a-real-signature')
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| NoIndexNonPublic — a published post is indexable, a draft's 404 and the
| signed preview page are not.
|--------------------------------------------------------------------------
*/

it('ships no X-Robots-Tag on a published post or the index', function () {
    config(['app.noindex_hosts' => '']);

    $post = makeBlogPost();

    $this->get('/en/blog')->assertHeaderMissing('X-Robots-Tag');
    $this->get('/en/blog/'.$post->slug)->assertHeaderMissing('X-Robots-Tag');
});

it('ships X-Robots-Tag noindex on a draft\'s 404 and on the preview page', function () {
    $post = makeBlogPost(['slug' => 'hidden-draft', 'status' => 'draft', 'published_at' => null]);

    $this->get('/en/blog/'.$post->slug)
        ->assertNotFound()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');

    $this->get($post->previewUrl())
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
});

/*
|--------------------------------------------------------------------------
| Sitemap + robots — see App\Support\MarketingSitemap, public/robots.txt
|--------------------------------------------------------------------------
*/

it('includes the blog index and every published post in the sitemap, never a draft', function () {
    config(['app.marketing_url' => 'https://hive.contractors']);

    $published = makeBlogPost(['slug' => 'in-the-sitemap', 'title' => 'In The Sitemap']);
    makeBlogPost(['slug' => 'not-in-the-sitemap', 'title' => 'Not In The Sitemap', 'status' => 'draft', 'published_at' => null]);

    $xml = simplexml_load_string($this->get('/sitemap.xml')->getContent());
    $locs = collect(iterator_to_array($xml->url, false))->map(fn ($url) => (string) $url->loc);

    expect($locs)->toContain('https://hive.contractors/en/blog')
        ->and($locs)->toContain('https://hive.contractors/en/blog/'.$published->slug)
        ->and($locs)->not->toContain('https://hive.contractors/en/blog/not-in-the-sitemap');
});

it('allows every locale\'s blog path in robots.txt', function () {
    $content = file_get_contents(public_path('robots.txt'));

    foreach (['en', 'pl', 'es'] as $locale) {
        expect($content)->toContain("Allow: /{$locale}/blog");
    }
});
