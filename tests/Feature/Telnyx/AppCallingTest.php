<?php

use App\Livewire\Vendors\AppCalling;
use App\Models\CallLog;
use App\Models\User;
use App\Models\UserVoiceCredential;
use App\Models\Vendor;
use App\Services\Voice\AppVoiceCredentials;
use App\Services\Voice\ClickToCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// =========================================================================
// Hive Contractors app calling (2026-10-16): each team member's app or
// browser signs in with a Telnyx telephony credential, rings alongside their
// cell for business calls, and places outbound calls through Hive.
// =========================================================================

beforeEach(function () {
    config([
        'scout.driver' => null,
        'services.telnyx.api_key' => 'test-api-key',
        'services.telnyx.from' => '+12247354200',
        'services.telnyx.connection_id' => 'call-control-app',
        'services.telnyx.app_connection_id' => 'app-connection',
        'services.telnyx.public_url' => 'https://example.com',
    ]);
    $this->travelTo(\Carbon\Carbon::parse('2026-09-23 10:00:00', 'America/Chicago'));

    $this->company = Vendor::withoutGlobalScopes()->find(1) ?? Vendor::query()->forceCreate(['id' => 1, 'business_name' => 'GS Construction']);
    $this->patryk = User::factory()->create(['first_name' => 'Patryk', 'cell_phone' => '2249993880', 'primary_vendor_id' => 1]);
    $this->company->forceFill(['timezone' => 'America/Chicago', 'options' => (object) [
        'short_name' => 'GS', 'call_recipients' => [$this->patryk->id], 'voicemail_enabled' => true,
    ]])->save();
});

it('creates a calling login at Telnyx once, finds it again by its tag, and mints tokens for it', function () {
    Http::fake([
        'api.telnyx.com/v2/telephony_credentials/cred-1/token' => Http::response('jwt.token.value', 200),
        'api.telnyx.com/v2/telephony_credentials?*' => Http::response(['data' => []], 200),
        'api.telnyx.com/v2/telephony_credentials' => Http::response(['data' => ['id' => 'cred-1', 'sip_username' => 'gencredpatryk']], 200),
    ]);

    $voice = app(AppVoiceCredentials::class);

    expect($voice->tokenFor($this->patryk))->toBe('jwt.token.value')
        ->and($voice->sipUriFor($this->patryk))->toBe('sip:gencredpatryk@sip.telnyx.com');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/v2/telephony_credentials')
        && $request['connection_id'] === 'app-connection'
        && $request['tag'] === 'hive-app-user-'.$this->patryk->id);

    // A second token reuses the login.
    $voice->tokenFor($this->patryk->fresh());
    expect(UserVoiceCredential::query()->where('user_id', $this->patryk->id)->count())->toBe(1);
});

it('reuses a login another environment already created for the person', function () {
    Http::fake([
        'api.telnyx.com/v2/telephony_credentials?*' => Http::response(['data' => [['id' => 'cred-prod', 'sip_username' => 'gencredfromprod']]], 200),
        'api.telnyx.com/*' => Http::response(['errors' => [['detail' => 'should not create']]], 500),
    ]);

    expect(app(AppVoiceCredentials::class)->credentialFor($this->patryk)->telnyx_credential_id)->toBe('cred-prod');
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

it('places a call that rings the app first, marked so the app answers it by itself', function () {
    UserVoiceCredential::factory()->create(['user_id' => $this->patryk->id, 'sip_username' => 'gencredpatryk']);
    Http::fake(['api.telnyx.com/*' => Http::response(['data' => ['call_control_id' => 'user-leg']], 200)]);

    $result = app(ClickToCall::class)->start($this->patryk, ['(847) 555-0100'], ringApp: true);

    expect($result['ok'])->toBeTrue()
        ->and($result['call_log']->metadata['user_leg'])->toBe('app')
        ->and($result['call_log']->metadata['target_phone'])->toBe('+18475550100');

    Http::assertSent(function ($request) use ($result) {
        $state = json_decode(base64_decode($request['client_state'] ?? ''), true);

        return $request['to'] === 'sip:gencredpatryk@sip.telnyx.com'
            && $request['from'] === '+12247354200'
            && collect($request['custom_headers'])->contains(fn ($header) => $header['name'] === 'X-Hive-Outbound-Call' && $header['value'] === (string) $result['call_log']->id)
            && ($state['action'] ?? null) === 'click_to_call'
            && str_ends_with($request['webhook_url'], '/webhooks/telnyx/voice');
    });
});

it('rings the cell for a call placed from the website, and never calls the business number', function () {
    Http::fake(['api.telnyx.com/*' => Http::response(['data' => ['call_control_id' => 'user-leg']], 200)]);

    $result = app(ClickToCall::class)->start($this->patryk, ['2245551234']);
    expect($result['ok'])->toBeTrue();
    Http::assertSent(fn ($request) => $request['to'] === '+12249993880' && ! isset($request['custom_headers']));

    $own = app(ClickToCall::class)->start($this->patryk, ['+12247354200']);
    expect($own['ok'])->toBeFalse()->and($own['error'])->toBe('Cannot call the company phone number.');
});

it('rings a team member\'s app alongside their cell, and answering the app connects at once and stops the cell', function () {
    UserVoiceCredential::factory()->create(['user_id' => $this->patryk->id, 'sip_username' => 'gencredpatryk']);
    Http::fake([
        'api.telnyx.com/v2/calls' => Http::sequence()
            ->push(['data' => ['call_control_id' => 'cell-leg']])
            ->push(['data' => ['call_control_id' => 'app-leg']]),
        'api.telnyx.com/v2/conferences' => Http::response(['data' => ['id' => 'conf-1']], 200),
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200),
    ]);

    $callLog = CallLog::factory()->create(['call_control_id' => 'incoming-cc', 'status' => CallLog::STATUS_ANSWERED, 'from_number' => '+17735550111', 'caller_name' => 'Erin Abbey']);
    $this->postJson('/webhooks/telnyx/voice', ['data' => ['event_type' => 'call.answered', 'record_type' => 'event', 'payload' => [
        'call_control_id' => 'incoming-cc',
        'client_state' => base64_encode(json_encode(['action' => 'welcome_or_ring', 'call_log_id' => $callLog->id, 'original_caller' => '+17735550111'])),
    ]]])->assertSuccessful();

    // The app leg carries the caller's own number and a Telnyx-clean name:
    // that is what the phone's call screen shows.
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/calls')
        && $request['to'] === 'sip:gencredpatryk@sip.telnyx.com'
        && $request['from'] === '+17735550111'
        && $request['from_display_name'] === 'Erin Abbey'
        && ! isset($request['answering_machine_detection']));
    expect(\Illuminate\Support\Facades\Cache::get('telnyx_app_ringing:'.$this->patryk->id)['call_log_id'] ?? null)->toBe($callLog->id);
    expect($callLog->fresh()->metadata['admin_call_control_ids'])->toBe(['cell-leg', 'app-leg']);

    // Patryk answers in the app (after the caller's welcome finished).
    \Illuminate\Support\Facades\Cache::put("telnyx_tts_complete:{$callLog->id}", true);
    $callLog->update(['metadata' => array_merge($callLog->fresh()->metadata, ['tts_complete' => true])]);
    $this->postJson('/webhooks/telnyx/voice', ['data' => ['event_type' => 'call.answered', 'record_type' => 'event', 'payload' => [
        'call_control_id' => 'app-leg',
        'client_state' => base64_encode(json_encode(['action' => 'admin_ring', 'leg' => 'app', 'call_log_id' => $callLog->id, 'incoming_call_control_id' => 'incoming-cc', 'admin_user_id' => $this->patryk->id])),
    ]]])->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'cell-leg/actions/hangup'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'app-leg/actions/speak'));
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/conferences'));
});

it('opens the app calling page for GS Construction\'s team only, and places calls from it', function () {
    UserVoiceCredential::factory()->create(['user_id' => $this->patryk->id, 'telnyx_credential_id' => 'cred-1', 'sip_username' => 'gencredpatryk']);
    Http::fake([
        'api.telnyx.com/v2/telephony_credentials/cred-1/token' => Http::response('jwt.token.value', 200),
        'api.telnyx.com/*' => Http::response(['data' => ['call_control_id' => 'user-leg']], 200),
    ]);

    $page = Livewire::actingAs($this->patryk)->test(AppCalling::class)->assertOk()->assertSee('Call a number');
    expect($page->instance()->token())->toBe(['ok' => true, 'token' => 'jwt.token.value']);

    $page->set('number', '847-555-0100');
    expect($page->instance()->callOut()['ok'])->toBeTrue();

    $other = User::factory()->create(['primary_vendor_id' => Vendor::factory()->create()->id]);
    Livewire::actingAs($other)->test(AppCalling::class)->assertStatus(404);
});

it('cleans a caller name for Telnyx, and falls back to the business number when the caller\'s is refused', function () {
    expect(\App\Http\Controllers\Api\TelnyxWebhookController::telnyxDisplayName('Patryk Szady (test) <x>'))->toBe('Patryk Szady test x')
        ->and(\App\Http\Controllers\Api\TelnyxWebhookController::telnyxDisplayName('   '))->toBe('Incoming Call');

    UserVoiceCredential::factory()->create(['user_id' => $this->patryk->id, 'sip_username' => 'gencredpatryk']);
    Http::fake([
        'api.telnyx.com/v2/calls' => Http::sequence()
            ->push(['data' => ['call_control_id' => 'cell-leg']])
            ->push(['errors' => [['code' => '10015', 'title' => 'Invalid from']]], 422)
            ->push(['data' => ['call_control_id' => 'app-leg']]),
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200),
    ]);

    $callLog = CallLog::factory()->create(['call_control_id' => 'incoming-cc', 'status' => CallLog::STATUS_ANSWERED, 'from_number' => '+17735550111', 'caller_name' => 'Erin Abbey']);
    $this->postJson('/webhooks/telnyx/voice', ['data' => ['event_type' => 'call.answered', 'record_type' => 'event', 'payload' => [
        'call_control_id' => 'incoming-cc',
        'client_state' => base64_encode(json_encode(['action' => 'welcome_or_ring', 'call_log_id' => $callLog->id, 'original_caller' => '+17735550111'])),
    ]]])->assertSuccessful();

    $appLegs = collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/v2/calls') && str_starts_with((string) $pair[0]['to'], 'sip:'))->map(fn ($pair) => $pair[0]['from'])->values()->all();
    expect($appLegs)->toBe(['+17735550111', '+12247354200'])
        ->and($callLog->fresh()->metadata['admin_call_control_ids'])->toBe(['cell-leg', 'app-leg']);
});
