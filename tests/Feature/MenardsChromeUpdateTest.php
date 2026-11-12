<?php

/**
 * Chrome on the server is updated by a weekly root job in Forge. Hive's part:
 * the daily pass restarts the Menards browser onto an updated Chrome, and a
 * weekly check tells an admin when Chrome has fallen behind
 * (App\Support\Menards\ChromeInstall).
 */

use App\Jobs\NotifyMenardsBrowserNeedsAttention;
use App\Services\MenardsRemoteBrowserService;
use App\Support\Menards\ChromeInstall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Away from the real ingest directory and status files (parallel runs share storage).
    $this->storage = sys_get_temp_dir().'/menards-chrome-'.uniqid();
    mkdir($this->storage.'/app', 0777, true);
    mkdir($this->storage.'/logs', 0777, true);
    mkdir($this->storage.'/framework/cache', 0777, true);
    $this->originalStorage = storage_path();
    app()->useStoragePath($this->storage);
});

afterEach(function () {
    app()->useStoragePath($this->originalStorage);
    Illuminate\Support\Facades\File::deleteDirectory($this->storage);
});

function chromeUpBrowser($mock, bool $expectStart): void
{
    $mock->shouldReceive('checkRequirements')->andReturn(['ok' => true, 'missing' => []]);
    $mock->shouldReceive('writeExtensionDefaults')->andReturnNull();
    $mock->shouldReceive('updatesOverHttps')->andReturn(true);
    $mock->shouldReceive('status')->andReturn([
        'running' => true, 'chrome' => true, 'extension' => true, 'configured' => true,
        'signed_in' => true, 'posts_to' => '', 'page' => 'Account Overview at Menards® - Google Chrome',
    ]);
    $mock->shouldReceive('start')->times($expectStart ? 1 : 0)->andReturn(['ok' => true]);
    $mock->shouldIgnoreMissing();
}

function chromeInstall(bool $replaced, array $versions = ['installed' => '157.0.8103.78', 'candidate' => '157.0.8103.78']): void
{
    $install = Mockery::mock(ChromeInstall::class);
    $install->shouldReceive('runningBinaryReplaced')->andReturn($replaced);
    $install->shouldReceive('versions')->andReturn($versions);
    app()->instance(ChromeInstall::class, $install);
}

it('recognises a running Chrome whose program an update replaced', function () {
    expect(ChromeInstall::isReplacedChrome('/opt/google/chrome/chrome (deleted)'))->toBeTrue()
        ->and(ChromeInstall::isReplacedChrome('/opt/google/chrome/chrome'))->toBeFalse()
        ->and(ChromeInstall::isReplacedChrome('/usr/bin/php8.3 (deleted)'))->toBeFalse()
        ->and(ChromeInstall::isReplacedChrome(false))->toBeFalse();
});

it('reads the installed and offered Chrome from apt', function () {
    $policy = "google-chrome-stable:\n  Installed: 151.0.7922.173-1\n  Candidate: 157.0.8103.50-1\n  Version table:\n     157.0.8103.50-1 500\n";

    expect(ChromeInstall::parsePolicy($policy))->toBe(['installed' => '151.0.7922.173', 'candidate' => '157.0.8103.50'])
        ->and(ChromeInstall::majorsBehind(ChromeInstall::parsePolicy($policy)))->toBe(6)
        ->and(ChromeInstall::parsePolicy("google-chrome-stable:\n  Installed: (none)\n  Candidate: 157.0.8103.50-1\n")['installed'])->toBeNull()
        ->and(ChromeInstall::parsePolicy(''))->toBe(['installed' => null, 'candidate' => null])
        ->and(ChromeInstall::majorsBehind(['installed' => '157.0.8103.50', 'candidate' => '157.0.8103.78']))->toBe(0)
        ->and(ChromeInstall::majorsBehind(['installed' => null, 'candidate' => '157.0.8103.78']))->toBe(0);
});

it('restarts the browser on the daily pass when Chrome was updated under it', function () {
    Bus::fake();
    Illuminate\Support\Sleep::fake();
    chromeInstall(replaced: true);
    $this->mock(MenardsRemoteBrowserService::class, fn ($mock) => chromeUpBrowser($mock, expectStart: true));

    $this->artisan('menards:browser', ['action' => 'ensure', '--signin' => true])
        ->expectsOutputToContain('Chrome was updated on the server');
});

it('leaves a current browser running', function () {
    Bus::fake();
    chromeInstall(replaced: false);
    $this->mock(MenardsRemoteBrowserService::class, fn ($mock) => chromeUpBrowser($mock, expectStart: false));

    $this->artisan('menards:browser', ['action' => 'ensure', '--signin' => true])
        ->doesntExpectOutputToContain('Chrome was updated on the server');
});

it('never restarts for a Chrome update on a deploy\'s plain ensure', function () {
    Bus::fake();
    chromeInstall(replaced: true);
    $this->mock(MenardsRemoteBrowserService::class, fn ($mock) => chromeUpBrowser($mock, expectStart: false));

    $this->artisan('menards:browser', ['action' => 'ensure'])
        ->doesntExpectOutputToContain('Chrome was updated on the server');
});

it('tells an admin when the weekly check finds Chrome a release behind', function () {
    Bus::fake();
    chromeInstall(replaced: false, versions: ['installed' => '151.0.7922.173', 'candidate' => '157.0.8103.50']);

    $this->artisan('menards:browser', ['action' => 'chrome-check'])
        ->expectsOutputToContain('6 releases behind')
        ->assertSuccessful();

    Bus::assertDispatched(NotifyMenardsBrowserNeedsAttention::class, fn ($job) => $job->reason === 'chrome_outdated'
        && str_contains($job->detail, 'sudo apt-get install --only-upgrade google-chrome-stable'));
});

it('stays quiet when Chrome is current, or only a point release behind', function (array $versions) {
    Bus::fake();
    chromeInstall(replaced: false, versions: $versions);

    $this->artisan('menards:browser', ['action' => 'chrome-check'])->assertSuccessful();

    Bus::assertNotDispatched(NotifyMenardsBrowserNeedsAttention::class);
})->with([
    'current' => [['installed' => '157.0.8103.78', 'candidate' => '157.0.8103.78']],
    'point release behind' => [['installed' => '157.0.8103.50', 'candidate' => '157.0.8103.78']],
    'not installed from apt' => [['installed' => null, 'candidate' => null]],
]);

it('titles the notification', function () {
    $title = (fn () => $this->title())->call(new NotifyMenardsBrowserNeedsAttention('chrome_outdated', 'x'));

    expect($title)->toBe('Chrome on the server is out of date');
});
