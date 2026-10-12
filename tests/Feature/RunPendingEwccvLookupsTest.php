<?php

use App\Http\Controllers\EwccvSessionController;
use App\Jobs\LookupEwccvForVendor;
use App\Jobs\RunPendingEwccvLookups;
use App\Models\Vendor;
use App\Models\VendorDoc;
use App\Support\Ewccv\BridgedSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
    Cache::put(EwccvSessionController::CACHE_KEY, [
        'access_token' => str_repeat('t', 120),
        'jwt' => null,
        'state_token' => null,
        'received_at' => now()->subMinutes($minutesAgo)->toIso8601String(),
    ], now()->addHours(12));
}

beforeEach(function () {
    Cache::forget(EwccvSessionController::CACHE_KEY);
    config(['services.ewccv.bypass_key' => null, 'services.ewccv.belongs_to_vendor_id' => 1]);
});

it('counts as pending every active workers comp policy EWCCV is not tracking', function () {
    $never = wcDoc(1);
    $failed = wcDoc(1, ['status' => 'failed', 'reason' => 'recaptcha_failed_ui']);
    $waiting = wcDoc(1, ['status' => 'pending', 'reason' => 'needs_browser_session']);
    $tracking = wcDoc(1, ['status' => 'tracking']);
    $expired = wcDoc(1, null, '-1 day');
    $otherTenant = wcDoc(2);

    expect(RunPendingEwccvLookups::pendingVendorIds(1))->toBe([$never->vendor_id, $failed->vendor_id, $waiting->vendor_id])
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

it('runs the scraper for exactly the pending vendors when a session is at hand, and not at all without one', function () {
    $pending = wcDoc(1);
    wcDoc(1, ['status' => 'tracking']);

    Artisan::shouldReceive('call')->never();
    (new RunPendingEwccvLookups)->handle();

    bridgedSession();
    Artisan::shouldReceive('call')->once()
        ->with('ewccv:scrape-tracking', ['--belongs-to-vendor-id' => 1, '--vendor-id' => [$pending->vendor_id]])
        ->andReturn(0);
    (new RunPendingEwccvLookups)->handle();
});

it('marks a new policy as waiting for a browser session instead of running a lookup that can only fail', function () {
    $doc = wcDoc(1);
    $tracked = wcDoc(1, ['status' => 'tracking', 'checked_at' => '2026-10-01T00:00:00+00:00']);

    Artisan::shouldReceive('call')->never();
    (new LookupEwccvForVendor($doc->id))->handle();
    (new LookupEwccvForVendor($tracked->id))->handle();

    expect($doc->fresh()->options['ewccv'])->toMatchArray(['status' => 'pending', 'reason' => 'needs_browser_session'])
        ->and($doc->fresh()->ewccv_summary['tip'])->toContain('open ewccv.com with the Hive extension')
        ->and($doc->fresh()->ewccv_summary['tracking'])->toBeFalse()
        ->and($tracked->fresh()->options['ewccv']['status'])->toBe('tracking');

    // With a session the lookup runs as before.
    bridgedSession();
    Artisan::shouldReceive('call')->once()->with('ewccv:scrape-tracking', ['--vendor-id' => [$doc->vendor_id], '--belongs-to-vendor-id' => 1])->andReturn(0);
    (new LookupEwccvForVendor($doc->id))->handle();
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

    // Nothing pending: the session is stored, no job.
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
    $held = \Illuminate\Support\Facades\Cache::lock('ewccv:scraper', 60);
    expect($held->get())->toBeTrue();

    $this->artisan('ewccv:scrape-tracking', ['--belongs-to-vendor-id' => 1, '--login-url' => 'https://www.ewccv.com/cvs/login/?t=test'])
        ->expectsOutputToContain('Another EWCCV run is in progress')
        ->assertExitCode(1);

    $held->release();
});
