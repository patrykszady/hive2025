<?php

use App\Models\CallLog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Conferencing another GS admin into a live call — from the phone keypad (DTMF
 * "9") and, indirectly, the UI. These pin the safety + survival behaviour that
 * the feature turns on:
 *   - only a GS/admin leg may summon the team (never the external caller);
 *   - an outbound call whose dialed target hangs up KEEPS GOING when a second
 *     GS admin is already on the line.
 */
beforeEach(function () {
    Cache::flush();
    config()->set('services.telnyx.api_key', 'test-key');
    config()->set('services.telnyx.connection_id', 'test-conn');
    config()->set('services.telnyx.from', '+12247354200');

    foreach ([
        'vendors' => function (Blueprint $t) {
            $t->id();
            $t->string('business_name')->nullable();
            $t->json('options')->nullable();
            $t->timestamps();
        },
        'users' => function (Blueprint $t) {
            $t->id();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('cell_phone')->nullable();
            $t->timestamps();
        },
    ] as $table => $schema) {
        if (! Schema::hasTable($table)) {
            Schema::create($table, $schema);
        }
    }

    if (! Schema::hasTable('call_logs')) {
        Schema::create('call_logs', function (Blueprint $t) {
            $t->id();
            $t->string('call_control_id')->nullable()->index();
            $t->string('direction')->nullable();
            $t->string('from_number')->nullable();
            $t->string('to_number')->nullable();
            $t->string('status')->nullable();
            $t->string('hangup_cause')->nullable();
            $t->integer('duration_seconds')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamp('answered_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
    }

    // Fresh HTTP factory so Http::fake/assertSent see this test's requests only.
    app()->forgetInstance('Illuminate\\Http\\Client\\Factory');
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake([
        'api.telnyx.com/v2/calls' => Http::response(['data' => ['call_control_id' => 'invited-admin-cc']], 200),
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200),
    ]);
});

function dtmfWebhook(string $callControlId, string $digit = '9')
{
    return test()->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.dtmf.received',
            'record_type' => 'event',
            'payload' => ['call_control_id' => $callControlId, 'digit' => $digit],
        ],
    ]);
}

/**
 * Both leg-role cases share one conference. The observable that proves the gate
 * fired is the invite throttle key: it is set ONLY after the role gate passes
 * (right before recipients are dialed). So throttle-present ⇒ passed the gate,
 * throttle-absent ⇒ blocked at the gate — independent of dial mechanics, which
 * the existing suite already covers.
 */
function inboundConferenceCall(): CallLog
{
    return CallLog::create([
        'direction' => 'incoming',
        'status' => CallLog::STATUS_TRANSFERRED,
        // On inbound, the callLog's own call_control_id IS the caller's leg.
        'call_control_id' => 'caller-leg',
        'metadata' => [
            'conference_id' => 'conf-1',
            'admin_call_control_ids' => ['admin-leg'],
            'joined_admin_ids' => [999],
        ],
    ]);
}

it('lets a GS admin leg press 9 to summon the team', function () {
    $call = inboundConferenceCall();
    Cache::put('telnyx_bridged:admin-leg', true, now()->addMinutes(10)); // passes the bridged gate

    dtmfWebhook('admin-leg')->assertSuccessful();

    // Passed the role gate → reached the invite step.
    expect(Cache::has("telnyx_invite_throttle:{$call->id}"))->toBeTrue();
});

it('ignores 9 from the external caller leg — the caller cannot summon the team', function () {
    $call = inboundConferenceCall();
    // Bridge the caller leg too, so we test the ROLE gate, not the bridged gate.
    // The fallback lookup resolves the log by call_control_id (= caller-leg),
    // so only the role gate stands between the caller and the whole team.
    Cache::put('telnyx_bridged:caller-leg', true, now()->addMinutes(10));

    dtmfWebhook('caller-leg')->assertSuccessful();

    // Blocked at the role gate → never reached the invite step, nothing dialed.
    expect(Cache::has("telnyx_invite_throttle:{$call->id}"))->toBeFalse();
    Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/v2/calls') && $r->method() === 'POST');
});

it('keeps an outbound call alive when the target drops but a second GS admin is on', function () {
    $call = CallLog::create([
        'direction' => 'outgoing',
        'status' => CallLog::STATUS_TRANSFERRED,
        'call_control_id' => 'user-leg',
        'answered_at' => now()->subMinute(),
        'metadata' => [
            'conference_id' => 'conf-2',
            // The GS user plus a pulled-in admin — two GS legs.
            'admin_call_control_ids' => ['user-leg', 'invited-admin-cc'],
            'joined_target_call_control_ids' => ['target-leg'],
        ],
    ]);

    test()->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.hangup',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'target-leg',
                'hangup_cause' => 'normal_clearing',
                'client_state' => base64_encode(json_encode([
                    'action' => 'click_to_call_target_ring',
                    'call_log_id' => $call->id,
                    'user_call_control_id' => 'user-leg',
                ])),
            ],
        ],
    ])->assertSuccessful();

    $call->refresh();
    expect($call->status)->toBe(CallLog::STATUS_TRANSFERRED)
        ->and($call->ended_at)->toBeNull()
        // The departed target is cleared from the roster.
        ->and($call->metadata['joined_target_call_control_ids'])->toBe([]);
});

it('completes a plain 2-party outbound call when the target hangs up', function () {
    $call = CallLog::create([
        'direction' => 'outgoing',
        'status' => CallLog::STATUS_TRANSFERRED,
        'call_control_id' => 'user-leg',
        'answered_at' => now()->subMinute(),
        'metadata' => [
            'conference_id' => 'conf-3',
            // Only the GS user — no one was pulled in.
            'admin_call_control_ids' => ['user-leg'],
            'joined_target_call_control_ids' => ['target-leg'],
        ],
    ]);

    test()->postJson('/webhooks/telnyx/voice', [
        'data' => [
            'event_type' => 'call.hangup',
            'record_type' => 'event',
            'payload' => [
                'call_control_id' => 'target-leg',
                'hangup_cause' => 'normal_clearing',
                'client_state' => base64_encode(json_encode([
                    'action' => 'click_to_call_target_ring',
                    'call_log_id' => $call->id,
                    'user_call_control_id' => 'user-leg',
                ])),
            ],
        ],
    ])->assertSuccessful();

    $call->refresh();
    expect($call->status)->toBe(CallLog::STATUS_COMPLETED)
        ->and($call->ended_at)->not->toBeNull();
});
