<?php

use App\Jobs\LookupEwccvForVendor;
use App\Jobs\RequestEwccvSignInLink;
use App\Jobs\RunPendingEwccvLookups;
use App\Models\Vendor;
use App\Models\VendorDoc;
use App\Services\NylasService;
use App\Support\Ewccv\BridgedSession;
use App\Support\Ewccv\ScrapeLauncher;
use App\Support\Ewccv\ServiceHours;
use App\Support\Ewccv\SignInLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// =========================================================================
// EWCCV is down every night from midnight to 6 am Eastern (Patryk,
// 2026-10-14): nothing searches it, signs in or asks it for a sign-in link
// then, and whatever is waiting runs with the first session after 6 am.
// =========================================================================

function nightDoc(): VendorDoc
{
    $vendor = Vendor::factory()->create();

    return VendorDoc::withoutEvents(fn () => VendorDoc::create([
        'type' => 'workers',
        'vendor_id' => $vendor->id,
        'belongs_to_vendor_id' => 1,
        'number' => 'WC-'.$vendor->id,
        'doc_filename' => 'wc-'.$vendor->id.'.pdf',
        'effective_date' => now()->subMonths(6)->toDateString(),
        'expiration_date' => now()->addMonths(6)->toDateString(),
        'options' => [],
    ]));
}

function eastern(string $time): Carbon
{
    return Carbon::parse($time, ServiceHours::TIMEZONE);
}

beforeEach(function () {
    Storage::fake('local');
    Cache::forget('ewccv:lookup-dispatched');
    Cache::forget('ewccv:sign-in-link-requested');
    config([
        'services.ewccv.service_hours' => true,
        'services.ewccv.bridge_token' => 'bridge-secret',
        'services.ewccv.bypass_key' => null,
        'services.ewccv.belongs_to_vendor_id' => 1,
    ]);
});

it('is closed from midnight to 6 am Eastern, in daylight and standard time alike', function (string $at, bool $closed) {
    expect(ServiceHours::isClosed(eastern($at)))->toBe($closed);
})->with([
    '11:59 pm EDT' => ['2026-10-14 23:59', false],
    'midnight EDT' => ['2026-10-15 00:00', true],
    '5:59 am EDT' => ['2026-10-15 05:59', true],
    '6:00 am EDT' => ['2026-10-15 06:00', false],
    '3:00 am EST' => ['2027-01-12 03:00', true],
    'noon EST' => ['2027-01-12 12:00', false],
]);

it('reads the window in Eastern time whatever zone the moment comes in', function () {
    // 04:30 UTC is 00:30 EDT (closed); 10:30 UTC is 06:30 EDT (open).
    expect(ServiceHours::isClosed(Carbon::parse('2026-10-15 04:30', 'UTC')))->toBeTrue()
        ->and(ServiceHours::isClosed(Carbon::parse('2026-10-15 10:30', 'UTC')))->toBeFalse()
        ->and(ServiceHours::isClosed(Carbon::parse('2026-10-14 23:30', 'America/Chicago')))->toBeTrue();
});

it('names the next 6 am Eastern as the opening', function () {
    expect(ServiceHours::opensAt(eastern('2026-10-15 02:00'))->toDateTimeString())->toBe('2026-10-15 06:00:00')
        ->and(ServiceHours::opensAt(eastern('2026-10-15 18:00'))->toDateTimeString())->toBe('2026-10-16 06:00:00')
        ->and(ServiceHours::closedMessage(eastern('2026-10-15 02:00')))->toContain('6:00 am EDT');
});

it('never closes when the rule is switched off', function () {
    config(['services.ewccv.service_hours' => false]);

    expect(ServiceHours::isClosed(eastern('2026-10-15 03:00')))->toBeFalse();
});

it('tells the extension nothing is waiting overnight and gives it no sign-in link, then reports the work at 6', function () {
    Queue::fake();
    nightDoc();
    $bridge = fn () => $this->withHeader('Authorization', 'Bearer bridge-secret');

    $this->travelTo(eastern('2026-10-15 02:00'));
    $bridge()->getJson('/api/ewccv/pending')
        ->assertOk()
        ->assertJson(['pending' => 0, 'running' => false, 'closed_until' => '2026-10-15T06:00:00-04:00'])
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'midnight to 6:00 am Eastern'));
    $bridge()->getJson('/api/ewccv/sign-in-link')
        ->assertOk()
        ->assertJson(['url' => null, 'requested' => false]);
    Queue::assertNotPushed(RequestEwccvSignInLink::class);

    $this->travelTo(eastern('2026-10-15 06:01'));
    $bridge()->getJson('/api/ewccv/pending')
        ->assertOk()
        ->assertJson(['pending' => 1])
        ->assertJsonMissingPath('closed_until');
});

it('keeps a session handed over overnight but starts no lookup with it', function () {
    Queue::fake();
    nightDoc();
    $this->travelTo(eastern('2026-10-15 02:00'));

    $this->withHeader('Authorization', 'Bearer bridge-secret')
        ->postJson('/api/ewccv/session', ['accessToken' => str_repeat('n', 120)])
        ->assertOk();

    expect(BridgedSession::raw()['access_token'])->toBe(str_repeat('n', 120));
    Queue::assertNotPushed(RunPendingEwccvLookups::class);
});

it('leaves lookups pending overnight instead of starting the scraper', function () {
    $doc = nightDoc();
    Storage::disk('local')->put(BridgedSession::FILE, json_encode([
        'access_token' => str_repeat('t', 120), 'jwt' => null, 'state_token' => null,
        'received_at' => eastern('2026-10-15 01:59')->toIso8601String(),
    ]));
    $this->travelTo(eastern('2026-10-15 02:00'));

    $launcher = $this->mock(ScrapeLauncher::class);
    $launcher->shouldReceive('launch')->never();
    (new RunPendingEwccvLookups)->handle($launcher);
    (new LookupEwccvForVendor($doc->id))->handle($launcher);

    expect($doc->fresh()->options['ewccv'])->toMatchArray(['status' => 'pending', 'reason' => 'needs_browser_session'])
        ->and(RunPendingEwccvLookups::pendingCount(1))->toBe(1);
});

it('runs no EWCCV command and requests no sign-in link overnight', function () {
    Process::fake();
    $this->travelTo(eastern('2026-10-15 02:00'));

    $this->artisan('ewccv:scrape-tracking', ['--belongs-to-vendor-id' => 1])
        ->expectsOutputToContain('Nothing searched')
        ->assertSuccessful();
    $this->artisan('ewccv:sync-subscriptions', ['--belongs-to-vendor-id' => 1])
        ->expectsOutputToContain('Nothing synced')
        ->assertSuccessful();
    expect(SignInLink::request(app(NylasService::class)))->toBeNull();

    Process::assertNothingRan();
});
