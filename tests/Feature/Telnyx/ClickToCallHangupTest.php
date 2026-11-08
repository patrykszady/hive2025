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
