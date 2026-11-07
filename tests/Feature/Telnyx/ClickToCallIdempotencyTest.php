<?php

use App\Models\CallLog;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Voice\ClickToCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// =========================================================================
// Idempotency guard (2026-11-07): a burst of identical click-to-call
// requests — the app's recents-echo bug sending four POSTs for the same tap
// in the same second — must place exactly one call, not one per request.
// =========================================================================

beforeEach(function () {
    config([
        'scout.driver' => null,
        'services.telnyx.api_key' => 'test-api-key',
        'services.telnyx.from' => '+12247354200',
        'services.telnyx.connection_id' => 'call-control-app',
        'services.telnyx.public_url' => 'https://example.com',
    ]);
    $this->travelTo(\Carbon\Carbon::parse('2026-11-07 10:00:00', 'America/Chicago'));

    $this->company = Vendor::withoutGlobalScopes()->find(1) ?? Vendor::query()->forceCreate(['id' => 1, 'business_name' => 'GS Construction']);
    $this->patryk = User::factory()->create(['first_name' => 'Patryk', 'cell_phone' => '2249993880', 'primary_vendor_id' => 1]);
});

it('places exactly one call for four rapid identical requests to the same target', function () {
    Http::fake(['api.telnyx.com/v2/calls' => Http::response(['data' => ['call_control_id' => 'user-leg']], 200)]);

    $results = collect(range(1, 4))->map(fn () => app(ClickToCall::class)->start($this->patryk, ['(847) 555-0100']));

    expect($results->every(fn ($result) => $result['ok'] === true))->toBeTrue();

    // Every request is handed back the same call — the one the first
    // request through the lock created.
    $callLogIds = $results->pluck('call_log')->pluck('id')->unique();
    expect($callLogIds)->toHaveCount(1);

    expect(CallLog::query()->count())->toBe(1);
    Http::assertSentCount(1);
});

it('still creates a new call for a different target', function () {
    Http::fake(['api.telnyx.com/v2/calls' => Http::response(['data' => ['call_control_id' => 'user-leg']], 200)]);

    $first = app(ClickToCall::class)->start($this->patryk, ['(847) 555-0100']);
    $second = app(ClickToCall::class)->start($this->patryk, ['(847) 555-0199']);

    expect($first['ok'])->toBeTrue()->and($second['ok'])->toBeTrue();
    expect($first['call_log']->id)->not->toBe($second['call_log']->id);

    expect(CallLog::query()->count())->toBe(2);
    Http::assertSentCount(2);
});

it('still treats a repeat request as a duplicate past the old fixed 20s window, as long as the call is still ringing', function () {
    Http::fake(['api.telnyx.com/v2/calls' => Http::response(['data' => ['call_control_id' => 'user-leg']], 200)]);

    $first = app(ClickToCall::class)->start($this->patryk, ['(847) 555-0100']);
    expect($first['ok'])->toBeTrue();

    // Past the dedupe guard's old hard-coded 20s window, but still well
    // inside both configured ring timeouts (voice_timeout, default 30s, and
    // click_to_call_timeout, default 45s): the call can legitimately still
    // be ringing here, so a repeat request must keep returning it instead of
    // placing a second real Telnyx call.
    $this->travel(28)->seconds();

    $second = app(ClickToCall::class)->start($this->patryk, ['(847) 555-0100']);
    expect($second['ok'])->toBeTrue();
    expect($second['call_log']->id)->toBe($first['call_log']->id);

    expect(CallLog::query()->count())->toBe(1);
    Http::assertSentCount(1);
});

it('creates a new call once a never-terminated call outlives the marker\'s safety-net ceiling', function () {
    Http::fake(['api.telnyx.com/v2/calls' => Http::response(['data' => ['call_control_id' => 'user-leg']], 200)]);

    $first = app(ClickToCall::class)->start($this->patryk, ['(847) 555-0100']);
    expect($first['ok'])->toBeTrue();

    // Past CallLog::STALE_ACTIVE_MINUTES — the same ceiling `calls:reconcile-stale`
    // already uses to flag a call stuck active with no hangup webhook. This is
    // the marker's safety-net expiry, not the normal dedupe boundary.
    $this->travel(CallLog::STALE_ACTIVE_MINUTES + 1)->minutes();

    $second = app(ClickToCall::class)->start($this->patryk, ['(847) 555-0100']);
    expect($second['ok'])->toBeTrue();
    expect($second['call_log']->id)->not->toBe($first['call_log']->id);

    expect(CallLog::query()->count())->toBe(2);
    Http::assertSentCount(2);
});

it('still creates a new call for the same target once the earlier one has ended', function () {
    Http::fake(['api.telnyx.com/v2/calls' => Http::response(['data' => ['call_control_id' => 'user-leg']], 200)]);

    $first = app(ClickToCall::class)->start($this->patryk, ['(847) 555-0100']);
    expect($first['ok'])->toBeTrue();
    $first['call_log']->update(['status' => CallLog::STATUS_COMPLETED]);

    $second = app(ClickToCall::class)->start($this->patryk, ['(847) 555-0100']);
    expect($second['ok'])->toBeTrue();
    expect($second['call_log']->id)->not->toBe($first['call_log']->id);

    expect(CallLog::query()->count())->toBe(2);
    Http::assertSentCount(2);
});
