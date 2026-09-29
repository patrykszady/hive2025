<?php

use App\Support\Seo\Reports\ReportRefresh;
use App\Support\SeoStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use SsSystems\Platform\Reports\Jobs\RunArtisanCommandDetached;

/**
 * The Full Report Library read "needs a refresh" most of the week
 * (2026-09-29): a report is fresh for 24 hours and the schedule ran weekly,
 * and one report's Run is cut off by the 30-second web limit. "Refresh all"
 * starts one background pass; the schedule runs the same pass hourly.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.admin_api.token' => ADMIN_API_TEST_TOKEN]);
    Storage::fake('local');
    Cache::flush();
});

/** @return list<string> */
function availableReportKeys(): array
{
    return ReportRefresh::keysToRun(onlyStale: false);
}

function writeReport(string $key, int $hoursAgo = 0): void
{
    Storage::disk('local')->put(SeoStorage::path("reports/{$key}.md"), "# {$key}\n");
    touch(Storage::disk('local')->path(SeoStorage::path("reports/{$key}.md")), now()->subHours($hoursAgo)->getTimestamp());
}

it('starts one background pass over every report that needs a refresh', function () {
    Queue::fake();
    $keys = availableReportKeys();
    writeReport($keys[0]);            // up to date
    writeReport($keys[1], 30);        // stale

    $data = $this->postJson('/api/admin/v1/seo/reports/refresh', [], adminApiHeaders())->assertOk()->json('data');

    $expected = array_values(array_diff($keys, [$keys[0]]));
    expect($data['queued'])->toBeTrue()
        ->and($data['keys'])->toBe($expected)
        ->and($data['batch']['running'])->toBeTrue();

    Queue::assertPushed(RunArtisanCommandDetached::class, fn ($job) => $job->command === 'seo:reports-refresh'
        && $job->options === ['--keys' => implode(',', $expected)]);

    $this->getJson('/api/admin/v1/seo/reports', adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.batch.running', true)
        ->assertJsonPath('data.batch.keys', $expected);
});

it('does not start a second pass while one is running', function () {
    Queue::fake();
    ReportRefresh::markQueued(['health']);

    $this->postJson('/api/admin/v1/seo/reports/refresh', [], adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.queued', false)
        ->assertJsonPath('data.running', true);

    Queue::assertNothingPushed();
});

it('says so when every report is up to date', function () {
    Queue::fake();
    foreach (availableReportKeys() as $key) {
        writeReport($key);
    }

    $this->postJson('/api/admin/v1/seo/reports/refresh', [], adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.queued', false)
        ->assertJsonPath('data.message', 'Every report is up to date.');

    Queue::assertNothingPushed();
});

it('runs the reports one after another and records the progress', function () {
    $keys = availableReportKeys();
    $ran = [];

    $progress = ReportRefresh::run(onlyStale: false, runner: function (string $key, array $meta, Request $request, int $days) use (&$ran) {
        $ran[] = $key;
        writeReport($key);

        return ['ok' => true, 'status' => 'ok', 'output_tail' => ''];
    });

    expect($ran)->toBe($keys)
        ->and($progress['running'])->toBeFalse()
        ->and($progress['done'])->toBe($keys)
        ->and($progress['results'])->each->toBe('ok')
        ->and(ReportRefresh::keysToRun(onlyStale: true))->toBe([]);
});

it('counts a clean run with nothing to write as checked today', function () {
    $key = availableReportKeys()[0];

    ReportRefresh::run(onlyStale: false, keys: [$key], runner: fn () => ['ok' => true, 'status' => 'ok', 'output_tail' => "Loading…\nNothing ranking in positions 8-20 this month."]);

    $note = Storage::disk('local')->get(SeoStorage::path("reports/{$key}.md"));
    expect($note)->toContain('Nothing ranking in positions 8-20 this month.')
        ->and(ReportRefresh::keysToRun(onlyStale: true))->not->toContain($key);
});

it('leaves a failed report stale and lets the hourly pass wait before trying it again', function () {
    $key = availableReportKeys()[0];
    $failing = fn () => ['ok' => false, 'status' => 'failed', 'output_tail' => 'boom'];

    $first = ReportRefresh::run(onlyStale: true, keys: [$key], automatic: true, runner: $failing);
    expect($first['results'][$key])->toBe('failed')
        ->and(Storage::disk('local')->exists(SeoStorage::path("reports/{$key}.md")))->toBeFalse();

    $second = ReportRefresh::run(onlyStale: true, keys: [$key], automatic: true, runner: $failing);
    expect($second['done'])->toBe([]);

    $this->travel(7)->hours();
    $third = ReportRefresh::run(onlyStale: true, keys: [$key], automatic: true, runner: $failing);
    expect($third['done'])->toBe([$key]);
});
