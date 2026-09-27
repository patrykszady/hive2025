<?php

use App\Models\GscDailyTotal;
use App\Models\GscQueryMetric;
use App\Models\SeoSyncRun;
use App\Support\Seo\SearchConsoleWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Where SsSystems\Platform\Seo\SearchConsoleSync's rows land on this app —
 * see that kit interface's docblock for what each method must guarantee.
 */
uses(RefreshDatabase::class);

it('inserts then updates a query metric on the same dim_hash', function () {
    $writer = new SearchConsoleWriter;

    $row = [
        'date' => '2026-09-01', 'site_url' => 'sc-domain:hive.contractors',
        'query' => 'project management for contractors', 'page' => 'https://hive.contractors/',
        'country' => 'usa', 'device' => 'MOBILE', 'impressions' => 10, 'clicks' => 1,
        'ctr' => 0.1, 'position' => 5.5, 'dim_hash' => sha1('row-1'),
    ];

    expect($writer->upsertQueryMetric($row))->toBe('inserted');
    expect(GscQueryMetric::count())->toBe(1);

    $row['clicks'] = 3;
    expect($writer->upsertQueryMetric($row))->toBe('updated');
    expect(GscQueryMetric::count())->toBe(1);
    expect(GscQueryMetric::first()->clicks)->toBe(3);
});

it('finds the existing daily total via whereDate rather than inserting a duplicate', function () {
    $writer = new SearchConsoleWriter;

    $writer->upsertDailyTotal('2026-09-01', 'sc-domain:hive.contractors', [
        'clicks' => 5, 'impressions' => 50, 'ctr' => 0.1, 'position' => 4.0,
    ]);
    expect(GscDailyTotal::count())->toBe(1);

    // Re-syncing the same day updates the existing row rather than
    // inserting a duplicate and hitting the (date, site_url) unique index
    // — this is exactly the sqlite-vs-MySQL DATE-truncation case the
    // whereDate() lookup exists for (see the class docblock).
    $writer->upsertDailyTotal('2026-09-01', 'sc-domain:hive.contractors', [
        'clicks' => 9, 'impressions' => 80, 'ctr' => 0.11, 'position' => 3.5,
    ]);

    expect(GscDailyTotal::count())->toBe(1);
    expect(GscDailyTotal::first()->clicks)->toBe(9);
});

it('writes search appearance rows to their own table', function () {
    $writer = new SearchConsoleWriter;

    $writer->upsertSearchAppearance('2026-09-01', 'AI_OVERVIEW', [
        'clicks' => 1, 'impressions' => 20, 'ctr' => 0.05, 'position' => 8.0,
    ]);

    expect(DB::table('gsc_search_appearance_metrics')->count())->toBe(1);
});

it('replaces the single search_console row on every recordSyncRun call', function () {
    $writer = new SearchConsoleWriter;

    $writer->recordSyncRun(['status' => 'ok', 'finished_at' => '2026-09-24T03:30:00+00:00', 'error' => null]);
    $writer->recordSyncRun(['status' => 'error', 'finished_at' => '2026-09-24T03:31:00+00:00', 'error' => 'boom']);

    expect(SeoSyncRun::count())->toBe(1);
    $summary = SeoSyncRun::summary('search_console');
    expect($summary['status'])->toBe('error');
    expect($summary['error'])->toBe('boom');
});
