<?php

use App\Models\BingDailyTotal;
use App\Models\BingTrafficStat;
use App\Support\Seo\BingWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Where SsSystems\Platform\Seo\Bing\BingSync's rows land on this app. */
uses(RefreshDatabase::class);

it('inserts then updates a query stat on the same dim_hash', function () {
    $writer = new BingWriter;

    $row = [
        'date' => '2026-09-01', 'site_url' => 'https://hive.contractors',
        'query' => 'construction crm software', 'impressions' => 12, 'clicks' => 2,
        'position' => 6.1, 'dim_hash' => sha1('bing-row-1'),
    ];

    expect($writer->upsertQueryStat($row))->toBe('inserted');
    $row['clicks'] = 5;
    expect($writer->upsertQueryStat($row))->toBe('updated');

    expect(BingTrafficStat::count())->toBe(1);
    expect(BingTrafficStat::first()->clicks)->toBe(5);
});

it('finds the existing daily total via whereDate rather than inserting a duplicate', function () {
    $writer = new BingWriter;

    $writer->upsertDailyTotal('2026-09-01', 'https://hive.contractors', ['clicks' => 3, 'impressions' => 30, 'ctr' => 0.1]);
    $writer->upsertDailyTotal('2026-09-01', 'https://hive.contractors', ['clicks' => 7, 'impressions' => 60, 'ctr' => 0.12]);

    expect(BingDailyTotal::count())->toBe(1);
    expect(BingDailyTotal::first()->clicks)->toBe(7);
});
