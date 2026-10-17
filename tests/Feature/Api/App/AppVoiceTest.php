<?php

use App\Models\CallLog;
use App\Models\User;
use App\Models\UserVoiceCredential;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// =========================================================================
// The app's calling endpoints: a sign-in token for its Telnyx SDK, and
// "place a call", which rings the app first and dials the number through
// Hive from the business line (2026-10-16).
// =========================================================================

beforeEach(function () {
    config([
        'scout.driver' => null,
        'services.telnyx.api_key' => 'test-api-key',
        'services.telnyx.from' => '+12247354200',
        'services.telnyx.connection_id' => 'call-control-app',
        'services.telnyx.app_connection_id' => 'app-connection',
    ]);

    Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $this->member = User::factory()->create(['first_name' => 'Patryk', 'cell_phone' => '2249993880', 'primary_vendor_id' => 1]);
    UserVoiceCredential::factory()->create(['user_id' => $this->member->id, 'telnyx_credential_id' => 'cred-1', 'sip_username' => 'gencredpatryk']);
});

it('hands the app a calling sign-in token', function () {
    Http::fake(['api.telnyx.com/v2/telephony_credentials/cred-1/token' => Http::response('jwt.token.value', 200)]);
    Sanctum::actingAs($this->member, ['app']);

    $this->getJson('/api/app/v1/voice/token')
        ->assertOk()
        ->assertExactJson(['token' => 'jwt.token.value', 'sip_username' => 'gencredpatryk', 'caller_id' => '+12247354200']);
});

it('places a call that rings the app, then the number from the business line', function () {
    Http::fake(['api.telnyx.com/*' => Http::response(['data' => ['call_control_id' => 'app-leg']], 200)]);
    Sanctum::actingAs($this->member, ['app']);

    $response = $this->postJson('/api/app/v1/calls', ['to' => '(847) 555-0100'])->assertCreated();

    $callLog = CallLog::find($response->json('call_log_id'));
    expect($response->json('to'))->toBe('+18475550100')
        ->and($callLog->user_id)->toBe($this->member->id)
        ->and($callLog->metadata['user_leg'])->toBe('app');
    Http::assertSent(fn ($request) => $request['to'] === 'sip:gencredpatryk@sip.telnyx.com');
});

it('refuses a number that is not a full phone number, or the business line itself', function () {
    Sanctum::actingAs($this->member, ['app']);

    $this->postJson('/api/app/v1/calls', ['to' => '555'])->assertUnprocessable()->assertJsonValidationErrors('to');
    $this->postJson('/api/app/v1/calls', ['to' => '+12247354200'])->assertUnprocessable()->assertJson(['message' => 'Cannot call the company phone number.']);
});

it('keeps calling to GS Construction\'s team and to app tokens', function () {
    $outsider = User::factory()->create(['primary_vendor_id' => Vendor::factory()->create()->id]);
    Sanctum::actingAs($outsider, ['app']);
    $this->getJson('/api/app/v1/voice/token')->assertForbidden();

    Sanctum::actingAs($this->member, ['something-else']);
    $this->postJson('/api/app/v1/calls', ['to' => '8475550100'])->assertForbidden();
});

it('rings the person\'s cell instead when the app asks, as Expo Go does before the app has its own calling', function () {
    Http::fake(['api.telnyx.com/*' => Http::response(['data' => ['call_control_id' => 'cell-leg']], 200)]);
    Sanctum::actingAs($this->member, ['app']);

    $this->postJson('/api/app/v1/calls', ['to' => '8475550100', 'ring' => 'cell'])
        ->assertCreated()
        ->assertJson(['ring' => 'cell']);

    Http::assertSent(fn ($request) => $request['to'] === '+12249993880');
});
