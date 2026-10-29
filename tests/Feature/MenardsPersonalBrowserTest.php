<?php

use App\Http\Controllers\MenardsSyncStatusController;
use App\Jobs\ImportMenardsReceiptBatch;
use App\Livewire\Menards\MenardsBrowserViewer;
use App\Models\User;
use App\Models\Vendor;
use App\Services\MenardsRemoteBrowserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The receipt extension now also runs in the owner's own, signed-in Edge or
 * Chrome ("personal browser"), not only the server's Chromium. Imperva sees
 * an ordinary person either way, so a personal delivery is just as good a
 * sign-in proof as the server browser's own — and while one is fresh, the
 * server browser has nothing to prove or repair.
 */
const MENARDS_PERSONAL_STATUS_FILE_PATH = 'app/menards-personal-sync-status.json';

beforeEach(function () {
    // An isolated storage root, exactly as MenardsSigninPauseTest uses for
    // the same reason: the real storage/files/_menards_ingest directory and
    // storage/app/menards-personal-sync-status.json are shared with every
    // OTHER Menards test file, which --parallel runs concurrently in
    // separate processes. A batch dir or status file written to the real
    // path was seen by an unrelated test in a different process mid-run.
    $this->app->useStoragePath(sys_get_temp_dir() . '/menards-personal-test-' . getmypid());
    @mkdir(storage_path('app'), 0775, true);

    Cache::forget(MenardsSyncStatusController::PERSONAL_CACHE_KEY);
    @unlink(storage_path(MENARDS_PERSONAL_STATUS_FILE_PATH));
});

afterEach(function () {
    Cache::forget(MenardsSyncStatusController::PERSONAL_CACHE_KEY);
    @unlink(storage_path(MENARDS_PERSONAL_STATUS_FILE_PATH));
});

/** A batch directory under the real ingest dir, carrying the `personal` marker, mtime set to $deliveredAt. */
function menardsPersonalBatchDir(\Carbon\CarbonInterface $deliveredAt): string
{
    $dir = storage_path('files/_menards_ingest/personal_test_' . uniqid());
    mkdir($dir, 0775, true);
    touch($dir . '/personal');
    touch($dir, $deliveredAt->getTimestamp());

    return $dir;
}

function menardsPersonalCleanupBatchDir(string $dir): void
{
    @unlink($dir . '/personal');
    @rmdir($dir);
}

/** An admin of the vendor the shared Menards account belongs to — passes the real auth/registered/vendor.access middleware stack, not just a Livewire authorize(). */
function menardsPersonalAdmin(): User
{
    $vendor = Vendor::factory()->create(['business_name' => 'Personal Browser Co']);
    $vendor->forceFill(['registration' => ['registered' => true]])->save();
    config(['services.menards.owner_vendor_id' => $vendor->id]);

    $user = new User();
    $user->forceFill([
        'first_name' => 'Personal',
        'last_name' => 'Admin',
        'email' => 'menards-personal-admin-' . uniqid() . '@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'password' => null,
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return $user;
}

/** A different company's admin — same role, wrong vendor; the Gate refuses it. */
function menardsPersonalOutsider(): User
{
    $vendor = Vendor::factory()->create(['business_name' => 'Outsider Co']);
    $vendor->forceFill(['registration' => ['registered' => true]])->save();

    $user = new User();
    $user->forceFill([
        'first_name' => 'Outsider',
        'last_name' => 'User',
        'email' => 'menards-personal-outsider-' . uniqid() . '@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'password' => null,
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return $user;
}

// ── Ingest: personal batches are marked and logged ─────────────────────────

it('marks a batch personal only when the extension says so', function (bool $personal, string $expectedSource, bool $expectMarker) {
    Queue::fake();
    config(['services.menards.bridge_token' => 'bridge-secret']);

    $payload = [
        'receipts' => [[
            'date' => 'March 20, 2026',
            'amount' => 12.34,
            'transactionId' => 'txn-' . ($personal ? 'personal' : 'server'),
            'pdfBase64' => base64_encode("%PDF-1.4\n%fake"),
        ]],
    ];

    if ($personal) {
        $payload['personal'] = true;
    }

    $this->withToken('bridge-secret')->postJson('/api/menards/receipts', $payload)->assertStatus(202);

    $capturedDir = null;
    Queue::assertPushed(ImportMenardsReceiptBatch::class, function ($job) use (&$capturedDir) {
        $capturedDir = $job->directory;

        return true;
    });

    try {
        expect(is_file($capturedDir . '/personal'))->toBe($expectMarker);

        $manifest = json_decode(file_get_contents($capturedDir . '/manifest.json'), true);
        expect($manifest['source'])->toBe($expectedSource);
    } finally {
        array_map('unlink', glob($capturedDir . '/*') ?: []);
        @rmdir($capturedDir);
    }
})->with([
    'personal browser' => [true, 'personal-browser', true],
    'server browser (default)' => [false, 'browser-extension', false],
]);

it('logs the batch source for a personal-browser delivery', function () {
    Queue::fake();
    config(['services.menards.bridge_token' => 'bridge-secret']);
    $spy = Mockery::spy();
    Log::shouldReceive('channel')->with('menards')->andReturn($spy);

    $this->withToken('bridge-secret')->postJson('/api/menards/receipts', [
        'personal' => true,
        'receipts' => [[
            'date' => 'March 20, 2026',
            'amount' => 12.34,
            'transactionId' => 'txn-log-source',
            'pdfBase64' => base64_encode("%PDF-1.4\n%fake"),
        ]],
    ])->assertStatus(202);

    $capturedDir = null;
    Queue::assertPushed(ImportMenardsReceiptBatch::class, function ($job) use (&$capturedDir) {
        $capturedDir = $job->directory;

        return true;
    });

    $spy->shouldHaveReceived('info')->with(
        'Menards ingest: batch received',
        Mockery::on(fn ($context) => ($context['source'] ?? null) === 'personal-browser')
    )->once();

    if ($capturedDir && is_dir($capturedDir)) {
        array_map('unlink', glob($capturedDir . '/*') ?: []);
        @rmdir($capturedDir);
    }
});

// ── Status: a personal report never drives the server browser's own state ──

it('records a personal-browser report separately and never touches the server browser\'s sign-in machinery', function () {
    Queue::fake();
    config(['services.menards.bridge_token' => 'bridge-secret']);
    Cache::put(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY, ['reason' => 'challenge', 'at' => now()->toIso8601String()], 600);
    Cache::put(MenardsRemoteBrowserService::SYNC_IN_FLIGHT_KEY, now()->toIso8601String(), now()->addMinutes(5));

    $this->withToken('bridge-secret')->postJson('/api/menards/sync-status', [
        'ok' => false,
        'error' => '/main/my-account/receipt-lookup/initialize.ajx -> HTTP 401',
        'personal' => true,
        'browser' => 'Microsoft Edge 156',
    ])->assertOk();

    // A 401 would normally queue a resync — never for a personal report.
    Queue::assertNothingPushed();

    expect(Cache::has(MenardsSyncStatusController::CACHE_KEY))->toBeFalse()
        ->and(Cache::has(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY))->toBeTrue()
        ->and(Cache::has(MenardsRemoteBrowserService::SYNC_IN_FLIGHT_KEY))->toBeTrue();

    $personal = Cache::get(MenardsSyncStatusController::PERSONAL_CACHE_KEY);
    expect($personal)->toMatchArray(['ok' => false, 'personal' => true, 'browser' => 'Microsoft Edge 156']);

    $path = storage_path(MENARDS_PERSONAL_STATUS_FILE_PATH);
    expect(is_file($path))->toBeTrue();
    expect(json_decode(file_get_contents($path), true))->toMatchArray(['ok' => false, 'personal' => true, 'browser' => 'Microsoft Edge 156']);
});

it('writes a healthy personal report that a deploy\'s cache clear cannot erase', function () {
    config(['services.menards.bridge_token' => 'bridge-secret']);

    $this->withToken('bridge-secret')->postJson('/api/menards/sync-status', [
        'ok' => true,
        'receipts' => 2,
        'personal' => true,
        'browser' => 'Microsoft Edge 156',
    ])->assertOk();

    Cache::flush();

    $browser = new MenardsRemoteBrowserService();
    expect($browser->personalStatus())->toMatchArray(['ok' => true, 'receipts' => 2, 'personal' => true, 'browser' => 'Microsoft Edge 156']);
});

// ── `menards:browser sync` stands down for a fresh personal delivery ───────

it('stands down `menards:browser sync` when its own browser delivered within the last day', function () {
    $dir = menardsPersonalBatchDir(now()->subHour());

    $this->mock(MenardsRemoteBrowserService::class, function ($mock) {
        $mock->shouldReceive('status')->never();
        $mock->shouldReceive('requestSync')->never();
    });

    try {
        $this->artisan('menards:browser', ['action' => 'sync'])
            ->expectsOutputToContain('the server browser stands down')
            ->assertSuccessful();
    } finally {
        menardsPersonalCleanupBatchDir($dir);
    }
});

it('does not stand down `menards:browser sync` once the personal batch is 30 hours old', function () {
    $dir = menardsPersonalBatchDir(now()->subHours(30));
    Queue::fake();

    $this->mock(MenardsRemoteBrowserService::class, function ($mock) {
        $mock->shouldReceive('status')->once()->andReturn([
            'running' => false, 'chrome' => false, 'extension' => false,
            'configured' => false, 'signed_in' => false, 'posts_to' => '', 'page' => '',
        ]);
    });

    try {
        $this->artisan('menards:browser', ['action' => 'sync'])
            ->doesntExpectOutputToContain('the server browser stands down')
            ->assertFailed();
    } finally {
        menardsPersonalCleanupBatchDir($dir);
    }
});

it('lets `menards:browser ensure` skip sign-in and still call itself healthy while its own browser is fresh', function () {
    $dir = menardsPersonalBatchDir(now()->subHours(2));

    $this->mock(MenardsRemoteBrowserService::class, function ($mock) {
        $mock->shouldReceive('checkRequirements')->andReturn(['ok' => true, 'missing' => []]);
        $mock->shouldReceive('writeExtensionDefaults')->andReturnNull();
        $mock->shouldReceive('updatesOverHttps')->andReturn(true);
        $mock->shouldReceive('status')->andReturn([
            'running' => true, 'chrome' => true, 'extension' => true, 'configured' => true,
            'signed_in' => false, 'posts_to' => '', 'page' => 'Sign In at Menards® - Google Chrome',
        ]);
        $mock->shouldReceive('login')->never();
    });

    try {
        $this->artisan('menards:browser', ['action' => 'ensure', '--signin' => true])
            ->expectsOutputToContain('the server browser stands down')
            ->assertSuccessful();
    } finally {
        menardsPersonalCleanupBatchDir($dir);
    }
});

// ── Keep-alive self-heal: no parked tab after a Chrome restart ─────────────

it('parks a tab on Account Overview and pings again when keepalive finds no tab to use', function () {
    $logger = Mockery::spy();
    Log::shouldReceive('channel')->with('menards')->andReturn($logger);

    $this->mock(MenardsRemoteBrowserService::class, function ($mock) {
        $mock->shouldReceive('looksLikeChallengeWall')->andReturn(false);
        $mock->shouldReceive('keepSessionAlive')->twice()->andReturn(
            ['ok' => false, 'stage' => 'find_tab', 'error' => 'No tab is parked on a signed-in Menards page.'],
            ['ok' => true, 'stage' => 'called', 'status' => 200, 'json' => true],
        );
        $mock->shouldReceive('parkOnAccountOverview')->once()->andReturn(true);
        $mock->shouldReceive('status')->never();
    });

    $this->artisan('menards:browser', ['action' => 'keepalive'])
        ->expectsOutputToContain('Session alive')
        ->assertSuccessful();

    $logger->shouldHaveReceived('info')->with('Menards keep-alive: parked a tab on Account Overview')->once();
});

it('leaves it to the sync when parking lands on the sign-in page or the wall', function () {
    $logger = Mockery::spy();
    Log::shouldReceive('channel')->with('menards')->andReturn($logger);

    $this->mock(MenardsRemoteBrowserService::class, function ($mock) {
        $mock->shouldReceive('looksLikeChallengeWall')->andReturn(false);
        $mock->shouldReceive('keepSessionAlive')->once()->andReturn(
            ['ok' => false, 'stage' => 'find_tab', 'error' => 'No tab is parked on a signed-in Menards page.'],
        );
        $mock->shouldReceive('parkOnAccountOverview')->once()->andReturn(false);
        $mock->shouldReceive('status')->once()->andReturn(['page' => 'Sign In at Menards® - Google Chrome']);
    });

    $this->artisan('menards:browser', ['action' => 'keepalive'])
        ->expectsOutputToContain('Keep-alive failed')
        ->assertSuccessful();

    // A second, different warning also fires below (the keepalive state
    // changed to 'error') — this asserts only the one this test is about.
    $logger->shouldHaveReceived('warning')->with(
        'Menards keep-alive: parking did not land on a signed-in Menards page — leaving it to the sync',
        Mockery::any()
    )->once();
});

// ── After-click screenshot: suffixed so the two can be told apart ──────────

it('names the after-click screenshot distinctly from the one taken before the click', function () {
    Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::parse('2026-10-28 10:00:00'));

    $browser = Mockery::mock(MenardsRemoteBrowserService::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $method = new ReflectionMethod(MenardsRemoteBrowserService::class, 'challengeScreenshotPath');
    $method->setAccessible(true);

    expect($method->invoke($browser))->toBe(storage_path('app/menards-wall-20261028-100000.png'))
        ->and($method->invoke($browser, '-after-click'))->toBe(storage_path('app/menards-wall-20261028-100000-after-click.png'));

    Illuminate\Support\Carbon::setTestNow();
});

it('takes a second, suffixed screenshot only when the checkbox click did not clear the wall', function () {
    config(['services.menards.challenge_click' => '313,391']);
    $title = 'menards.com/main/login.html - Google Chrome';
    $browser = Mockery::mock(MenardsRemoteBrowserService::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $browser->shouldReceive('click')->once();
    $browser->shouldReceive('windowTitle')->andReturnUsing(function () use (&$title) {
        return $title;
    });
    $browser->shouldReceive('waitForTitle')->once()->andReturn(false);
    $browser->shouldReceive('captureChallengeScreenshot')->once()->with('-after-click');

    $method = new ReflectionMethod(MenardsRemoteBrowserService::class, 'clickChallengeCheckbox');
    $method->setAccessible(true);

    expect($method->invoke($browser))->toBeFalse();
});

// ── Install card: the zip is the primary path, the .reg policy is secondary ─

it('zips the extension source for Load unpacked, without defaults.json, tests, or dotfiles', function () {
    $response = $this->actingAs(menardsPersonalAdmin())->get('/menards/extension-source.zip');
    $response->assertOk();

    // A BinaryFileResponse streams on send(); the test client never calls
    // send(), so the temp file deleteFileAfterSend() targets is still here —
    // read the file it points at, as MenardsExtensionUpdateTest does.
    $zipPath = $response->getFile()->getPathname();

    $zip = new ZipArchive();
    expect($zip->open($zipPath))->toBeTrue();

    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();
    @unlink($zipPath);

    expect($names)->toContain('hive-menards-extension/manifest.json')
        ->and($names)->toContain('hive-menards-extension/background.js')
        ->and($names)->not->toContain('hive-menards-extension/defaults.json');

    foreach ($names as $name) {
        expect($name)->not->toContain('/tests/');
    }
});

it('refuses the extension zip to a non-admin', function () {
    $this->actingAs(menardsPersonalOutsider())->get('/menards/extension-source.zip')->assertForbidden();
});

it('sends a guest to log in instead of the extension zip', function () {
    $this->get('/menards/extension-source.zip')->assertStatus(302);
});

it('serves the registry file with the extension id and the https update url for an admin', function () {
    config(['services.menards.update_secret' => 'ext-secret', 'app.url' => 'https://hive.test']);
    $this->mock(MenardsRemoteBrowserService::class, fn ($mock) => $mock->shouldReceive('extensionId')->andReturn('abcdefghijklmnopabcdefghijklmnop'));

    $response = $this->actingAs(menardsPersonalAdmin())->get('/menards/extension-policy.reg');

    $response->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="hive-menards-extension-policy.reg"');

    expect($response->getContent())
        ->toContain('Windows Registry Editor Version 5.00')
        ->toContain('HKEY_LOCAL_MACHINE\SOFTWARE\Policies\Microsoft\Edge\ExtensionInstallForcelist')
        ->toContain('HKEY_LOCAL_MACHINE\SOFTWARE\Policies\Google\Chrome\ExtensionInstallForcelist')
        ->toContain('abcdefghijklmnopabcdefghijklmnop;https://hive.test/menards-extension/ext-secret/update.xml');
});

it('refuses the registry file to a non-admin', function () {
    $this->actingAs(menardsPersonalOutsider())->get('/menards/extension-policy.reg')->assertForbidden();
});

it('404s the registry file when the extension id or the update secret is unknown', function (array $config, ?string $extensionId) {
    config($config);
    $this->mock(MenardsRemoteBrowserService::class, fn ($mock) => $mock->shouldReceive('extensionId')->andReturn($extensionId));

    $this->actingAs(menardsPersonalAdmin())->get('/menards/extension-policy.reg')->assertNotFound();
})->with([
    'no extension id' => [['services.menards.update_secret' => 'ext-secret'], null],
    'no update secret' => [['services.menards.update_secret' => ''], 'abcdefghijklmnopabcdefghijklmnop'],
]);

it('shows the install-your-own-browser card with the last-delivered status', function () {
    config(['services.menards.chromium_binary' => '/usr/bin/chromium']);
    Cache::put(MenardsSyncStatusController::PERSONAL_CACHE_KEY, [
        'ok' => true, 'receipts' => 4, 'browser' => 'Microsoft Edge 156', 'personal' => true, 'at' => now()->toIso8601String(),
    ], 600);

    Livewire::actingAs(menardsPersonalAdmin())
        ->test(MenardsBrowserViewer::class)
        ->assertSee('Run the receipt extension in your own browser')
        ->assertSee('Download the extension')
        ->assertSee('Microsoft Edge 156')
        ->assertSee('If your PC is in a Windows domain');
});

it('shows "never yet" when the owner\'s browser has not delivered, and keeps the bridge token out of the page until revealed', function () {
    config(['services.menards.chromium_binary' => '/usr/bin/chromium', 'services.menards.bridge_token' => 'super-secret-token']);

    $viewer = Livewire::actingAs(menardsPersonalAdmin())->test(MenardsBrowserViewer::class)
        ->assertSee('never yet')
        ->assertDontSee('super-secret-token');

    $viewer->call('revealBridgeToken')->assertSee('super-secret-token');
});
