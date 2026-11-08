<?php

use App\Models\CallLog;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// =========================================================================
// Once a click-to-call/direct-dial target leg is bridged, Telnyx's own
// record_start command retags that leg's client_state to action
// 'call_recording' (see handleClickToCallTargetAnswered()) — Telnyx replaces
// a leg's client_state with whatever the last command sent for it, so the
// target's own call.hangup no longer carries 'click_to_call_target_ring'.
// Before the fix this fell through to the generic "standard hangup" branch,
// which only updates a CallLog found by matching call_control_id — the
// column that always holds the CALLER's own leg for these CallLogs, never a
// target's — so it silently did nothing and the caller's leg stayed up
// (call_log 3056/3057, 2026-11-08: 57s of dead air each).
// =========================================================================

beforeEach(function () {
    config([
        'services.telnyx.api_key' => 'test-api-key',
        'services.telnyx.from' => '+12247354200',
        'services.telnyx.public_url' => 'https://example.com',
    ]);
    Vendor::withoutGlobalScopes()->find(1) ?? Vendor::query()->forceCreate(['id' => 1, 'business_name' => 'GS Construction']);
    Http::fake();
});

/** The call.hangup webhook body a leg last tagged 'call_recording' sends — no user_call_control_id, unlike click_to_call_target_ring. */
function callRecordingHangupPayload(string $callControlId, int $callLogId, string $hangupCause = 'normal_clearing'): array
{
    return [
        'data' => [
            'event_type' => 'call.hangup',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => $callControlId,
                'hangup_cause' => $hangupCause,
                'client_state' => base64_encode(json_encode([
                    'action' => 'call_recording',
                    'call_log_id' => $callLogId,
                ])),
            ],
        ],
    ];
}

/**
 * The call.hangup webhook body the user's OWN leg sends — a ring-back leg
 * still tagged with whatever action it last had ('click_to_call',
 * 'click_to_call_waiting', 'click_to_call_failed_tts') or a direct-dial
 * parked leg, which never changes from 'direct_dial'.
 */
function userLegHangupPayload(string $callControlId, string $action, int $callLogId, string $hangupCause = 'normal_clearing'): array
{
    return [
        'data' => [
            'event_type' => 'call.hangup',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => $callControlId,
                'hangup_cause' => $hangupCause,
                'client_state' => base64_encode(json_encode([
                    'action' => $action,
                    'call_log_id' => $callLogId,
                ])),
            ],
        ],
    ];
}

it('hangs up the caller leg and completes the call when a bridged click-to-call target hangs up', function () {
    $callLog = CallLog::factory()->create([
        'direction' => 'outgoing',
        'call_control_id' => 'user-cc-id',
        'status' => CallLog::STATUS_TRANSFERRED,
        'answered_at' => now()->subSeconds(57),
        'metadata' => [
            'type' => 'click_to_call',
            'conference_id' => 'conf-1',
            'target_call_control_ids' => ['target-cc-1'],
            'joined_target_call_control_ids' => ['target-cc-1'],
            'answered_target_call_control_id' => 'target-cc-1',
            'admin_call_control_ids' => ['user-cc-id'],
        ],
    ]);

    $this->postJson('/webhooks/telnyx/voice', callRecordingHangupPayload('target-cc-1', $callLog->id))
        ->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'user-cc-id/actions/hangup'));

    $fresh = $callLog->fresh();
    expect($fresh->status)->toBe(CallLog::STATUS_COMPLETED)
        ->and($fresh->hangup_cause)->toBe('normal_clearing')
        ->and($fresh->duration_seconds)->toBeGreaterThanOrEqual(57)
        ->and($fresh->ended_at)->not->toBeNull();
});

it('does not hang up the caller leg when the first of two connected Call All targets leaves, but does once the last one does', function () {
    $callLog = CallLog::factory()->create([
        'direction' => 'outgoing',
        'call_control_id' => 'user-cc-id',
        'status' => CallLog::STATUS_TRANSFERRED,
        'answered_at' => now()->subSeconds(20),
        'metadata' => [
            'type' => 'click_to_call_multi',
            'conference_id' => 'conf-1',
            'target_call_control_ids' => ['target-cc-1', 'target-cc-2'],
            'joined_target_call_control_ids' => ['target-cc-1', 'target-cc-2'],
            'answered_target_call_control_id' => 'target-cc-1',
            'admin_call_control_ids' => ['user-cc-id'],
        ],
    ]);

    // First connected target leaves — another one is still on the line.
    $this->postJson('/webhooks/telnyx/voice', callRecordingHangupPayload('target-cc-1', $callLog->id))
        ->assertSuccessful();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'user-cc-id/actions/hangup'));
    $afterFirst = $callLog->fresh();
    expect($afterFirst->status)->toBe(CallLog::STATUS_TRANSFERRED)
        ->and($afterFirst->metadata['joined_target_call_control_ids'])->toBe(['target-cc-2']);

    // The last connected target leaves — nobody is left on the call.
    $this->postJson('/webhooks/telnyx/voice', callRecordingHangupPayload('target-cc-2', $callLog->id))
        ->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'user-cc-id/actions/hangup'));
    $afterSecond = $callLog->fresh();
    expect($afterSecond->status)->toBe(CallLog::STATUS_COMPLETED)
        ->and($afterSecond->ended_at)->not->toBeNull();
});

it('leaves an inbound customer call bridged to an admin alone when the customer hangs up, even though it is also tagged call_recording', function () {
    $callLog = CallLog::factory()->create([
        'direction' => 'incoming',
        'call_control_id' => 'customer-cc-id',
        'status' => CallLog::STATUS_TRANSFERRED,
        'answered_at' => now()->subSeconds(90),
        // No 'type' key at all — exactly how inbound CallLogs are created
        // (handleCallInitiated never sets metadata.type) — so the new
        // click-to-call/direct-dial branch must never match this CallLog.
        'metadata' => [
            'admin_call_control_ids' => ['admin-cc-id'],
            'joined_admin_ids' => [42],
            'connected_at' => now()->subSeconds(90)->toDateTimeString(),
        ],
    ]);

    $this->postJson('/webhooks/telnyx/voice', callRecordingHangupPayload('customer-cc-id', $callLog->id))
        ->assertSuccessful();

    // The admin's own leg is never touched by this hangup — Telnyx tears it
    // down on its own side, exactly as before this fix.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'admin-cc-id/actions/hangup'));

    $fresh = $callLog->fresh();
    expect($fresh->status)->toBe(CallLog::STATUS_COMPLETED)
        ->and($fresh->hangup_cause)->toBe('normal_clearing')
        ->and($fresh->duration_seconds)->toBeGreaterThanOrEqual(90);
});

it('hangs up the parked direct-dial leg the same way once its bridged target hangs up', function () {
    $callLog = CallLog::factory()->create([
        'direction' => 'outgoing',
        'call_control_id' => 'parked-cc-1',
        'status' => CallLog::STATUS_TRANSFERRED,
        'answered_at' => now()->subSeconds(57),
        'metadata' => [
            'type' => 'direct_dial',
            'conference_id' => 'conf-1',
            'target_call_control_ids' => ['target-cc-1'],
            'joined_target_call_control_ids' => ['target-cc-1'],
            'answered_target_call_control_id' => 'target-cc-1',
            'admin_call_control_ids' => ['parked-cc-1'],
        ],
    ]);

    $this->postJson('/webhooks/telnyx/voice', callRecordingHangupPayload('target-cc-1', $callLog->id))
        ->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'parked-cc-1/actions/hangup'));

    $fresh = $callLog->fresh();
    expect($fresh->status)->toBe(CallLog::STATUS_COMPLETED)
        ->and($fresh->duration_seconds)->toBeGreaterThanOrEqual(57);
});

// =========================================================================
// The other direction: the CALLER's own leg hangs up while a click-to-call/
// direct-dial CallLog is already bridged (STATUS_TRANSFERRED). Before this
// fix, hangupOutstandingClickToCallTargets() only ran when the CallLog was
// NOT transferred/completed — i.e. only before the bridge — so the still-
// connected target leg(s) were never told to hang up and sat talking to
// silence until Telnyx's own timeout (call_log 3058, 2026-11-08: the caller
// hung up at 00:31:36 but +18478737217 stayed in the conference until
// 00:32:08, 32s of dead air).
// =========================================================================

it('hangs up the connected target leg when the caller hangs up after a ring-back click-to-call bridge', function () {
    $callLog = CallLog::factory()->create([
        'direction' => 'outgoing',
        'call_control_id' => 'user-cc-id',
        'status' => CallLog::STATUS_TRANSFERRED,
        'answered_at' => now()->subSeconds(32),
        'metadata' => [
            'type' => 'click_to_call',
            'conference_id' => 'conf-1',
            'target_call_control_ids' => ['target-cc-1'],
            'joined_target_call_control_ids' => ['target-cc-1'],
            'answered_target_call_control_id' => 'target-cc-1',
            'admin_call_control_ids' => ['user-cc-id'],
        ],
    ]);

    $this->postJson('/webhooks/telnyx/voice', userLegHangupPayload('user-cc-id', 'click_to_call_waiting', $callLog->id))
        ->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'target-cc-1/actions/hangup'));

    $fresh = $callLog->fresh();
    expect($fresh->status)->toBe(CallLog::STATUS_COMPLETED)
        ->and($fresh->hangup_cause)->toBe('normal_clearing')
        ->and($fresh->duration_seconds)->toBeGreaterThanOrEqual(32)
        ->and($fresh->ended_at)->not->toBeNull();
});

it('hangs up the connected target leg when the parked direct-dial leg hangs up after bridging', function () {
    $callLog = CallLog::factory()->create([
        'direction' => 'outgoing',
        'call_control_id' => 'parked-cc-1',
        'status' => CallLog::STATUS_TRANSFERRED,
        'answered_at' => now()->subSeconds(32),
        'metadata' => [
            'type' => 'direct_dial',
            'conference_id' => 'conf-1',
            'target_call_control_ids' => ['target-cc-1'],
            'joined_target_call_control_ids' => ['target-cc-1'],
            'answered_target_call_control_id' => 'target-cc-1',
            'admin_call_control_ids' => ['parked-cc-1'],
        ],
    ]);

    $this->postJson('/webhooks/telnyx/voice', userLegHangupPayload('parked-cc-1', 'direct_dial', $callLog->id))
        ->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'target-cc-1/actions/hangup'));

    $fresh = $callLog->fresh();
    expect($fresh->status)->toBe(CallLog::STATUS_COMPLETED)
        ->and($fresh->hangup_cause)->toBe('normal_clearing')
        ->and($fresh->duration_seconds)->toBeGreaterThanOrEqual(32)
        ->and($fresh->ended_at)->not->toBeNull();
});

it('hangs up both connected targets when the caller leaves a Call All with two answered targets', function () {
    $callLog = CallLog::factory()->create([
        'direction' => 'outgoing',
        'call_control_id' => 'user-cc-id',
        'status' => CallLog::STATUS_TRANSFERRED,
        'answered_at' => now()->subSeconds(10),
        'metadata' => [
            'type' => 'click_to_call_multi',
            'conference_id' => 'conf-1',
            'target_call_control_ids' => ['target-cc-1', 'target-cc-2'],
            'joined_target_call_control_ids' => ['target-cc-1', 'target-cc-2'],
            'answered_target_call_control_id' => 'target-cc-1',
            'admin_call_control_ids' => ['user-cc-id'],
        ],
    ]);

    $this->postJson('/webhooks/telnyx/voice', userLegHangupPayload('user-cc-id', 'click_to_call_waiting', $callLog->id))
        ->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'target-cc-1/actions/hangup'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'target-cc-2/actions/hangup'));

    $fresh = $callLog->fresh();
    expect($fresh->status)->toBe(CallLog::STATUS_COMPLETED)
        ->and($fresh->ended_at)->not->toBeNull();
});

it('keeps the target leg alive when the caller leg leaves a ring-back click-to-call but a teammate pulled in via conference_invite is still on the line', function () {
    // The teammate joined via DTMF "press 9" / "Call all" (joinAdminToConference())
    // and landed in admin_call_control_ids alongside the originating user's own
    // leg — so when the ORIGINATING leg hangs up, the target is still mid-
    // conversation with that teammate and must not be torn down.
    $callLog = CallLog::factory()->create([
        'direction' => 'outgoing',
        'call_control_id' => 'user-cc-id',
        'status' => CallLog::STATUS_TRANSFERRED,
        'answered_at' => now()->subSeconds(45),
        'metadata' => [
            'type' => 'click_to_call',
            'conference_id' => 'conf-1',
            'target_call_control_ids' => ['target-cc-1'],
            'joined_target_call_control_ids' => ['target-cc-1'],
            'answered_target_call_control_id' => 'target-cc-1',
            'admin_call_control_ids' => ['user-cc-id', 'teammate-cc-1'],
            'joined_admin_ids' => [42],
        ],
    ]);

    $this->postJson('/webhooks/telnyx/voice', userLegHangupPayload('user-cc-id', 'click_to_call_waiting', $callLog->id))
        ->assertSuccessful();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'target-cc-1/actions/hangup'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'teammate-cc-1/actions/hangup'));

    $fresh = $callLog->fresh();
    expect($fresh->status)->toBe(CallLog::STATUS_TRANSFERRED)
        ->and($fresh->ended_at)->toBeNull()
        ->and($fresh->metadata['admin_call_control_ids'])->toBe(['teammate-cc-1']);
});

it('keeps the target leg alive when the parked direct-dial leg leaves but a teammate pulled in via conference_invite is still on the line', function () {
    $callLog = CallLog::factory()->create([
        'direction' => 'outgoing',
        'call_control_id' => 'parked-cc-1',
        'status' => CallLog::STATUS_TRANSFERRED,
        'answered_at' => now()->subSeconds(45),
        'metadata' => [
            'type' => 'direct_dial',
            'conference_id' => 'conf-1',
            'target_call_control_ids' => ['target-cc-1'],
            'joined_target_call_control_ids' => ['target-cc-1'],
            'answered_target_call_control_id' => 'target-cc-1',
            'admin_call_control_ids' => ['parked-cc-1', 'teammate-cc-1'],
            'joined_admin_ids' => [42],
        ],
    ]);

    $this->postJson('/webhooks/telnyx/voice', userLegHangupPayload('parked-cc-1', 'direct_dial', $callLog->id))
        ->assertSuccessful();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'target-cc-1/actions/hangup'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'teammate-cc-1/actions/hangup'));

    $fresh = $callLog->fresh();
    expect($fresh->status)->toBe(CallLog::STATUS_TRANSFERRED)
        ->and($fresh->ended_at)->toBeNull()
        ->and($fresh->metadata['admin_call_control_ids'])->toBe(['teammate-cc-1']);
});

it('still only hangs up the still-ringing target when the caller hangs up before any target answers', function () {
    // Unchanged pre-bridge behaviour: the user leg answered the ringback
    // (STATUS_ANSWERED) but no target has picked up yet, so there is no
    // joined_target_call_control_ids and no connected leg to tear down —
    // only the existing hangupOutstandingClickToCallTargets() cleanup runs.
    $callLog = CallLog::factory()->create([
        'direction' => 'outgoing',
        'call_control_id' => 'user-cc-id',
        'status' => CallLog::STATUS_ANSWERED,
        'answered_at' => now()->subSeconds(5),
        'metadata' => [
            'type' => 'click_to_call',
            'target_call_control_ids' => ['target-cc-1'],
            'joined_target_call_control_ids' => [],
        ],
    ]);

    $this->postJson('/webhooks/telnyx/voice', userLegHangupPayload('user-cc-id', 'click_to_call_waiting', $callLog->id))
        ->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'target-cc-1/actions/hangup'));

    $fresh = $callLog->fresh();
    // Status is preserved — never bridged, so never "completed" — only the
    // hangup cause and end time are recorded.
    expect($fresh->status)->toBe(CallLog::STATUS_ANSWERED)
        ->and($fresh->hangup_cause)->toBe('normal_clearing')
        ->and($fresh->ended_at)->not->toBeNull();
});

it('leaves an inbound call bridged to a single admin unchanged when the ADMIN leg hangs up', function () {
    // Regression guard: an admin's own leg on an inbound call is routed
    // through the entirely separate 'admin_screen' action/handleAdminRingHangup()
    // path, never through the click-to-call/direct-dial branches this fix
    // touches, so hanging up here must behave exactly as before — the caller
    // leg is ended once (an existing, unrelated behaviour), with no
    // click-to-call-style target cleanup attempted.
    $admin = \App\Models\User::factory()->create(['cell_phone' => '2249993880']);

    $callLog = CallLog::factory()->create([
        'direction' => 'incoming',
        'call_control_id' => 'incoming-cc',
        'status' => CallLog::STATUS_TRANSFERRED,
        'metadata' => [
            'admin_call_control_ids' => ['admin-cc-1'],
            'joined_admin_ids' => [$admin->id],
            'conference_id' => 'conf-uuid-123',
            'conference_name' => 'call_999_test',
            'connected_at' => now()->subSeconds(90)->toDateTimeString(),
        ],
    ]);

    $this->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.hangup',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'admin-cc-1',
                'hangup_cause' => 'normal_clearing',
                'client_state' => base64_encode(json_encode([
                    'action' => 'admin_screen',
                    'call_log_id' => $callLog->id,
                    'incoming_call_control_id' => 'incoming-cc',
                    'admin_user_id' => $admin->id,
                ])),
            ],
        ],
    ])->assertSuccessful();

    // Existing behaviour: the last team member leaving after a real
    // conversation ends the call for the caller too.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'incoming-cc/actions/hangup'));

    // No click-to-call/direct-dial style target cleanup was ever attempted —
    // nothing in this CallLog's metadata names a target leg to hang up.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'target-cc'));

    $callLog->refresh();
    expect($callLog->metadata['joined_admin_ids'] ?? [])->toBeEmpty()
        ->and($callLog->metadata['admin_call_control_ids'] ?? [])->toBeEmpty();
});
