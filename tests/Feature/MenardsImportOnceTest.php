<?php

use App\Console\Commands\ScrapeMenardsReceipts;
use App\Http\Controllers\MenardsSyncStatusController;
use App\Services\MenardsRemoteBrowserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/**
 * 2026-10-02: a sync asked for by hand at 01:00:30 ran alongside the
 * scheduled one, the extension posted the same five receipts twice, and the
 * two batches were imported side by side, so three expenses each got two
 * identical receipts. Imports now run one at a time, and a sync is not asked
 * for again while the last one has not reported back.
 */
beforeEach(function () {
    Cache::forget(MenardsRemoteBrowserService::SYNC_IN_FLIGHT_KEY);
});

it('waits for another import instead of importing alongside it', function () {
    $other = Cache::lock(ScrapeMenardsReceipts::IMPORT_LOCK, 60);
    expect($other->get())->toBeTrue();

    $this->artisan('menards:scrape-receipts', ['--skip-scrape' => true, '--lock-wait' => 0, '--output-dir' => sys_get_temp_dir().'/menards-none'])
        ->expectsOutputToContain('Another Menards import is still running')
        ->assertFailed();

    $other->release();
});

it('does not ask for a sync while the last one has not reported back', function () {
    Cache::put(MenardsRemoteBrowserService::SYNC_IN_FLIGHT_KEY, now()->toIso8601String(), now()->addMinutes(5));

    $this->mock(MenardsRemoteBrowserService::class, function ($mock) {
        $mock->shouldReceive('status')->andReturn(['running' => true, 'chrome' => true, 'extension' => true, 'configured' => true, 'signed_in' => true, 'posts_to' => '', 'page' => '']);
        $mock->shouldReceive('requestSync')->never();
        $mock->shouldReceive('login')->never();
    });

    $this->artisan('menards:browser', ['action' => 'sync'])
        ->expectsOutputToContain('has not reported yet')
        ->assertSuccessful();
});

it('asks for a sync once the last one has reported, and marks it in flight', function () {
    config(['services.menards.bridge_token' => 'bridge-secret']);
    Cache::put(MenardsRemoteBrowserService::SYNC_IN_FLIGHT_KEY, now()->toIso8601String(), now()->addMinutes(5));

    $this->withToken('bridge-secret')->postJson('/api/menards/sync-status', ['ok' => true, 'receipts' => 5])->assertOk();
    expect(Cache::has(MenardsRemoteBrowserService::SYNC_IN_FLIGHT_KEY))->toBeFalse();

    $this->mock(MenardsRemoteBrowserService::class, function ($mock) {
        $mock->shouldReceive('status')->andReturn(['running' => true, 'chrome' => true, 'extension' => true, 'configured' => true, 'signed_in' => true, 'posts_to' => '', 'page' => '']);
        $mock->shouldReceive('requestSync')->once()->andReturn(['ok' => true]);
        $mock->shouldReceive('waitForSyncToStart')->andReturn(true);
    });

    $this->artisan('menards:browser', ['action' => 'sync'])->assertSuccessful();

    expect(Cache::has(MenardsRemoteBrowserService::SYNC_IN_FLIGHT_KEY))->toBeTrue()
        ->and(Cache::get(MenardsSyncStatusController::CACHE_KEY)['ok'] ?? null)->toBeTrue();
});

/**
 * 2026-10-02 17:00 UTC: the sync page opened and "sync requested" was
 * logged, but the extension's background worker never took the message —
 * no receipt page, no report — and the window passed with nothing fetched.
 */
it('asks once more when the extension never starts the sync', function () {
    $this->mock(MenardsRemoteBrowserService::class, function ($mock) {
        $mock->shouldReceive('status')->andReturn(['running' => true, 'chrome' => true, 'extension' => true, 'configured' => true, 'signed_in' => true, 'posts_to' => '', 'page' => '']);
        $mock->shouldReceive('requestSync')->twice()->andReturn(['ok' => true]);
        $mock->shouldReceive('waitForSyncToStart')->twice()->andReturn(false, true);
    });

    $this->artisan('menards:browser', ['action' => 'sync'])
        ->expectsOutputToContain('The extension did not start the sync — asking again.')
        ->assertSuccessful();

    expect(Cache::has(MenardsRemoteBrowserService::SYNC_IN_FLIGHT_KEY))->toBeTrue();
});

it('fails loudly and frees the next sync when the extension never starts', function () {
    $this->mock(MenardsRemoteBrowserService::class, function ($mock) {
        $mock->shouldReceive('status')->andReturn(['running' => true, 'chrome' => true, 'extension' => true, 'configured' => true, 'signed_in' => true, 'posts_to' => '', 'page' => '']);
        $mock->shouldReceive('requestSync')->twice()->andReturn(['ok' => true]);
        $mock->shouldReceive('waitForSyncToStart')->twice()->andReturn(false);
    });

    $this->artisan('menards:browser', ['action' => 'sync'])->assertFailed();

    expect(Cache::has(MenardsRemoteBrowserService::SYNC_IN_FLIGHT_KEY))->toBeFalse();
});

it('sees a sync start by its receipt page in Chrome\'s tab list', function (array $targets, ?bool $open) {
    Illuminate\Support\Facades\Http::fake(['127.0.0.1:*/json/list' => Illuminate\Support\Facades\Http::response($targets)]);

    expect(app(MenardsRemoteBrowserService::class)->receiptTabOpen())->toBe($open);
})->with([
    'parked, no sync' => [[['type' => 'page', 'url' => 'https://www.menards.com/main/accountoverview.html']], false],
    'a sync running' => [[['type' => 'page', 'url' => 'https://www.menards.com/main/accountoverview.html'], ['type' => 'page', 'url' => 'https://www.menards.com/main/receiptLookup.html']], true],
    'only a frame on it' => [[['type' => 'iframe', 'url' => 'https://www.menards.com/main/receiptLookup.html']], false],
]);
