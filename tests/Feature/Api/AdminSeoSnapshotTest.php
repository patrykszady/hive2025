<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.admin_api.token' => ADMIN_API_TEST_TOKEN]);
    // Reports live on the local disk; a production pull fills the real one,
    // so this test counts its own, empty one.
    Storage::fake('local');
});

it('answers the full snapshot shape with automated_actions off and empty tables reading calmly', function () {
    $data = $this->getJson('/api/admin/v1/seo/snapshot', adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data)->toHaveKeys([
        'generated_at', 'automated_actions', 'pulse', 'health', 'report_stats',
        'search', 'trend', 'trend_through', 'gsc_errors', 'sitemaps',
    ]);
    expect($data['automated_actions'])->toBeFalse();
    expect(fn () => \Illuminate\Support\Carbon::parse($data['generated_at']))->not->toThrow(\Exception::class);

    // health: seo:health --json always exits 0 and this is cached — an
    // empty environment still answers a shape, just with null scores.
    expect($data['health'])->toHaveKeys(['score', 'prior_score', 'prior_as_of', 'pillars']);

    // report_stats: 7 available (none generated), 3 unavailable — delegates
    // to SeoReportController so this and the reports list never disagree.
    expect($data['report_stats'])->toBe([
        'total' => 7, 'generated' => 0, 'fresh' => 0, 'stale' => 0,
        'missing' => 7, 'unavailable' => 3, 'updated_today' => 0, 'last_update' => null,
    ]);

    // search: both channels present with zeroed totals, no data collected yet.
    expect($data['search']['channels'])->toHaveKeys(['gsc', 'bing']);
    expect($data['search']['channels']['gsc']['clicks'])->toBe(0);
    expect($data['search']['channels']['bing']['clicks'])->toBe(0);
    expect($data['trend'])->toBe([]);
    expect($data['trend_through'])->toBeNull();

    // gsc_errors: table exists (this app's own migration), so `available`
    // is true even with zero rows tracked.
    expect($data['gsc_errors']['available'])->toBeTrue();
    expect($data['gsc_errors']['totals'])->toBe(['tracked' => 0, 'problem' => 0, 'pass' => 0, 'not_indexed' => 0]);

    // sitemaps: GSC_CREDENTIALS/GSC_PROPERTY unset in this environment reads
    // 'unconfigured', never an error.
    expect($data['sitemaps']['state'])->toBe('unconfigured');
    expect($data['sitemaps']['connected'])->toBeFalse();
});

it('regenerates content-decay for real once gsc_query_metrics has rows, and the search block picks it up', function () {
    \Illuminate\Support\Facades\Storage::fake('local');

    $today = now();
    \Illuminate\Support\Facades\DB::table('gsc_query_metrics')->insert([
        'date' => $today->copy()->subDays(2)->toDateString(), 'site_url' => 'sc-domain:hive.contractors',
        'query' => 'project management for contractors', 'page' => '/', 'country' => 'usa', 'device' => 'DESKTOP',
        'impressions' => 100, 'clicks' => 12, 'ctr' => 0.12, 'position' => 4.2,
        'dim_hash' => \Illuminate\Support\Str::random(40), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $data = $this->getJson('/api/admin/v1/seo/snapshot', adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['search']['channels']['gsc']['clicks'])->toBe(12);

    $regenerate = $this->postJson('/api/admin/v1/seo/reports/content-decay/regenerate', [], adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($regenerate['ok'])->toBeTrue();
    expect($regenerate['status'])->toBe('ok');
    expect(\Illuminate\Support\Facades\Storage::disk('local')->exists('reports/content-decay.md'))->toBeTrue();
});

/**
 * ss-systems/platform-kit's Pulse (see App\Providers\AppServiceProvider's
 * SnapshotBuilder binding and docs/PULSE.md in the kit). The marketing site
 * has no search feature, so `search_event` is never configured here — the
 * shape must carry no `searches`/`searched_cities`/`filters` keys at all,
 * never zeros (ss-systems/CLAUDE.md's "a key one site's API omits renders
 * as an empty card").
 */
it('carries a pulse block with no searches, since the marketing site has no search feature', function () {
    $data = $this->getJson('/api/admin/v1/seo/snapshot', adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['pulse'])->toHaveKeys([
        'timezone', 'window_days', 'trend_days', 'mobile_share_pct',
        'totals', 'totals_prev', 'days', 'features', 'hours',
        'visitor_cities', 'top_pages', 'js_errors',
    ]);
    expect($data['pulse']['timezone'])->toBe('America/Chicago');
    expect($data['pulse'])->not->toHaveKey('searched_cities');
    expect($data['pulse'])->not->toHaveKey('filters');
    expect($data['pulse']['totals'])->not->toHaveKey('searches');
    expect($data['pulse']['totals_prev'])->not->toHaveKey('searches');
});

it('counts a signup click recorded via the pulse beacon as a feature, labelled Sign-up clicks', function () {
    $this->postJson('/pulse', ['e' => 'signup', 'm' => [], 'p' => '/en/welcome'])->assertStatus(204);

    $data = $this->getJson('/api/admin/v1/seo/snapshot', adminApiHeaders())
        ->assertOk()
        ->json('data');

    $signup = collect($data['pulse']['features'])->firstWhere('key', 'signup');

    expect($signup)->not->toBeNull();
    expect($signup['label'])->toBe('Sign-up clicks');
    expect($signup['uses'])->toBe(1);
});
