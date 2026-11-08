<?php

use App\Models\CallLog;
use App\Models\User;
use App\Models\UserVoiceCredential;
use App\Models\Vendor;
use App\Services\Voice\DirectDialStarter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// =========================================================================
// Direct dial (2026-11-07): the app's own Telnyx SDK places the call and
// Telnyx parks it (never rings anywhere) instead of Hive ringing the
// person's phone first. DirectDialStarter only logs the CallLog; everything
// from the parked leg's call.initiated onward is
// TelnyxWebhookController::handleParkedDirectDialLeg() and the shared
// click-to-call target handlers it reuses.
// =========================================================================

beforeEach(function () {
    config([
        'scout.driver' => null,
        'services.telnyx.api_key' => 'test-api-key',
        'services.telnyx.from' => '+12247354200',
        'services.telnyx.connection_id' => 'call-control-app',
        'services.telnyx.app_connection_id' => 'app-connection',
        'services.telnyx.direct_dial_enabled' => true,
        'services.telnyx.public_url' => 'https://example.com',
    ]);
    $this->travelTo(\Carbon\Carbon::parse('2026-11-07 10:00:00', 'America/Chicago'));

    $this->company = Vendor::withoutGlobalScopes()->find(1) ?? Vendor::query()->forceCreate(['id' => 1, 'business_name' => 'GS Construction']);
    $this->patryk = User::factory()->create(['first_name' => 'Patryk', 'cell_phone' => '2249993880', 'primary_vendor_id' => 1]);
    UserVoiceCredential::factory()->create(['user_id' => $this->patryk->id, 'telnyx_credential_id' => 'cred-1', 'sip_username' => 'gencredpatryk']);
});

/** @return array<string, mixed> the call.initiated webhook body for a parked app leg. */
function parkedLegPayload(array $payloadOverrides = []): array
{
    return [
        'data' => [
            'event_type' => 'call.initiated',
            'record_type' => 'event',
            'payload' => array_merge([
                'call_control_id' => 'parked-cc-1',
                'direction' => 'outgoing',
                'state' => 'parked',
                'connection_id' => 'app-connection',
                'from' => 'sip:gencredpatryk@sip.telnyx.com',
                'to' => '+18475550100',
            ], $payloadOverrides),
        ],
    ];
}

// =========================================================================
// DirectDialStarter: logs the CallLog only, no Telnyx call.
// =========================================================================

it('logs exactly one CallLog for a burst of identical direct-dial requests, with no Telnyx call', function () {
    Http::fake();

    $results = collect(range(1, 3))->map(fn () => app(DirectDialStarter::class)->start($this->patryk, ['(847) 555-0100']));

    expect($results->every(fn ($result) => $result['ok'] === true))->toBeTrue();
    expect($results->pluck('call_log')->pluck('id')->unique())->toHaveCount(1);
    expect(CallLog::query()->count())->toBe(1);

    $callLog = $results->first()['call_log'];
    expect($callLog->direction)->toBe('outgoing')
        ->and($callLog->status)->toBe(CallLog::STATUS_INITIATED)
        ->and($callLog->from_number)->toBe('+12247354200')
        ->and($callLog->to_number)->toBe('+18475550100')
        ->and($callLog->metadata['type'])->toBe('direct_dial');

    Http::assertNothingSent();
});

// =========================================================================
// Correlating the parked leg.
// =========================================================================

it('correlates the parked leg by client_state and dials the target', function () {
    $callLog = CallLog::create([
        'direction' => 'outgoing', 'from_number' => '+12247354200', 'to_number' => '+18475550100',
        'status' => CallLog::STATUS_INITIATED, 'user_id' => $this->patryk->id, 'vendor_id' => 1,
        'metadata' => ['type' => 'direct_dial', 'target_phone' => '+18475550100', 'target_phones' => ['+18475550100']],
    ]);

    Http::fake(['api.telnyx.com/v2/calls' => Http::response(['data' => ['call_control_id' => 'target-cc-1']], 200)]);

    $clientState = DirectDialStarter::clientStateFor($callLog);
    $this->postJson('/webhooks/telnyx/voice', parkedLegPayload(['client_state' => $clientState]))->assertSuccessful();

    Http::assertSent(function ($request) use ($callLog) {
        if (! str_ends_with($request->url(), '/v2/calls')) {
            return false;
        }
        $state = json_decode(base64_decode($request['client_state'] ?? ''), true);

        return $request['to'] === '+18475550100'
            && $request['from'] === '+12247354200'
            && $request['answering_machine_detection'] === 'premium'
            && ($state['action'] ?? null) === 'click_to_call_target_ring'
            && ($state['user_call_control_id'] ?? null) === 'parked-cc-1'
            && ($state['call_log_id'] ?? null) === $callLog->id;
    });

    $callLog->refresh();
    expect($callLog->status)->toBe(CallLog::STATUS_ANSWERED)
        ->and($callLog->call_control_id)->toBe('parked-cc-1')
        ->and($callLog->metadata['target_call_control_ids'])->toBe(['target-cc-1']);
});

it('correlates the parked leg by the X-Hive-Call-Log header when client_state is missing', function () {
    $callLog = CallLog::create([
        'direction' => 'outgoing', 'from_number' => '+12247354200', 'to_number' => '+18475550100',
        'status' => CallLog::STATUS_INITIATED, 'user_id' => $this->patryk->id, 'vendor_id' => 1,
        'metadata' => ['type' => 'direct_dial', 'target_phone' => '+18475550100', 'target_phones' => ['+18475550100']],
    ]);

    Http::fake(['api.telnyx.com/v2/calls' => Http::response(['data' => ['call_control_id' => 'target-cc-1']], 200)]);

    $this->postJson('/webhooks/telnyx/voice', parkedLegPayload([
        'custom_headers' => [['name' => 'X-Hive-Call-Log', 'value' => (string) $callLog->id]],
    ]))->assertSuccessful();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/calls') && $request['to'] === '+18475550100');
    expect($callLog->fresh()->status)->toBe(CallLog::STATUS_ANSWERED);
});

it('falls back to the newest initiated direct-dial call for the same target and credential owner', function () {
    $callLog = CallLog::create([
        'direction' => 'outgoing', 'from_number' => '+12247354200', 'to_number' => '+18475550100',
        'status' => CallLog::STATUS_INITIATED, 'user_id' => $this->patryk->id, 'vendor_id' => 1,
        'metadata' => ['type' => 'direct_dial', 'target_phone' => '+18475550100', 'target_phones' => ['+18475550100']],
    ]);

    Http::fake(['api.telnyx.com/v2/calls' => Http::response(['data' => ['call_control_id' => 'target-cc-1']], 200)]);

    // Neither client_state nor the custom header survived the round trip —
    // only `from` (the credential) and `to` (the target) are left.
    $this->postJson('/webhooks/telnyx/voice', parkedLegPayload())->assertSuccessful();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/calls') && $request['to'] === '+18475550100');
    expect($callLog->fresh()->status)->toBe(CallLog::STATUS_ANSWERED)
        ->and($callLog->fresh()->call_control_id)->toBe('parked-cc-1');
});

it('never attaches one user\'s parked leg to another user\'s direct-dial call via the fallback', function () {
    $other = User::factory()->create(['first_name' => 'Jordan', 'primary_vendor_id' => 1]);
    UserVoiceCredential::factory()->create(['user_id' => $other->id, 'sip_username' => 'gencredjordan']);

    // The same target number, dialed by a DIFFERENT team member — only
    // Patryk's own credential (payload.from) may correlate to Patryk's leg.
    $othersCallLog = CallLog::create([
        'direction' => 'outgoing', 'from_number' => '+12247354200', 'to_number' => '+18475550100',
        'status' => CallLog::STATUS_INITIATED, 'user_id' => $other->id, 'vendor_id' => 1,
        'metadata' => ['type' => 'direct_dial', 'target_phone' => '+18475550100', 'target_phones' => ['+18475550100']],
    ]);

    Http::fake(['api.telnyx.com/v2/calls' => Http::response(['data' => ['call_control_id' => 'target-cc-1']], 200)]);

    // from = Patryk's own credential, but there is no call log of HIS to find.
    $this->postJson('/webhooks/telnyx/voice', parkedLegPayload())->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'parked-cc-1/actions/hangup'));
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/v2/calls'));
    expect($othersCallLog->fresh()->status)->toBe(CallLog::STATUS_INITIATED)
        ->and($othersCallLog->fresh()->call_control_id)->toBeNull();
});

it('hangs up an uncorrelated parked leg', function () {
    Http::fake();

    $this->postJson('/webhooks/telnyx/voice', parkedLegPayload())->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'parked-cc-1/actions/hangup'));
});

it('hangs up a parked leg even when it correlates, if the flag is off', function () {
    $callLog = CallLog::create([
        'direction' => 'outgoing', 'from_number' => '+12247354200', 'to_number' => '+18475550100',
        'status' => CallLog::STATUS_INITIATED, 'user_id' => $this->patryk->id, 'vendor_id' => 1,
        'metadata' => ['type' => 'direct_dial', 'target_phone' => '+18475550100', 'target_phones' => ['+18475550100']],
    ]);
    config(['services.telnyx.direct_dial_enabled' => false]);
    Http::fake();

    $this->postJson('/webhooks/telnyx/voice', parkedLegPayload(['client_state' => DirectDialStarter::clientStateFor($callLog)]))->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'parked-cc-1/actions/hangup'));
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/v2/calls'));
    expect($callLog->fresh()->status)->toBe(CallLog::STATUS_INITIATED);
});

// =========================================================================
// Target answers: the parked leg is answered, then the shared disclosure +
// recording + conference path runs exactly as it does for a ring-back call.
// =========================================================================

it('answers the parked leg, then plays the disclosure and bridges once the target answers', function () {
    $callLog = CallLog::factory()->create([
        'status' => CallLog::STATUS_ANSWERED,
        'user_id' => $this->patryk->id,
        'answered_at' => now(),
        'metadata' => ['type' => 'direct_dial', 'target_call_control_ids' => ['target-cc-1']],
    ]);
    config(['call_recording.disclosure.enabled' => true, 'call_recording.outbound_disclosure.enabled' => true, 'call_recording.outbound_disclosure.phrase' => 'This call is recorded.']);

    Http::fake([
        'api.telnyx.com/v2/conferences' => Http::response(['data' => ['id' => 'conf-1']], 200),
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200),
    ]);

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => ['event_type' => 'call.answered', 'record_type' => 'event', 'payload' => [
            'call_control_id' => 'target-cc-1',
            'client_state' => base64_encode(json_encode([
                'action' => 'click_to_call_target_ring',
                'call_log_id' => $callLog->id,
                'user_call_control_id' => 'parked-cc-1',
            ])),
        ]],
    ])->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'parked-cc-1/actions/answer'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'target-cc-1/actions/speak') && $request['payload'] === 'This call is recorded.');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/conferences') && $request['call_control_id'] === 'parked-cc-1');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/conferences/conf-1/actions/join') && $request['call_control_id'] === 'target-cc-1');

    expect($callLog->fresh()->status)->toBe(CallLog::STATUS_TRANSFERRED);
});

// =========================================================================
// Hangups, both ways.
// =========================================================================

it('hangs up the still-ringing target when the caller cancels before the target answers, and marks the call missed', function () {
    $callLog = CallLog::factory()->create([
        'status' => CallLog::STATUS_ANSWERED,
        'answered_at' => now(),
        'metadata' => ['type' => 'direct_dial', 'target_call_control_ids' => ['target-cc-1']],
    ]);
    Http::fake();

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => ['event_type' => 'call.hangup', 'record_type' => 'event', 'payload' => [
            'call_control_id' => 'parked-cc-1',
            'hangup_cause' => 'originator_cancel',
            'client_state' => base64_encode(json_encode(['action' => 'direct_dial', 'call_log_id' => $callLog->id])),
        ]],
    ])->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'target-cc-1/actions/hangup'));
    expect($callLog->fresh()->status)->toBe(CallLog::STATUS_MISSED);
});

it('hangs up the parked leg when the target hangs up after the bridge', function () {
    $callLog = CallLog::factory()->create([
        'status' => CallLog::STATUS_TRANSFERRED,
        'answered_at' => now()->subSeconds(20),
        'metadata' => [
            'type' => 'direct_dial',
            'conference_id' => 'conf-1',
            'target_call_control_ids' => ['target-cc-1'],
            'joined_target_call_control_ids' => ['target-cc-1'],
            'answered_target_call_control_id' => 'target-cc-1',
            'admin_call_control_ids' => ['parked-cc-1'],
        ],
    ]);
    Http::fake();

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => ['event_type' => 'call.hangup', 'record_type' => 'event', 'payload' => [
            'call_control_id' => 'target-cc-1',
            'hangup_cause' => 'normal_clearing',
            'client_state' => base64_encode(json_encode([
                'action' => 'click_to_call_target_ring',
                'call_log_id' => $callLog->id,
                'user_call_control_id' => 'parked-cc-1',
            ])),
        ]],
    ])->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'parked-cc-1/actions/hangup'));
    expect($callLog->fresh()->status)->toBe(CallLog::STATUS_COMPLETED);
});

it('ends the parked leg instead of speaking into it when the target does not answer', function () {
    $callLog = CallLog::factory()->create([
        'status' => CallLog::STATUS_ANSWERED,
        'answered_at' => now(),
        'metadata' => ['type' => 'direct_dial', 'target_call_control_ids' => ['target-cc-1']],
    ]);
    Http::fake();

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => ['event_type' => 'call.hangup', 'record_type' => 'event', 'payload' => [
            'call_control_id' => 'target-cc-1',
            'hangup_cause' => 'user_busy',
            'client_state' => base64_encode(json_encode([
                'action' => 'click_to_call_target_ring',
                'call_log_id' => $callLog->id,
                'user_call_control_id' => 'parked-cc-1',
            ])),
        ]],
    ])->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'parked-cc-1/actions/hangup'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'parked-cc-1/actions/speak'));
    expect($callLog->fresh()->status)->toBe(CallLog::STATUS_MISSED);
});

// =========================================================================
// The app connection now reports to the voice webhook. An incoming leg on it
// is a phone's app being rung by Hive itself — never a customer calling.
// =========================================================================

it('leaves an incoming leg on the app connection alone — no answer, no hangup, no CallLog', function () {
    Http::fake();
    $callLogsBefore = \App\Models\CallLog::count();

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.initiated',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'app-ring-leg-1',
                'direction' => 'incoming',
                'state' => 'parked',
                'connection_id' => 'app-connection',
                'from' => '+12247354200',
                'to' => 'sip:gencredpatryk@sip.telnyx.com',
            ],
        ],
    ])->assertSuccessful();

    Http::assertNothingSent();
    expect(\App\Models\CallLog::count())->toBe($callLogsBefore);
});

it('still treats an incoming call on the business number connection as a customer call', function () {
    Http::fake();

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.initiated',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'customer-leg-1',
                'direction' => 'incoming',
                'state' => 'parked',
                'connection_id' => 'call-control-app',
                'from' => '+18475550199',
                'to' => '+12247354200',
            ],
        ],
    ])->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'customer-leg-1'));
});
