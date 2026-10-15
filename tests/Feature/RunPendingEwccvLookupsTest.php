<?php

use App\Http\Controllers\EwccvSessionController;
use App\Jobs\LookupEwccvForVendor;
use App\Jobs\RunPendingEwccvLookups;
use App\Models\Vendor;
use App\Models\VendorDoc;
use App\Support\Ewccv\BridgedSession;
use App\Support\Ewccv\ScrapeLauncher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// =========================================================================
// EWCCV, 2026-10-12: the search credential only ever comes from a person's
// browser (the bridge extension). So a hand-off runs every pending lookup at
// once, a new policy without a session is marked as waiting instead of
// failing, and nothing is spent on captcha solvers.
// =========================================================================

function wcDoc(int $belongsTo, ?array $ewccv = null, string $expires = '+6 months'): VendorDoc
{
    $vendor = Vendor::factory()->create();

    return VendorDoc::withoutEvents(fn () => VendorDoc::create([
        'type' => 'workers',
        'vendor_id' => $vendor->id,
        'belongs_to_vendor_id' => $belongsTo,
        'number' => 'WC-'.$vendor->id,
        'doc_filename' => 'wc-'.$vendor->id.'.pdf',
        'effective_date' => now()->subMonths(6)->toDateString(),
        'expiration_date' => now()->modify($expires)->toDateString(),
        'options' => $ewccv ? ['ewccv' => $ewccv] : [],
    ]));
}

function bridgedSession(int $minutesAgo = 1): void
{
    \Illuminate\Support\Facades\Storage::disk('local')->put(BridgedSession::FILE, json_encode([
        'access_token' => str_repeat('t', 120),
        'jwt' => null,
        'state_token' => null,
        'received_at' => now()->subMinutes($minutesAgo)->toIso8601String(),
    ]));
}

beforeEach(function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    Cache::forget('ewccv:lookup-dispatched');
    config(['services.ewccv.bypass_key' => null, 'services.ewccv.belongs_to_vendor_id' => 1]);
});

it('counts as pending every active workers comp policy EWCCV is not tracking, minus recent settled failures', function () {
    $never = wcDoc(1);
    $failed = wcDoc(1, ['status' => 'failed', 'reason' => 'recaptcha_failed_ui', 'checked_at' => now()->subHour()->toIso8601String()]);
    $waiting = wcDoc(1, ['status' => 'pending', 'reason' => 'needs_browser_session']);
    $noToken = wcDoc(1, ['status' => 'failed', 'reason' => 'no_accessToken', 'checked_at' => now()->subMinutes(5)->toIso8601String()]);
    $tracking = wcDoc(1, ['status' => 'tracking']);
    $expired = wcDoc(1, null, '-1 day');
    $noResultsToday = wcDoc(1, ['status' => 'failed', 'reason' => 'no_results', 'checked_at' => now()->subHours(3)->toIso8601String()]);
    $noResultsOld = wcDoc(1, ['status' => 'failed', 'reason' => 'no_results', 'checked_at' => now()->subHours(30)->toIso8601String()]);
    $otherTenant = wcDoc(2);

    expect(RunPendingEwccvLookups::pendingVendorIds(1))->toBe([$never->vendor_id, $failed->vendor_id, $waiting->vendor_id, $noToken->vendor_id, $noResultsOld->vendor_id])
        ->and(RunPendingEwccvLookups::pendingVendorIds(2))->toBe([$otherTenant->vendor_id]);
});

it('a session fresher than 45 minutes is usable, an older one is not', function () {
    expect(BridgedSession::fresh())->toBeNull()->and(BridgedSession::canSearch())->toBeFalse();

    bridgedSession(44);
    expect(BridgedSession::fresh()['age_minutes'])->toBe(44)->and(BridgedSession::canSearch())->toBeTrue();

    bridgedSession(46);
    expect(BridgedSession::fresh())->toBeNull();

    config(['services.ewccv.bypass_key' => 'nccikey']);
    expect(BridgedSession::canSearch())->toBeTrue();
});

it('starts the scraper for exactly the pending vendors when a session is at hand, and not at all without one', function () {
    $pending = wcDoc(1);
    wcDoc(1, ['status' => 'tracking']);

    $launcher = $this->mock(ScrapeLauncher::class);
    $launcher->shouldReceive('launch')->never();
    (new RunPendingEwccvLookups)->handle($launcher);

    bridgedSession();
    $launcher = $this->mock(ScrapeLauncher::class);
    $launcher->shouldReceive('launch')->once()->with([$pending->vendor_id], 1, [])->andReturn(4242);
    (new RunPendingEwccvLookups)->handle($launcher);
});

it('marks a new policy as waiting for a browser session instead of running a lookup that can only fail', function () {
    $doc = wcDoc(1);
    $tracked = wcDoc(1, ['status' => 'tracking', 'checked_at' => '2026-10-01T00:00:00+00:00']);

    $launcher = $this->mock(ScrapeLauncher::class);
    $launcher->shouldReceive('launch')->never();
    (new LookupEwccvForVendor($doc->id))->handle($launcher);
    (new LookupEwccvForVendor($tracked->id))->handle($launcher);

    expect($doc->fresh()->options['ewccv'])->toMatchArray(['status' => 'pending', 'reason' => 'needs_browser_session'])
        ->and($doc->fresh()->ewccv_summary['tip'])->toContain('Not looked up on EWCCV yet; insurer notices to certificates@ are watched')
        ->and($doc->fresh()->ewccv_summary['tracking'])->toBeFalse()
        ->and($tracked->fresh()->options['ewccv']['status'])->toBe('tracking');

    // With a session the lookup starts as before.
    bridgedSession();
    $launcher = $this->mock(ScrapeLauncher::class);
    $launcher->shouldReceive('launch')->once()->with([$doc->vendor_id], 1)->andReturn(4243);
    (new LookupEwccvForVendor($doc->id))->handle($launcher);
});

it('a session handed over by the extension starts the pending lookups right away', function () {
    Queue::fake();
    config(['services.ewccv.bridge_token' => 'bridge-secret']);
    $pending = wcDoc(1);

    $this->withHeader('Authorization', 'Bearer bridge-secret')
        ->postJson('/api/ewccv/session', ['accessToken' => str_repeat('a', 120)])
        ->assertOk()
        ->assertJson(['ok' => true, 'pending_lookups' => 1]);

    Queue::assertPushed(RunPendingEwccvLookups::class, 1);

    // A second hand-off moments later (a page load) stores the fresher
    // session but does not queue a second run.
    $this->withHeader('Authorization', 'Bearer bridge-secret')
        ->postJson('/api/ewccv/session', ['accessToken' => str_repeat('c', 120)])
        ->assertOk()
        ->assertJson(['pending_lookups' => 1]);
    Queue::assertPushed(RunPendingEwccvLookups::class, 1);
    expect(BridgedSession::raw()['access_token'])->toBe(str_repeat('c', 120));

    // Nothing pending: the session is stored, no job.
    Cache::forget('ewccv:lookup-dispatched');
    $pending->forceFill(['options' => ['ewccv' => ['status' => 'tracking']]])->saveQuietly();
    $this->withHeader('Authorization', 'Bearer bridge-secret')
        ->postJson('/api/ewccv/session', ['accessToken' => str_repeat('b', 120)])
        ->assertOk()
        ->assertJson(['pending_lookups' => 0]);
    Queue::assertPushed(RunPendingEwccvLookups::class, 1);
});

it('leaves captcha solvers out of the scraper unless forced on', function () {
    expect(config('services.ewccv.use_captcha_solvers'))->toBeFalse();

    config(['services.anticaptcha.api_key' => 'ac', 'services.twocaptcha.api_key' => '2c']);
    $this->artisan('ewccv:scrape-tracking', ['--belongs-to-vendor-id' => 1, '--dry-run' => true])
        ->expectsOutputToContain('Captcha solvers off')
        ->assertExitCode(0);
});

it('never runs two scrapers at once: a second run waits, then gives up with a message', function () {
    config(['services.ewccv.lock_wait_seconds' => 0]);
    wcDoc(1);
    $held = new \App\Support\Ewccv\ScraperLock;
    expect($held->acquire())->toBeTrue();

    $this->artisan('ewccv:scrape-tracking', ['--belongs-to-vendor-id' => 1, '--login-url' => 'https://www.ewccv.com/cvs/login/?t=test'])
        ->expectsOutputToContain('Another EWCCV run is in progress')
        ->assertExitCode(1);

    $held->release();
});

it('starts the scraper in a session of its own so no worker, and no deploy, waits on the run', function () {
    // The job is instant, so it needs no long-running queue or timeout.
    expect((new RunPendingEwccvLookups)->queue)->toBeNull()
        ->and((new LookupEwccvForVendor(1))->queue)->toBeNull()
        ->and((new RunPendingEwccvLookups)->timeout ?? null)->toBeNull();

    $line = ScrapeLauncher::commandLine([4, 30], 1);
    expect($line)->toStartWith('nohup setsid ')
        ->toContain("'ewccv:scrape-tracking' '--belongs-to-vendor-id=1' '--vendor-id=4' '--vendor-id=30'")
        ->toContain(escapeshellarg(base_path('artisan')))
        ->toContain('>> '.escapeshellarg(storage_path('logs/ewccv-scraper.log')).' 2>&1 < /dev/null & echo $!');
});

it('drops a spent browser session when the scraper reports it, so the next hand-off runs fresh', function () {
    config(['services.ewccv.lock_wait_seconds' => 0]);
    $doc = wcDoc(1);
    bridgedSession();
    \Illuminate\Support\Facades\Process::fake([
        '*' => \Illuminate\Support\Facades\Process::result(json_encode([
            'success' => [], 'skipped' => [],
            'failed' => [['vendorId' => $doc->vendor_id, 'businessName' => 'X', 'reason' => 'session_expired']],
            'sessionExpired' => true,
        ])),
    ]);

    $this->artisan('ewccv:scrape-tracking', ['--belongs-to-vendor-id' => 1, '--login-url' => 'https://www.ewccv.com/cvs/login/?t=test'])
        ->expectsOutputToContain('bridged EWCCV session is spent')
        ->assertExitCode(0); // a completed run is a success even when vendors failed

    expect(BridgedSession::raw())->toBeNull()
        ->and($doc->fresh()->options['ewccv'])->toMatchArray(['status' => 'failed', 'reason' => 'session_expired'])
        ->and(RunPendingEwccvLookups::pendingVendorIds(1))->toBe([$doc->vendor_id]);
});

it('a spent-session drop leaves a fresher hand-off alone', function () {
    bridgedSession(30);
    $old = BridgedSession::raw()['received_at'];
    BridgedSession::store(['access_token' => str_repeat('n', 120)]);

    BridgedSession::forget($old);
    expect(BridgedSession::raw()['access_token'])->toBe(str_repeat('n', 120));

    BridgedSession::forget(BridgedSession::raw()['received_at']);
    expect(BridgedSession::raw())->toBeNull();
});
