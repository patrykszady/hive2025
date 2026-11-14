<?php

use App\Events\TeamPhoneRinging;
use App\Livewire\TeamPhone;
use App\Models\CallLog;
use App\Models\User;
use App\Models\UserVoiceCredential;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// =========================================================================
// One login, phone and computer both ring (Patryk, 2026-11-14). A browser
// signed in with a person's Telnyx calling login took the ring for it, and
// Telnyx never woke their iPhone (call 3126). Browsers now stay signed out:
// Hive tells them a call is ringing (TeamPhoneRinging), and Answer signs the
// browser in and has Hive ring it into the call (BrowserAnswer), which
// connects at once and stops the person's cell and phone.
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
    Cache::flush();
    $this->travelTo(\Carbon\Carbon::parse('2026-09-23 10:00:00', 'America/Chicago'));

    $this->company = Vendor::withoutGlobalScopes()->find(1) ?? Vendor::query()->forceCreate(['id' => 1, 'business_name' => 'GS Construction']);
    // The roster is exactly these two (the seeded test DB has other vendor-1 users).
    $this->company->users()->detach();
    $this->patryk = User::factory()->create(['first_name' => 'Patryk', 'last_name' => 'Szady', 'cell_phone' => '2249993880', 'primary_vendor_id' => 1]);
    $this->greg = User::factory()->create(['first_name' => 'Grzegorz', 'nickname' => 'Greg', 'last_name' => 'Szady', 'cell_phone' => '8478737217', 'primary_vendor_id' => 1]);
    foreach ([$this->patryk, $this->greg] as $person) {
        $this->company->users()->attach($person->id, ['role_id' => 1, 'is_employed' => 1]);
        UserVoiceCredential::factory()->create(['user_id' => $person->id, 'sip_username' => 'gencred'.strtolower($person->first_name)]);
    }
    $this->company->forceFill(['timezone' => 'America/Chicago', 'options' => (object) [
        'short_name' => 'GS', 'call_recipients' => [$this->patryk->id], 'voicemail_enabled' => true,
    ]])->save();

    $this->patrykCell = '+12249993880';
    $this->patrykLogin = 'sip:gencredpatryk@sip.telnyx.com';
});

/**
 * Fakes Telnyx: each address Hive dials gets the next call_control_id
 * listed for it (the same login is rung twice — for the phone, then for the
 * browser that answered).
 *
 * @param  array<string, list<string>>  $legs
 */
function browserRing_fakeTelnyx(array $legs): void
{
    Http::fake([
        'api.telnyx.com/v2/calls' => function (Request $request) use (&$legs) {
            $next = array_shift($legs[$request['to']]) ?? 'unexpected-leg';

            return Http::response(['data' => ['call_control_id' => $next, 'call_session_id' => 'sess-1', 'call_leg_id' => 'leg-1']], 200);
        },
        'api.telnyx.com/v2/conferences' => Http::response(['data' => ['id' => 'conf-1']], 200),
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok', 'id' => 'conf-1']], 200),
        'exp.host/*' => Http::response(['data' => []], 200),
    ]);
}

function browserRing_incomingCall(): CallLog
{
    return CallLog::factory()->create([
        'call_control_id' => 'incoming-cc',
        'direction' => 'incoming',
        'status' => CallLog::STATUS_ANSWERED,
        'from_number' => '+17735550111',
        'caller_name' => 'Erin Abbey',
        'metadata' => ['tts_complete' => true],
    ]);
}

function browserRing_startRinging(CallLog $callLog): void
{
    test()->postJson('/webhooks/telnyx/voice', ['data' => ['event_type' => 'call.answered', 'record_type' => 'event', 'payload' => [
        'call_control_id' => $callLog->call_control_id,
        'client_state' => base64_encode(json_encode(['action' => 'welcome_or_ring', 'call_log_id' => $callLog->id, 'original_caller' => $callLog->from_number])),
    ]]])->assertSuccessful();
}

/** A webhook for one of the person's legs: $kind is the leg's 'leg' (null for the cell). */
function browserRing_legEvent(string $eventType, string $leg, CallLog $callLog, User $person, ?string $kind, array $payload = []): void
{
    test()->postJson('/webhooks/telnyx/voice', ['data' => ['event_type' => $eventType, 'record_type' => 'event', 'payload' => $payload + [
        'call_control_id' => $leg,
        'client_state' => base64_encode(json_encode(array_filter([
            'action' => 'admin_ring',
            'leg' => $kind,
            'call_log_id' => $callLog->id,
            'incoming_call_control_id' => $callLog->call_control_id,
            'admin_user_id' => $person->id,
        ]))),
    ]]])->assertSuccessful();
}

/** @return list<Request> every dial Hive made to $to, in order */
function browserRing_dialsTo(string $to): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $request) => str_ends_with($request->url(), '/v2/calls') && $request['to'] === $to)
        ->values()->all();
}

function browserRing_phone(User $user): TeamPhone
{
    return Livewire::actingAs($user)->test(TeamPhone::class)->instance();
}

it('tells the person\'s browsers that a business call is ringing them, while their login and cell ring as before', function () {
    Event::fake([TeamPhoneRinging::class]);
    browserRing_fakeTelnyx([$this->patrykCell => ['patryk-cell-leg'], $this->patrykLogin => ['patryk-login-leg']]);
    $callLog = browserRing_incomingCall();

    browserRing_startRinging($callLog);

    expect(browserRing_dialsTo($this->patrykCell))->toHaveCount(1)
        ->and(browserRing_dialsTo($this->patrykLogin))->toHaveCount(1);
    Event::assertDispatched(TeamPhoneRinging::class, fn (TeamPhoneRinging $ring) => $ring->userId === $this->patryk->id
        && $ring->callLogId === $callLog->id
        && $ring->name === 'Erin Abbey'
        && $ring->kind === 'business'
        && $ring->broadcastOn()[0]->name === 'private-App.Models.User.'.$this->patryk->id
        && $ring->broadcastAs() === 'team-phone.ringing');

    // The browser's card asks Hive whether it still rings.
    expect(browserRing_phone($this->patryk)->ringing($callLog->id))->toBe(['ok' => true, 'name' => 'Erin Abbey', 'crew' => false, 'business' => true]);
});

it('answers a business call in the browser: Hive rings the browser in, which connects at once and stops the cell and the phone', function () {
    browserRing_fakeTelnyx([$this->patrykCell => ['patryk-cell-leg'], $this->patrykLogin => ['patryk-login-leg', 'patryk-browser-leg']]);
    $callLog = browserRing_incomingCall();
    browserRing_startRinging($callLog);

    expect(browserRing_phone($this->patryk)->answer($callLog->id))->toBe(['ok' => true, 'error' => null]);

    // The second ring to the login is the browser's: marked so the browser that asked answers it by itself.
    $browserDial = browserRing_dialsTo($this->patrykLogin)[1];
    $state = json_decode(base64_decode($browserDial['client_state']), true);
    expect(collect($browserDial['custom_headers'])->pluck('value', 'name')->all())->toMatchArray(['X-Hive-Outbound-Call' => (string) $callLog->id, 'X-Hive-Call-Log' => (string) $callLog->id])
        ->and($state)->toMatchArray(['action' => 'admin_ring', 'leg' => 'browser', 'call_log_id' => $callLog->id, 'incoming_call_control_id' => 'incoming-cc', 'admin_user_id' => $this->patryk->id])
        ->and($callLog->fresh()->metadata['admin_call_control_ids'])->toBe(['patryk-cell-leg', 'patryk-login-leg', 'patryk-browser-leg']);

    browserRing_legEvent('call.answered', 'patryk-browser-leg', $callLog, $this->patryk, 'browser');

    // Connected straight away — no screening prompt — and the cell and the phone stop ringing.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'patryk-cell-leg/actions/hangup'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'patryk-login-leg/actions/hangup'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'patryk-browser-leg/actions/hangup'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'patryk-browser-leg/actions/speak'));
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/conferences'));
});

it('keeps the caller off voicemail while the browser connects, when the cell and the phone stop ringing first', function () {
    browserRing_fakeTelnyx([$this->patrykCell => ['patryk-cell-leg'], $this->patrykLogin => ['patryk-login-leg', 'patryk-browser-leg']]);
    $callLog = browserRing_incomingCall();
    browserRing_startRinging($callLog);
    browserRing_phone($this->patryk)->answer($callLog->id);

    browserRing_legEvent('call.hangup', 'patryk-cell-leg', $callLog, $this->patryk, null, ['hangup_cause' => 'timeout']);
    browserRing_legEvent('call.hangup', 'patryk-login-leg', $callLog, $this->patryk, 'app', ['hangup_cause' => 'timeout']);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'incoming-cc/actions/gather_using_speak'));
    expect($callLog->fresh()->metadata['admin_call_control_ids'])->toBe(['patryk-browser-leg'])
        ->and(browserRing_phone($this->patryk)->ringing($callLog->id)['ok'])->toBeTrue();
});

it('rings no browser into a call that is not ringing that person, or is over', function () {
    browserRing_fakeTelnyx([$this->patrykCell => ['patryk-cell-leg'], $this->patrykLogin => ['patryk-login-leg']]);
    $callLog = browserRing_incomingCall();
    browserRing_startRinging($callLog);

    // Greg is not being rung for it.
    expect(browserRing_phone($this->greg)->ringing($callLog->id))->toBe(['ok' => false])
        ->and(browserRing_phone($this->greg)->answer($callLog->id)['ok'])->toBeFalse();

    // The caller hung up.
    $callLog->update(['status' => CallLog::STATUS_MISSED]);
    expect(browserRing_phone($this->patryk)->ringing($callLog->id))->toBe(['ok' => false])
        ->and(browserRing_phone($this->patryk)->answer($callLog->id)['ok'])->toBeFalse();

    expect(browserRing_dialsTo($this->patrykLogin))->toHaveCount(1)
        ->and(browserRing_dialsTo('sip:gencredgrzegorz@sip.telnyx.com'))->toBe([]);
});

it('tells the callee\'s browsers about a teammate\'s call in Hive', function () {
    Event::fake([TeamPhoneRinging::class]);
    \Illuminate\Support\Facades\Bus::fake();
    browserRing_fakeTelnyx(['sip:gencredgrzegorz@sip.telnyx.com' => ['greg-login-leg']]);

    $result = app(\App\Services\Voice\InternalCalls::class)->start($this->greg, $this->patryk);

    expect($result['ok'])->toBeTrue();
    Event::assertDispatched(TeamPhoneRinging::class, fn (TeamPhoneRinging $ring) => $ring->userId === $this->patryk->id
        && $ring->callLogId === $result['call_log']->id
        && $ring->name === 'Greg'
        && $ring->kind === 'internal'
        && $ring->crew === false);
    Event::assertNotDispatched(TeamPhoneRinging::class, fn (TeamPhoneRinging $ring) => $ring->userId === $this->greg->id);
});

it('keeps the browser signed out until it answers or calls, and listens for rings from Hive', function () {
    // The component's script travels HTML-encoded in Livewire's effects.
    Livewire::actingAs($this->patryk)->test(TeamPhone::class)
        ->assertSeeHtml('listen(&#039;.team-phone.ringing&#039;')
        ->assertSeeHtml('App.Models.User.'.$this->patryk->id)
        ->assertSeeHtml('async signIn()')
        // init() no longer signs in: it reads the URL and listens, nothing more.
        ->assertDontSeeHtml('readUrl());\n                await this.connect();');
});
