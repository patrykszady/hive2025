<?php

use App\Models\PlatformSetting;
use App\Support\Seo\HiveSeoProfile;
use App\Support\Seo\PsiSettings;
use App\Support\Seo\Reports\ReportCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SsSystems\Platform\Reports\Contracts\ClarityMetricsReader;
use SsSystems\Platform\Seo\Clarity\ClarityClient;
use SsSystems\Platform\Seo\Clarity\StoreClarityMetricsReader;
use SsSystems\Platform\Seo\Contracts\SeoSiteProfile;
use SsSystems\Platform\Seo\DataForSeo\Contracts\DataForSeoApi;

uses(RefreshDatabase::class);

/**
 * The kit's SEO tools (0.19.1, 2026-11-01) on this site: the blocks
 * gs.construction's/J. Peterson Design's report pages always carried and
 * this site's never did. Patryk, 2026-10-31: "we should be using the
 * same ... in the kit".
 */
beforeEach(function () {
    config(['services.admin_api.token' => ADMIN_API_TEST_TOKEN]);
});

it('carries the rankings, keyword research, visibility, ai traffic, map pack and intel blocks', function () {
    $data = $this->getJson('/api/admin/v1/seo/snapshot?rank_days=7', adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data)->toHaveKeys(['rankings', 'keyword_research', 'dataforseo', 'ai_traffic', 'map_pack', 'intel']);

    expect($data['rankings']['available'])->toBeFalse();
    expect($data['keyword_research']['available'])->toBeFalse();
    expect($data['dataforseo']['share_of_voice'])->toBe([]);
    expect($data['ai_traffic'])->toBe(['referrals' => [], 'crawlers' => [], 'total_referrals' => 0, 'total_crawls' => 0]);
    expect($data['map_pack'])->toBe(['available' => false, 'keywords' => []]);
    expect($data['intel']['families'])->toHaveCount(9, 'every change-intel family is registered');
    expect($data['intel']['open_findings'])->toBe(0);
});

it('counts an ai crawler hit for the report', function () {
    // '/' redirects guests to '/en/welcome' (see PublicSiteSeoTest) — hit
    // the real marketing page so the middleware sees a 200, not a 302.
    $this->get('/en/welcome', ['User-Agent' => 'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)'])->assertOk();

    $row = DB::table('ai_traffic_daily')->first();
    expect($row)->not->toBeNull('a crawler hit lands in ai_traffic_daily');
    expect($row->kind)->toBe('crawler');
    expect($row->source)->toBe('GPTBot');
});

it('registers and schedules the kit commands', function () {
    $commands = array_keys(Artisan::all());
    foreach ([
        'seo:track-rankings', 'seo:show-rankings', 'seo:keyword-research', 'seo:domain-overview',
        'seo:backlink-gap', 'seo:ai-mentions', 'seo:competitor-brand-track',
        'seo:map-pack-grid', 'seo:map-pack-competitors', 'seo:intel', 'seo:psi-sync', 'seo:clarity-sync',
    ] as $name) {
        expect($commands)->toContain($name);
    }

    $this->artisan('schedule:list')
        ->expectsOutputToContain('seo:track-rankings')
        ->expectsOutputToContain('seo:keyword-research')
        ->expectsOutputToContain('seo:ai-mentions')
        ->expectsOutputToContain('seo:intel')
        ->expectsOutputToContain('seo:psi-sync')
        ->expectsOutputToContain('seo:clarity-sync');
});

it('resolves the profile and the dataforseo client from the container', function () {
    $profile = app(SeoSiteProfile::class);
    expect($profile)->toBeInstanceOf(HiveSeoProfile::class);
    expect($profile->domain())->toBe('hive.contractors');
    expect($profile->brandName())->toBe('Hive Contractors');
    expect($profile->locationName())->toBe('Chicago,Illinois,United States');
    expect($profile->latitude())->toBeNull();
    expect($profile->longitude())->toBeNull();
    expect($profile->trackedQueries())->toBe([], 'tracked searches are derived from Search Console');
    expect($profile->mapsQueries())->toBe([], 'no service area to scan a map-pack grid for');
    expect($profile->towns())->toBe([]);
    expect($profile->services())->toContain('lien waiver software');

    Http::fake();
    $api = app(DataForSeoApi::class);
    expect($api->isConfigured())->toBeFalse('no credentials in the test environment');
    expect($api->request('POST', '/v3/serp/google/organic/live/regular', []))->toBe([]);
    Http::assertNothingSent();
});

it('stops a paid command cleanly without credentials', function () {
    Http::fake();

    $this->artisan('seo:keyword-research --budget=1')->assertExitCode(0);
    $this->artisan('seo:track-rankings --dry-run')->assertExitCode(0);
    Http::assertNothingSent();
});

it('stops seo:psi-sync cleanly with no PageSpeed key, cwv-template unavailable', function () {
    expect(ReportCapabilities::availability('cwv-template')['available'])->toBeFalse();

    Http::fake();
    $this->artisan('seo:psi-sync')->assertExitCode(0);
    Http::assertNothingSent();
});

it('makes cwv-template available once a PageSpeed key is saved, replacing this site\'s retired seo:psi-sync', function () {
    PlatformSetting::put(PsiSettings::SETTING_API_KEY, 'a-pagespeed-key');

    expect(ReportCapabilities::availability('cwv-template')['available'])->toBeTrue('CWV by template is runnable once the kit sync feeds psi_snapshots');

    Http::fake();
    $this->artisan('seo:psi-sync --dry-run')->assertExitCode(0);
});

/**
 * Microsoft Clarity (kit 0.20.0, 2026-11-02): seo:clarity-sync over the
 * kit's own Seo\Clarity module (App\Providers\AppServiceProvider's
 * ClarityCredentials/ClarityClient/ClarityMetricsReader bindings),
 * replacing this app's retired App\Services\MicrosoftClarityService and
 * App\Support\Seo\Reports\ClaritySettingsMetricsReader — which always
 * reported zeros/nulls because this site had no clarity_daily_metrics
 * table or sync. Credentials are set through config() directly
 * (App\Support\Seo\ClaritySettings falls back to
 * services.microsoft.clarity.* when nothing is saved in the admin), same
 * as every other credential-gated kit sync test in this file.
 */
it('stops seo:clarity-sync cleanly with no Clarity credentials, clarity-health unavailable', function () {
    expect(ReportCapabilities::availability('clarity-health')['available'])->toBeFalse();

    Http::fake();
    $this->artisan('seo:clarity-sync')->assertExitCode(0);
    Http::assertNothingSent();
    expect(DB::table('clarity_daily_metrics')->count())->toBe(0);
});

it('writes one clarity_daily_metrics row for the configured project, and a same-day rerun keeps one row', function () {
    config([
        'services.microsoft.clarity.project_id' => 'proj-test',
        'services.microsoft.clarity.api_token' => 'tok-test',
    ]);
    expect(ReportCapabilities::availability('clarity-health')['available'])->toBeTrue();

    // A trimmed project-live-insights payload (OS dimension), the same
    // shape gs.construction's production project returns — 50 sessions
    // (40 Windows + 10 iOS) and 7 script errors.
    Http::fake([
        ClarityClient::ENDPOINT.'*' => Http::response([
            ['metricName' => 'Traffic', 'information' => [
                ['OS' => 'Windows', 'totalSessionCount' => 40, 'totalBotSessionCount' => 3, 'distinctUserCount' => 30, 'pagesPerSessionPercentage' => 1.5],
                ['OS' => 'iOS', 'totalSessionCount' => 10, 'totalBotSessionCount' => 0, 'distinctUserCount' => 9, 'pagesPerSessionPercentage' => 2],
            ]],
            ['metricName' => 'ScriptErrorCount', 'information' => [['OS' => 'Windows', 'subTotal' => 7]]],
        ]),
    ]);

    $this->artisan('seo:clarity-sync')->assertExitCode(0);

    expect(DB::table('clarity_daily_metrics')->count())->toBe(1);
    $row = DB::table('clarity_daily_metrics')->first();
    expect($row->project_id)->toBe('proj-test');
    expect((int) $row->sessions)->toBe(50);
    expect((int) $row->script_errors)->toBe(7);

    // Re-running the same day overwrites the existing row, never inserts
    // a second one (ClarityMetricsStore::recordDay()'s upsert).
    $this->artisan('seo:clarity-sync')->assertExitCode(0);
    expect(DB::table('clarity_daily_metrics')->count())->toBe(1);
});

it('binds ClarityMetricsReader to the kit\'s StoreClarityMetricsReader, reading the synced row back', function () {
    config([
        'services.microsoft.clarity.project_id' => 'proj-test',
        'services.microsoft.clarity.api_token' => 'tok-test',
    ]);

    Http::fake([
        ClarityClient::ENDPOINT.'*' => Http::response([
            ['metricName' => 'Traffic', 'information' => [
                ['OS' => 'Windows', 'totalSessionCount' => 12, 'totalBotSessionCount' => 0, 'distinctUserCount' => 10, 'pagesPerSessionPercentage' => 1.0],
            ]],
        ]),
    ]);

    $this->artisan('seo:clarity-sync')->assertExitCode(0);

    $reader = app(ClarityMetricsReader::class);
    expect($reader)->toBeInstanceOf(StoreClarityMetricsReader::class);
    expect($reader->isConfigured())->toBeTrue();
    expect($reader->projectId())->toBe('proj-test');
    expect($reader->storedRowCount())->toBe(1);
    expect($reader->latestStoredMetric()['sessions'])->toBe(12);
});
