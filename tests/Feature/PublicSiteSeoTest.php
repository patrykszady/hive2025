<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(fn () => Cache::forget('marketing-sitemap-xml'));

/*
|--------------------------------------------------------------------------
| robots.txt
|--------------------------------------------------------------------------
|
| robots.txt is a STATIC file — nginx in production and server.php's
| file_exists() short-circuit under `artisan serve` both serve it directly
| and never reach the router (confirmed: hitting the Laravel route for it
| 404s once the static file exists, since the dynamic route was removed as
| dead code). So these tests read the file's own content rather than
| dispatching an HTTP request to it.
*/

it('robots.txt allows every locale marketing page and names the sitemap', function () {
    $content = file_get_contents(public_path('robots.txt'));

    foreach (['en', 'pl', 'es'] as $locale) {
        expect($content)->toContain("Allow: /{$locale}/welcome");
    }

    expect($content)->toContain('Allow: /welcome/legal/')
        ->and($content)->toContain('Sitemap: https://hive.contractors/sitemap.xml');
});

it('robots.txt disallows the private app areas', function () {
    $content = file_get_contents(public_path('robots.txt'));

    foreach (['dashboard', 'projects', 'vendors', 'estimates', 'api', 'clients', 'login'] as $prefix) {
        expect($content)->toContain("Disallow: /{$prefix}");
    }
});

it('robots.txt leaves the files a marketing page renders with crawlable', function () {
    // Google renders a page with whatever robots.txt lets it fetch: a
    // catch-all "Disallow: /" or a blocked /flux or /livewire script would
    // leave it an unstyled, script-less page with no indexable images. A
    // private route added later is covered by NoIndexNonPublic's header.
    $content = file_get_contents(public_path('robots.txt'));

    expect($content)->not->toMatch('#^Disallow: /\s*$#m')
        ->and($content)->not->toMatch('#^Disallow: /(flux|livewire|build|css|js|img|images|fonts|favicon)#m');
});

it('has no reachable robots.txt route — the static file is what serves it', function () {
    // No X-Page-Cache/CachePublicPage involved; this simply confirms the
    // dynamic route is gone rather than drifting out of sync with the file.
    $this->get('/robots.txt')->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| sitemap.xml
|--------------------------------------------------------------------------
*/

it('serves a valid sitemap with every marketing route x every locale, rooted on the marketing host', function () {
    // APP_URL deliberately different from the marketing host: the sitemap
    // must never follow it.
    config(['app.url' => 'https://hub.hive.contractors', 'app.marketing_url' => 'https://hive.contractors']);

    $response = $this->get('/sitemap.xml')->assertOk();
    $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

    $xml = simplexml_load_string($response->getContent());
    expect($xml)->not->toBeFalse();

    // 1 (welcome) + 10 top-level area/homeowners pages + 9 homeowner
    // subpages + 66 feature cards (config('marketing.areas')) + 1 (faq)
    // = 87 distinct pages, each in 3 locales.
    $distinctPages = 1 + 10 + 9 + array_sum(array_map(
        fn (array $area) => count($area['cards'] ?? []),
        config('marketing.areas')
    )) + 1;

    expect(count($xml->url))->toBe($distinctPages * 3);

    // Not collect($xml->url)->map(...): SimpleXMLElement's iterator keys
    // repeated sibling nodes all under the same key, so Collection's
    // iterator_to_array(..., true) collapses every <url> but the last one.
    // iterator_to_array($xml->url, false) drops those keys and keeps every
    // node.
    $locs = collect(iterator_to_array($xml->url, false))->map(fn ($url) => (string) $url->loc);

    expect($locs)->toContain('https://hive.contractors/en/welcome')
        ->and($locs)->toContain('https://hive.contractors/pl/welcome')
        ->and($locs)->toContain('https://hive.contractors/es/welcome')
        ->and($locs)->toContain('https://hive.contractors/en/welcome/finances/expenses')
        ->and($locs)->toContain('https://hive.contractors/en/welcome/homeowners/status')
        ->and($locs)->toContain('https://hive.contractors/en/welcome/faq');

    $locs->each(fn (string $loc) => expect($loc)->toStartWith('https://hive.contractors/')
        ->and($loc)->not->toContain('hub.hive.contractors'));
});

it('carries reciprocal hreflang alternates plus an English x-default on every sitemap url', function () {
    config(['app.marketing_url' => 'https://hive.contractors']);

    $xml = simplexml_load_string($this->get('/sitemap.xml')->getContent());
    $xml->registerXPathNamespace('xhtml', 'http://www.w3.org/1999/xhtml');

    $first = $xml->url[0];
    $alternates = $first->xpath('xhtml:link');

    $byHreflang = collect($alternates)->mapWithKeys(fn ($link) => [
        (string) $link->attributes()->hreflang => (string) $link->attributes()->href,
    ]);

    expect($byHreflang->keys()->sort()->values()->all())->toBe(['en', 'es', 'pl', 'x-default']);
    // x-default is explicitly the English URL.
    expect($byHreflang['x-default'])->toBe($byHreflang['en']);
});

it('does not shadow sitemap.xml with a catch-all route', function () {
    $this->get('/sitemap.xml')->assertOk();
});

/*
|--------------------------------------------------------------------------
| Home redirect
|--------------------------------------------------------------------------
*/

it('redirects a guest on the home page to the marketing page temporarily', function () {
    // 302 while the app shares this host: '/' leads a signed-in user to the
    // dashboard, and a browser would keep a 301 from a signed-out visit for
    // good (routes/web.php). A 301 once the app lives on hub.hive.contractors.
    $this->get('/')->assertRedirect('/en/welcome')->assertStatus(302);
});

/*
|--------------------------------------------------------------------------
| Legacy host redirect
|--------------------------------------------------------------------------
*/

it('301s the legacy hub.hive.contractors host to the app host, keeping path and query', function () {
    config(['app.url' => 'https://hive.contractors']);

    $response = $this->get('http://hub.hive.contractors/en/welcome/finances?utm_source=test');

    $response->assertStatus(301)
        ->assertHeader('Location', 'https://hive.contractors/en/welcome/finances?utm_source=test');
});

/*
|--------------------------------------------------------------------------
| NoIndexNonPublic — regression: this used to check only 'welcome'/'welcome/*'
| and, written before the {locale} prefix migration, marked every real
| marketing page (/en/welcome, /pl/welcome/...) noindex on every response.
|--------------------------------------------------------------------------
*/

it('does not mark a locale-prefixed marketing page noindex', function () {
    config(['app.noindex_hosts' => '']);

    foreach (['en', 'pl', 'es'] as $locale) {
        $response = $this->get("/{$locale}/welcome/finances");
        expect($response->headers->get('X-Robots-Tag'))->toBeNull();
    }
});

it('still marks a private app page noindex', function () {
    config(['app.noindex_hosts' => '']);

    $this->get('/login')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
});

it('does not mark the un-prefixed legal pages noindex', function () {
    config(['app.noindex_hosts' => '']);

    $response = $this->get('/welcome/legal/privacy');
    expect($response->headers->get('X-Robots-Tag'))->toBeNull();
});
