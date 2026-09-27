<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The shared ss-systems/platform-kit report library's admin surface — see
 * App\Support\Seo\Reports\ReportCapabilities for which of the ten reports
 * this site can actually run: 7 of 10 — cwv-template (no psi_snapshots),
 * area-pages-audit (no area_catalog: hive.contractors has no per-area
 * landing pages) and clarity-health (no Clarity integration at all) need
 * capabilities this site has no honest adapter for.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.admin_api.token' => 'test-admin-api-token']);
});

it('lists all ten kit reports with their requirements', function () {
    $reports = config('seo-reports.reports');

    expect(array_keys($reports))->toBe([
        'content-decay', 'content-gap', 'cwv-template', 'gbp-parity',
        'internal-link-suggest', 'schema-audit', 'area-pages-audit',
        'health-check', 'health', 'clarity-health',
    ]);

    foreach ($reports as $key => $meta) {
        expect($meta)->toHaveKeys(['label', 'description', 'command', 'requires']);
    }

    expect($reports['content-decay']['requires'])->toBe(['query_metrics', 'cache']);
    expect($reports['cwv-template']['requires'])->toBe(['psi_snapshots']);
    expect($reports['area-pages-audit']['requires'])->toBe(['area_catalog', 'page_fetcher', 'site_catalog']);
    expect($reports['clarity-health']['requires'])->toBe(['clarity_metrics']);
    expect($reports['health']['requires'])->toBe(['health_data', 'query_metrics']);
});

it('reports seven available and three unavailable, with owner-facing reasons and no vendor names', function () {
    Storage::fake('local');

    $data = $this->getJson('/api/admin/v1/seo/reports', adminApiHeaders())
        ->assertOk()
        ->json('data');

    $byKey = collect($data['reports'])->keyBy('key');

    expect($byKey['content-decay']['available'])->toBeTrue();
    expect($byKey['content-decay']['status'])->toBe('missing');

    expect($byKey['cwv-template']['available'])->toBeFalse();
    expect($byKey['cwv-template']['status'])->toBe('unavailable');
    expect($byKey['cwv-template']['unavailable_reason'])->toBe('Needs page speed measurements this site does not collect yet.');

    expect($byKey['area-pages-audit']['available'])->toBeFalse();
    expect($byKey['area-pages-audit']['unavailable_reason'])->toBe('Needs the service area pages this site does not have.');

    expect($byKey['clarity-health']['available'])->toBeFalse();
    expect($byKey['clarity-health']['unavailable_reason'])->toBe('Needs visitor behaviour data this site does not collect.');

    foreach ($byKey as $row) {
        expect($row['unavailable_reason'] ?? '')->not->toContain('Clarity');
        expect($row['unavailable_reason'] ?? '')->not->toContain('PageSpeed');
        expect($row['label'])->not->toContain('GSC');
    }

    expect($data['stats'])->toBe([
        'total' => 7, 'generated' => 0, 'fresh' => 0, 'stale' => 0,
        'missing' => 7, 'unavailable' => 3, 'updated_today' => 0, 'last_update' => null,
    ]);
});

it('refuses the three unavailable reports before running anything, with HTTP 200 and a plain reason', function () {
    $cases = [
        'cwv-template' => ['psi_snapshots'],
        'area-pages-audit' => ['area_catalog'],
        'clarity-health' => ['clarity_metrics'],
    ];

    foreach ($cases as $key => $missing) {
        $data = $this->postJson("/api/admin/v1/seo/reports/{$key}/regenerate", [], adminApiHeaders())
            ->assertOk()
            ->json('data');

        expect($data['ok'])->toBeFalse();
        expect($data['status'])->toBe('unavailable');
        expect($data['run'])->toBeNull();
        expect($data['available'])->toBeFalse();
        expect($data['missing'])->toBe($missing);
        expect($data['message'])->not->toBeEmpty();
    }
});

it('runs content-decay for real against seeded query metrics and writes markdown', function () {
    Storage::fake('local');

    $today = now();
    DB::table('gsc_query_metrics')->insert([
        'date' => $today->copy()->subDays(40)->toDateString(), 'site_url' => 'sc-domain:hive.contractors',
        'query' => 'construction pm software', 'page' => '/', 'country' => 'usa', 'device' => 'DESKTOP',
        'impressions' => 200, 'clicks' => 40, 'ctr' => 0.2, 'position' => 5.0, 'dim_hash' => Str::random(40),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('gsc_query_metrics')->insert([
        'date' => $today->copy()->subDays(5)->toDateString(), 'site_url' => 'sc-domain:hive.contractors',
        'query' => 'construction pm software', 'page' => '/', 'country' => 'usa', 'device' => 'DESKTOP',
        'impressions' => 200, 'clicks' => 4, 'ctr' => 0.02, 'position' => 5.0, 'dim_hash' => Str::random(40),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $data = $this->postJson('/api/admin/v1/seo/reports/content-decay/regenerate', [], adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['ok'])->toBeTrue();
    expect($data['status'])->toBe('ok');
    expect($data['run']['exit_code'])->toBe(0);
    expect(Storage::disk('local')->exists('reports/content-decay.md'))->toBeTrue();

    $markdown = Storage::disk('local')->get('reports/content-decay.md');
    expect($markdown)->toStartWith('# Content decay report');
    expect($markdown)->toContain('/');
});

it('runs health directly and always answers a score', function () {
    $exitCode = Artisan::call('seo:health --json');
    $json = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0);
    expect($json)->toHaveKeys(['score', 'pillars']);
});

it('gbp-parity skips the NAP check cleanly since this site has no configured phone or address', function () {
    Http::fake(['*' => Http::response('<html><body>hi</body></html>', 200)]);

    $exitCode = Artisan::call('seo:gbp-parity');
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('(none configured)');
});

it('shows a specific report and includes rendered html', function () {
    Storage::fake('local');
    Storage::disk('local')->put('reports/content-decay.md', "# Content decay report\n\nNothing decayed.");

    $data = $this->getJson('/api/admin/v1/seo/reports/content-decay', adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['exists'])->toBeTrue();
    expect($data['html'])->toContain('Content decay report');
});

it('answers 404 for an unknown report key', function () {
    $this->getJson('/api/admin/v1/seo/reports/not-a-real-report', adminApiHeaders())
        ->assertNotFound();
});
