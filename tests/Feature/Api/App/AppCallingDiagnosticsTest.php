<?php

use App\Models\AppCallingEvent;
use App\Models\CallLog;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// =========================================================================
// The app's on-device calling log (calls placed while suspended,
// backgrounded, or driven from Recents/Siri/the locked screen, where the
// Metro log channel is dead): uploaded here the next time the app can
// reach the server — see App\Services\Calls\AppCallingDiagnostics.
// =========================================================================

beforeEach(function () {
    $this->company = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $this->member = User::factory()->create(['first_name' => 'Patryk', 'cell_phone' => '2249993880', 'primary_vendor_id' => 1]);
});

function diagnosticsEvent(array $attributes = []): array
{
    return array_merge([
        'at' => now()->toIso8601String(),
        'level' => 'info',
        'message' => 'Dialed from Recents while suspended.',
        'context' => ['source' => 'recents'],
        'call_log_id' => null,
        'call_uuid' => 'ABCDE-12345',
    ], $attributes);
}

it('stores events and writes the app-calling log line', function () {
    Sanctum::actingAs($this->member, ['app']);
    $call = CallLog::factory()->create(['vendor_id' => 1]);

    Log::shouldReceive('channel')->with('app-calling')->twice()->andReturnSelf();
    Log::shouldReceive('log')->once()->withArgs(function (string $level, string $message, array $context) use ($call) {
        return $level === 'info'
            && $message === 'Dialed from Recents while suspended.'
            && $context['user_id'] === $this->member->id
            && $context['call_log_id'] === $call->id
            && $context['context'] === ['source' => 'recents'];
    });
    Log::shouldReceive('log')->once()->with('warning', 'App resumed mid-call.', \Mockery::type('array'));

    $response = $this->postJson('/api/app/v1/diagnostics/calling', [
        'events' => [
            diagnosticsEvent(['call_log_id' => $call->id]),
            diagnosticsEvent(['level' => 'warn', 'message' => 'App resumed mid-call.', 'context' => null, 'call_uuid' => null]),
        ],
    ])->assertCreated();

    expect($response->json('stored'))->toBe(2)
        ->and(AppCallingEvent::query()->count())->toBe(2)
        ->and(AppCallingEvent::query()->where('call_log_id', $call->id)->first()->context)->toBe(['source' => 'recents']);
});

it('requires authentication', function () {
    $this->postJson('/api/app/v1/diagnostics/calling', [
        'events' => [diagnosticsEvent()],
    ])->assertUnauthorized();
});

it('rejects more than 200 events in one batch', function () {
    Sanctum::actingAs($this->member, ['app']);

    $this->postJson('/api/app/v1/diagnostics/calling', [
        'events' => array_fill(0, 201, diagnosticsEvent()),
    ])->assertStatus(422)->assertJsonValidationErrors(['events']);

    expect(AppCallingEvent::query()->count())->toBe(0);
});

it('rejects an oversize message', function () {
    Sanctum::actingAs($this->member, ['app']);

    $this->postJson('/api/app/v1/diagnostics/calling', [
        'events' => [diagnosticsEvent(['message' => str_repeat('x', 501)])],
    ])->assertStatus(422)->assertJsonValidationErrors(['events.0.message']);

    expect(AppCallingEvent::query()->count())->toBe(0);
});

it('rejects context over 4KB once JSON-encoded', function () {
    Sanctum::actingAs($this->member, ['app']);

    $this->postJson('/api/app/v1/diagnostics/calling', [
        'events' => [diagnosticsEvent(['context' => ['blob' => str_repeat('x', 4100)]])],
    ])->assertStatus(422)->assertJsonValidationErrors(['events.0.context']);
});

it('stores an event for a call_log_id the uploading user cannot see, with the link dropped', function () {
    Sanctum::actingAs($this->member, ['app']);
    $theirs = CallLog::factory()->create(['vendor_id' => Vendor::factory()->create()->id]);

    $this->postJson('/api/app/v1/diagnostics/calling', [
        'events' => [diagnosticsEvent(['call_log_id' => $theirs->id])],
    ])->assertCreated();

    $stored = AppCallingEvent::query()->sole();
    expect($stored->call_log_id)->toBeNull();
});

it('includes the device timeline on the call detail endpoint', function () {
    Sanctum::actingAs($this->member, ['app']);
    $call = CallLog::factory()->create(['vendor_id' => 1]);
    AppCallingEvent::query()->create([
        'user_id' => $this->member->id,
        'call_log_id' => $call->id,
        'level' => 'error',
        'message' => 'Call failed to connect.',
        'occurred_at' => now()->subMinute(),
        'created_at' => now(),
    ]);

    $response = $this->getJson("/api/app/v1/calls/{$call->id}")->assertOk();

    expect($response->json('data.device_events'))->toHaveCount(1)
        ->and($response->json('data.device_events.0.level'))->toBe('error')
        ->and($response->json('data.device_events.0.message'))->toBe('Call failed to connect.');
});
