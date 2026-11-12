<?php

use App\Models\CallLog;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// =========================================================================
// The app's End button also tells Hive (2026-11-12, call_log 3091): the
// SDK's own goodbye to Telnyx was lost with its socket, so the person
// called stayed on a live, recorded line for 72 s after the app hung up.
// POST calls/{call}/hangup ends the call's legs through Call Control
// (App\Services\Calls\AppCallHangup). Telnyx is fully faked — this never
// places or touches a real call.
// =========================================================================

beforeEach(function () {
    config([
        'scout.driver' => null,
        'services.telnyx.api_key' => 'test-api-key',
        'services.telnyx.from' => '+12247354200',
        'services.telnyx.connection_id' => 'call-control-app',
        'services.telnyx.app_connection_id' => 'app-connection',
    ]);
    Http::preventStrayRequests();

    Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $this->member = User::factory()->create(['first_name' => 'Patryk', 'cell_phone' => '2249993880', 'primary_vendor_id' => 1]);
    Sanctum::actingAs($this->member, ['app']);
});

/**
 * @param  array<string, mixed>  $attributes
 * @param  array<string, mixed>  $metadata
 */
function appPlacedCall(User $member, array $attributes = [], array $metadata = []): CallLog
{
    return CallLog::factory()->create($attributes + [
        'vendor_id' => 1,
        'user_id' => $member->id,
        'direction' => 'outgoing',
        'from_number' => '+12247354200',
        'to_number' => '+17735550161',
        'status' => CallLog::STATUS_TRANSFERRED,
        'call_control_id' => 'app-leg',
        'ended_at' => null,
        'metadata' => $metadata + ['type' => 'direct_dial', 'user_leg' => 'app'],
    ]);
}

function hangupUrlFor(string $legId): string
{
    return "https://api.telnyx.com/v2/calls/{$legId}/actions/hangup";
}

/**
 * @param  array<string, mixed>  $body
 */
function fakeTelnyxHangups(array $body = ['data' => ['result' => 'ok']], int $status = 200): void
{
    Http::fake(['api.telnyx.com/*' => Http::response($body, $status)]);
}

it('hangs up the app leg and the person called when the app ends a connected call', function () {
    fakeTelnyxHangups();
    $call = appPlacedCall($this->member, [], [
        'target_call_control_ids' => ['target-leg'],
        'joined_target_call_control_ids' => ['target-leg'],
        'admin_call_control_ids' => ['app-leg'],
    ]);

    $this->postJson("/api/app/v1/calls/{$call->id}/hangup", ['reason' => 'local-hangup'])
        ->assertSuccessful()
        ->assertExactJson(['call_log_id' => $call->id, 'status' => CallLog::STATUS_TRANSFERRED, 'ended' => false, 'legs' => 2]);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->url() === hangupUrlFor('app-leg') && $request->hasHeader('Authorization', 'Bearer test-api-key') && filled($request['command_id']));
    Http::assertSent(fn ($request) => $request->url() === hangupUrlFor('target-leg'));

    // The status, duration and metadata stay the webhook's to write.
    expect($call->fresh()->status)->toBe(CallLog::STATUS_TRANSFERRED)
        ->and($call->fresh()->ended_at)->toBeNull();
});

it('stops the ringing when the app ends the call before anyone answers', function () {
    fakeTelnyxHangups();
    $call = appPlacedCall($this->member, ['status' => CallLog::STATUS_ANSWERED], [
        'target_call_control_id' => 'ringing-leg',
        'target_call_control_ids' => ['ringing-leg', 'second-leg', 'gave-up-leg'],
        'failed_target_call_control_ids' => ['gave-up-leg'],
    ]);

    $this->postJson("/api/app/v1/calls/{$call->id}/hangup")
        ->assertSuccessful()
        ->assertJson(['ended' => false, 'legs' => 3]);

    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => $request->url() === hangupUrlFor('app-leg'));
    Http::assertSent(fn ($request) => $request->url() === hangupUrlFor('ringing-leg'));
    Http::assertSent(fn ($request) => $request->url() === hangupUrlFor('second-leg'));
    Http::assertNotSent(fn ($request) => $request->url() === hangupUrlFor('gave-up-leg'));
});

it('leaves the person called with a teammate who is still on the call', function () {
    fakeTelnyxHangups();
    $call = appPlacedCall($this->member, [], [
        'target_call_control_ids' => ['target-leg'],
        'joined_target_call_control_ids' => ['target-leg'],
        'admin_call_control_ids' => ['app-leg', 'teammate-leg'],
    ]);

    $this->postJson("/api/app/v1/calls/{$call->id}/hangup")
        ->assertSuccessful()
        ->assertJson(['ended' => false, 'legs' => 1]);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === hangupUrlFor('app-leg'));
});

it('closes a direct-dial call cancelled before its leg reached Telnyx, so a late leg cannot dial out', function () {
    fakeTelnyxHangups();
    $call = appPlacedCall($this->member, ['status' => CallLog::STATUS_INITIATED, 'call_control_id' => null]);

    $this->postJson("/api/app/v1/calls/{$call->id}/hangup", ['reason' => 'cancelled'])
        ->assertSuccessful()
        ->assertExactJson(['call_log_id' => $call->id, 'status' => CallLog::STATUS_MISSED, 'ended' => true, 'legs' => 0]);

    Http::assertNothingSent();
    expect($call->fresh()->status)->toBe(CallLog::STATUS_MISSED)
        ->and($call->fresh()->hangup_cause)->toBe('originator_cancel')
        ->and($call->fresh()->ended_at)->not->toBeNull();
});

it('stops ringing the app itself when a ring-back call is cancelled at once', function () {
    fakeTelnyxHangups();
    $call = appPlacedCall($this->member, ['status' => CallLog::STATUS_INITIATED, 'call_control_id' => 'ring-back-leg'], ['type' => 'click_to_call']);

    $this->postJson("/api/app/v1/calls/{$call->id}/hangup", ['reason' => 'cancelled'])
        ->assertSuccessful()
        ->assertJson(['status' => CallLog::STATUS_INITIATED, 'ended' => false, 'legs' => 1]);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === hangupUrlFor('ring-back-leg'));
});

it('does nothing more once the call is already over', function (string $status) {
    fakeTelnyxHangups();
    $call = appPlacedCall($this->member, ['status' => $status, 'ended_at' => now()], ['joined_target_call_control_ids' => ['target-leg']]);

    $this->postJson("/api/app/v1/calls/{$call->id}/hangup")
        ->assertSuccessful()
        ->assertExactJson(['call_log_id' => $call->id, 'status' => $status, 'ended' => true, 'legs' => 0]);

    Http::assertNothingSent();
})->with([CallLog::STATUS_COMPLETED, CallLog::STATUS_MISSED, CallLog::STATUS_FAILED]);

it('answers normally when Telnyx says a leg is already gone', function () {
    fakeTelnyxHangups(['errors' => [['code' => '90018', 'title' => 'Call has already ended']]], 422);
    $call = appPlacedCall($this->member, [], ['joined_target_call_control_ids' => ['target-leg']]);

    $this->postJson("/api/app/v1/calls/{$call->id}/hangup")
        ->assertSuccessful()
        ->assertJson(['ended' => false, 'legs' => 2]);
});

it('only lets the person who placed the call end it', function () {
    fakeTelnyxHangups();
    $teammate = User::factory()->create(['primary_vendor_id' => 1]);
    $theirs = appPlacedCall($teammate);
    $incoming = appPlacedCall($this->member, ['direction' => 'incoming']);

    $this->postJson("/api/app/v1/calls/{$theirs->id}/hangup")->assertForbidden();
    $this->postJson("/api/app/v1/calls/{$incoming->id}/hangup")->assertForbidden();
    $this->postJson('/api/app/v1/calls/999999/hangup')->assertNotFound();

    Http::assertNothingSent();
});

it('refuses a call the app does not place through Hive, and an unknown reason', function () {
    fakeTelnyxHangups();
    $internal = appPlacedCall($this->member, [], ['type' => 'internal_call']);
    $call = appPlacedCall($this->member);

    $this->postJson("/api/app/v1/calls/{$internal->id}/hangup")
        ->assertUnprocessable()
        ->assertJson(['message' => 'This call cannot be ended from the app.']);
    $this->postJson("/api/app/v1/calls/{$call->id}/hangup", ['reason' => 'because'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');

    Http::assertNothingSent();
});

it('keeps the endpoint to GS Construction\'s team and to app tokens', function () {
    fakeTelnyxHangups();
    $call = appPlacedCall($this->member);

    Sanctum::actingAs($this->member, ['something-else']);
    $this->postJson("/api/app/v1/calls/{$call->id}/hangup")->assertForbidden();

    $outsider = User::factory()->create(['primary_vendor_id' => Vendor::factory()->create()->id]);
    $theirCall = appPlacedCall($outsider);
    Sanctum::actingAs($outsider, ['app']);
    $this->postJson("/api/app/v1/calls/{$theirCall->id}/hangup")->assertForbidden();

    Http::assertNothingSent();
});
