<?php

use App\Models\PlatformSetting;
use App\Support\Seo\IndexNowSiteKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SsSystems\Platform\Seo\Bing\BingWebmasterApi;
use SsSystems\Platform\Seo\IndexNow\Contracts\IndexNowKey;
use SsSystems\Platform\Seo\IndexNow\IndexNowClient;

/**
 * Wiring kit 0.19.4's two SEO tools into this site: Bing gets whatever
 * sitemaps its own robots.txt lists that Bing does not hold yet
 * (seo:bing-submit-sitemaps), and IndexNow (Bing/Yandex/Seznam/Naver) hears
 * about new or changed pages the same way the sitemap-ping half already did
 * for Google (seo:indexnow). See App\Support\Seo\IndexNowSiteKey and
 * AppServiceProvider's BingWebmasterApi/IndexNowKey bindings.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    // The marketing host, not hub.hive.contractors (this app's APP_URL) —
    // matches PublicSiteSeoTest's own override and keeps the generated
    // key-file route/host predictable.
    config(['app.url' => 'https://hive.contractors']);
});

it('registers and schedules both commands', function () {
    $commands = array_keys(Artisan::all());
    expect($commands)->toContain('seo:bing-submit-sitemaps')
        ->and($commands)->toContain('seo:indexnow');

    $this->artisan('schedule:list')
        ->expectsOutputToContain('seo:bing-submit-sitemaps')
        ->expectsOutputToContain('seo:indexnow');
});

it('seo:bing-submit-sitemaps exits 0 and sends nothing when Bing is not configured', function () {
    Http::fake();

    $this->artisan('seo:bing-submit-sitemaps')->assertExitCode(0);

    Http::assertNothingSent();
});

it('submits whatever Bing is missing from this site\'s own robots.txt', function () {
    config([
        'services.bing_wmt.key' => 'test-bing-key',
        'services.bing_wmt.site_url' => 'https://hive.contractors',
    ]);

    Http::fake([
        'https://hive.contractors/robots.txt' => Http::response("User-agent: *\nAllow: /\nSitemap: https://hive.contractors/sitemap.xml\n"),
        BingWebmasterApi::API_BASE.'/GetFeeds*' => Http::response(['d' => []]),
        BingWebmasterApi::API_BASE.'/SubmitFeed*' => Http::response(['d' => null]),
    ]);

    $this->artisan('seo:bing-submit-sitemaps')->assertExitCode(0);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/GetFeeds'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/SubmitFeed'));
});

it('generates an IndexNow key on first use, serves it at /{key}.txt, and 404s a wrong key', function () {
    $key = app(IndexNowKey::class)->key();

    expect($key)->toMatch('/^[a-f0-9]{32}$/');
    expect(PlatformSetting::get(IndexNowSiteKey::SETTING_KEY))->toBe($key);

    $response = $this->get("/{$key}.txt")->assertOk();
    $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    expect($response->getContent())->toBe($key);

    $wrongKey = $key === str_repeat('0', 32) ? str_repeat('1', 32) : str_repeat('0', 32);
    $this->get("/{$wrongKey}.txt")->assertNotFound();
});

it('still serves robots.txt and llms.txt exactly as before the key-file route was added', function () {
    // robots.txt is a STATIC file (see PublicSiteSeoTest) — the dynamic
    // route 404s before and after; the new {key}.txt route's regex
    // ([a-f0-9]{32}) can never match 'robots' anyway.
    $this->get('/robots.txt')->assertNotFound();
    expect(file_get_contents(public_path('robots.txt')))->toContain('Sitemap: https://hive.contractors/sitemap.xml');

    $this->get('/llms.txt')->assertOk();
});

it('seo:indexnow --dry-run computes what is due but sends nothing', function () {
    Http::fake([
        'https://hive.contractors/robots.txt' => Http::response("User-agent: *\nSitemap: https://hive.contractors/sitemap.xml\n"),
        'https://hive.contractors/sitemap.xml' => Http::response(
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .'<url><loc>https://hive.contractors/en/welcome</loc><lastmod>2026-10-01</lastmod></url>'
            .'</urlset>'
        ),
    ]);

    $this->artisan('seo:indexnow --dry-run')->assertExitCode(0);

    Http::assertNotSent(fn ($request) => $request->url() === IndexNowClient::ENDPOINT);
    expect(DB::table('indexnow_submissions')->count())->toBe(0);
});

it('a real seo:indexnow run submits due urls and records them in the ledger', function () {
    Http::fake([
        'https://hive.contractors/robots.txt' => Http::response("User-agent: *\nSitemap: https://hive.contractors/sitemap.xml\n"),
        'https://hive.contractors/sitemap.xml' => Http::response(
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .'<url><loc>https://hive.contractors/en/welcome</loc><lastmod>2026-10-01</lastmod></url>'
            .'<url><loc>https://hive.contractors/en/welcome/finances</loc><lastmod>2026-10-02</lastmod></url>'
            .'</urlset>'
        ),
        IndexNowClient::ENDPOINT => Http::response('', 200),
    ]);

    $this->artisan('seo:indexnow')->assertExitCode(0);

    Http::assertSent(fn ($request) => $request->url() === IndexNowClient::ENDPOINT
        && $request['host'] === 'hive.contractors'
        && count($request['urlList']) === 2);

    expect(DB::table('indexnow_submissions')->count())->toBe(2);
    expect(DB::table('indexnow_submissions')->first()->last_status)->toBe('accepted');
});
