<?php

use App\Http\Controllers\Api\TelnyxWebhookController;
use App\Models\CallLog;
use App\Models\PushToken;
use App\Models\User;
use App\Models\UserVoiceCredential;
use App\Models\Vendor;
use App\Services\Voice\AppVoiceCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// =========================================================================
// App only (Patryk, 2026-11-12): a team member who has the Hive Contractors
// app is rung in the app and never on their cell — the app leg is their one
// leg, and the cascade and the voicemail menu hang on it as they did on the
// cell. It replaces "app and cell together" (2026-10-16), which arrived as
// two incoming calls on one iPhone. People without the app ring on their
// cell as before.
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
    Http::preventStrayRequests();
    Cache::flush();
    $this->travelTo(\Carbon\Carbon::parse('2026-09-23 10:00:00', 'America/Chicago'));

    $this->company = Vendor::withoutGlobalScopes()->find(1) ?? Vendor::query()->forceCreate(['id' => 1, 'business_name' => 'GS Construction']);
    $this->patryk = User::factory()->create(['first_name' => 'Patryk', 'cell_phone' => '2249993880', 'primary_vendor_id' => 1]);
    $this->greg = User::factory()->create(['first_name' => 'Greg', 'cell_phone' => '2249991111', 'primary_vendor_id' => 1]);
    $this->company->forceFill(['timezone' => 'America/Chicago', 'options' => (object) [
        'short_name' => 'GS',
        'call_recipients' => [$this->patryk->id, $this->greg->id],
        'voicemail_enabled' => true,
    ]])->save();

    $this->patrykCell = '+12249993880';
    $this->gregCell = '+12249991111';
    $this->patrykApp = 'sip:gencredpatryk@sip.telnyx.com';
    $this->gregApp = 'sip:gencredgreg@sip.telnyx.com';
    $this->dialAttempts = [];
});

/**
 * An iPhone signed in to the app as $user, the way the app leaves it: the
 * phone's own sign-in token and the push registration that hangs on it.
 *
 * @param  array<string, mixed>  $phone  push_tokens overrides
 * @param  array<string, mixed>  $signIn  personal_access_tokens overrides
 */
function appOnly_signInPhone(User $user, array $phone = [], array $signIn = []): PushToken
{
    $token = $user->createToken("{$user->first_name}'s iPhone", ['app'])->accessToken;
    $token->forceFill($signIn + ['last_used_at' => now()])->save();

    return PushToken::factory()->create($phone + ['user_id' => $user->id, 'personal_access_token_id' => $token->id]);
}

/** Gives $user the Hive Contractors app: a calling login and a signed-in iPhone. */
function appOnly_giveApp(User $user, array $phone = [], array $signIn = []): PushToken
{
    UserVoiceCredential::factory()->create(['user_id' => $user->id, 'sip_username' => 'gencred'.strtolower($user->first_name)]);

    return appOnly_signInPhone($user, $phone, $signIn);
}

/**
 * Fakes Telnyx. $legs maps each number or SIP address Hive may dial to the
 * call_control_id the new leg gets — or to an HTTP status Telnyx refuses the
 * dial with, or to false for a connection that never completes. Every dial
 * attempt is noted for appOnly_dialed(), the failed connection included
 * (Http::recorded() never sees that one). Expo is faked too: a missed call
 * notifies the phones these tests register.
 *
 * @param  array<string, string|int|false>  $legs
 */
function appOnly_fakeTelnyx(array $legs): void
{
    Http::fake([
        'api.telnyx.com/v2/calls' => function (Request $request) use ($legs) {
            $outcome = $legs[$request['to']] ?? 'unexpected-leg';
            test()->dialAttempts = [...test()->dialAttempts, (string) $request['to']];

            if ($outcome === false) {
                throw new ConnectionException('Connection timed out');
            }

            return is_int($outcome)
                ? Http::response(['errors' => [['title' => 'Refused']]], $outcome)
                : Http::response(['data' => ['call_control_id' => $outcome]], 200);
        },
        'api.telnyx.com/v2/conferences' => Http::response(['data' => ['id' => 'conf-1']], 200),
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200),
        'exp.host/*' => Http::response(['data' => []], 200),
    ]);
}

/** @return list<string> every number or SIP address Hive tried to dial so far, in order */
function appOnly_dialed(): array
{
    return test()->dialAttempts;
}

/** The request that dialed $to, or null. */
function appOnly_dialTo(string $to): ?Request
{
    return collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->first(fn (Request $request) => str_ends_with($request->url(), '/v2/calls') && $request['to'] === $to);
}

/** @param  array<string, mixed>  $attributes */
function appOnly_incomingCall(array $attributes = []): CallLog
{
    return CallLog::factory()->create($attributes + [
        'call_control_id' => 'incoming-cc',
        'direction' => 'incoming',
        'status' => CallLog::STATUS_ANSWERED,
        'from_number' => '+17735550111',
        'caller_name' => 'Erin Abbey',
    ]);
}

/** Hive answered the caller: the greeting plays and the team starts ringing. */
function appOnly_startRinging(CallLog $callLog): void
{
    test()->postJson('/webhooks/telnyx/voice', ['data' => ['event_type' => 'call.answered', 'record_type' => 'event', 'payload' => [
        'call_control_id' => $callLog->call_control_id,
        'client_state' => base64_encode(json_encode(['action' => 'welcome_or_ring', 'call_log_id' => $callLog->id, 'original_caller' => $callLog->from_number])),
    ]]])->assertSuccessful();
}

/**
 * A webhook for one of a team member's legs, carrying the client_state Hive
 * dialed it with ($appLeg: the app leg's, which says so).
 *
 * @param  array<string, mixed>  $payload
 */
function appOnly_legEvent(string $eventType, string $leg, CallLog $callLog, User $person, bool $appLeg = true, array $payload = []): void
{
    test()->postJson('/webhooks/telnyx/voice', ['data' => ['event_type' => $eventType, 'record_type' => 'event', 'payload' => $payload + [
        'call_control_id' => $leg,
        'client_state' => base64_encode(json_encode(array_filter([
            'action' => 'admin_ring',
            'leg' => $appLeg ? 'app' : null,
            'call_log_id' => $callLog->id,
            'incoming_call_control_id' => $callLog->call_control_id,
            'admin_user_id' => $person->id,
        ]))),
    ]]])->assertSuccessful();
}

/** Whether the caller was given the voicemail menu. */
function appOnly_voicemailPlayed(): bool
{
    return collect(Http::recorded())->contains(fn ($pair) => str_contains($pair[0]->url(), 'incoming-cc/actions/gather_using_speak'));
}

// ── Who has the app ──────────────────────────────────────────────────────

it('takes someone to have the app only while an iPhone of theirs is signed in, switched on and lately heard from', function (Closure $arrange, bool $hasApp) {
    $arrange($this->patryk);

    expect(app(AppVoiceCredentials::class)->hasRingableApp($this->patryk->fresh()))->toBe($hasApp);
})->with([
    'an iPhone signed in to the app' => [fn (User $user) => appOnly_giveApp($user), true],
    'a calling login alone (the browser phone, the test page, an internal call)' => [fn (User $user) => UserVoiceCredential::factory()->create(['user_id' => $user->id]), false],
    'a signed-in phone without a calling login' => [fn (User $user) => appOnly_signInPhone($user), false],
    'a phone switched off on the users page' => [fn (User $user) => appOnly_giveApp($user, ['enabled' => false]), false],
    'an Android phone, which a call cannot wake' => [fn (User $user) => appOnly_giveApp($user, ['platform' => PushToken::PLATFORM_ANDROID]), false],
    'an admin viewing as them on the admin\'s own phone (Login As)' => [fn (User $user) => appOnly_giveApp($user, [], ['name' => 'app-login-as-by-1', 'expires_at' => now()->addHours(8)]), false],
    'a registration whose sign-in is gone' => [fn (User $user) => appOnly_giveApp($user)->forceFill(['personal_access_token_id' => null])->save(), false],
    'a phone not heard from in over a week' => [fn (User $user) => appOnly_giveApp($user, ['last_seen_at' => now()->subDays(8)], ['last_used_at' => now()->subDays(8)]), false],
    'a phone that registered long ago and used the app yesterday' => [fn (User $user) => appOnly_giveApp($user, ['last_seen_at' => now()->subDays(30)], ['last_used_at' => now()->subDay()]), true],
    'a phone that registered yesterday and has asked for nothing since' => [fn (User $user) => appOnly_giveApp($user, ['last_seen_at' => now()->subDay()], ['last_used_at' => null]), true],
    'a dead phone beside a live one' => [function (User $user) {
        appOnly_giveApp($user, ['last_seen_at' => now()->subDays(40)], ['last_used_at' => now()->subDays(40)]);
        appOnly_signInPhone($user);
    }, true],
]);

it('takes nobody to have the app while app calling is not configured', function () {
    appOnly_giveApp($this->patryk);
    config(['services.telnyx.app_connection_id' => null]);

    expect(app(AppVoiceCredentials::class)->hasRingableApp($this->patryk))->toBeFalse();
});

it('has the app from the phone\'s registration until it signs out', function () {
    UserVoiceCredential::factory()->create(['user_id' => $this->patryk->id]);
    $signIn = $this->patryk->createToken("Patryk's iPhone", ['app'])->plainTextToken;
    $voice = app(AppVoiceCredentials::class);

    expect($voice->hasRingableApp($this->patryk))->toBeFalse();

    $this->withToken($signIn)->postJson('/api/app/v1/push-tokens', ['token' => 'ExponentPushToken[patryks-iphone]', 'platform' => 'ios'])->assertCreated();
    expect($voice->hasRingableApp($this->patryk))->toBeTrue();

    $this->withToken($signIn)->postJson('/api/app/v1/logout')->assertSuccessful();
    expect($voice->hasRingableApp($this->patryk))->toBeFalse();
});

// ── The cascade ──────────────────────────────────────────────────────────

it('rings someone who has the app in the app only, and never dials their cell', function () {
    appOnly_giveApp($this->patryk);
    appOnly_fakeTelnyx([$this->patrykApp => 'patryk-app-leg']);

    $callLog = appOnly_incomingCall();
    appOnly_startRinging($callLog);

    expect(appOnly_dialed())->toBe([$this->patrykApp]);

    $dial = appOnly_dialTo($this->patrykApp);
    $state = json_decode(base64_decode($dial['client_state']), true);
    expect($dial['from'])->toBe('+17735550111')
        ->and($dial['from_display_name'])->toBe('Erin Abbey')
        ->and($dial['timeout_secs'])->toBe(TelnyxWebhookController::RING_SECONDS)
        ->and(isset($dial['answering_machine_detection']))->toBeFalse()
        ->and($state)->toMatchArray([
            'action' => 'admin_ring',
            'leg' => 'app',
            'call_log_id' => $callLog->id,
            'incoming_call_control_id' => 'incoming-cc',
            'admin_user_id' => $this->patryk->id,
        ]);

    $metadata = $callLog->fresh()->metadata;
    expect($metadata['admin_call_control_ids'])->toBe(['patryk-app-leg'])
        ->and($metadata['cascade_pending_ids'])->toBe([$this->greg->id])
        ->and($metadata['admin_dial_count'])->toBe(1)
        ->and(Cache::get('telnyx_app_ringing:'.$this->patryk->id))->toMatchArray(['call_log_id' => $callLog->id, 'app_call_control_id' => 'patryk-app-leg'])
        ->and(appOnly_voicemailPlayed())->toBeFalse();
});

it('rings someone without the app on their cell exactly as before', function () {
    appOnly_fakeTelnyx([$this->patrykCell => 'patryk-cell-leg']);

    $callLog = appOnly_incomingCall();
    appOnly_startRinging($callLog);

    expect(appOnly_dialed())->toBe([$this->patrykCell]);

    $dial = appOnly_dialTo($this->patrykCell);
    $state = json_decode(base64_decode($dial['client_state']), true);
    expect($dial['from'])->toBe('+12247354200')
        ->and($dial['timeout_secs'])->toBe(TelnyxWebhookController::RING_SECONDS)
        ->and($dial['answering_machine_detection'])->toBe('premium')
        ->and($state['action'])->toBe('admin_ring')
        ->and($state)->not->toHaveKey('leg');

    $metadata = $callLog->fresh()->metadata;
    expect($metadata['admin_call_control_ids'])->toBe(['patryk-cell-leg'])
        ->and($metadata['cascade_pending_ids'])->toBe([$this->greg->id]);
});

it('still rings the browser phone alongside the cell of someone with a calling login and no app, and answering one stops the other', function () {
    UserVoiceCredential::factory()->create(['user_id' => $this->patryk->id, 'sip_username' => 'gencredpatryk']);
    appOnly_fakeTelnyx([$this->patrykCell => 'patryk-cell-leg', $this->patrykApp => 'patryk-browser-leg']);

    $callLog = appOnly_incomingCall(['metadata' => ['tts_complete' => true]]);
    appOnly_startRinging($callLog);

    expect(appOnly_dialed())->toBe([$this->patrykCell, $this->patrykApp])
        ->and($callLog->fresh()->metadata['admin_call_control_ids'])->toBe(['patryk-cell-leg', 'patryk-browser-leg']);

    appOnly_legEvent('call.answered', 'patryk-browser-leg', $callLog, $this->patryk);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'patryk-cell-leg/actions/hangup'));
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/conferences'));
});

it('rings the cell, with the app alongside as before, of someone whose phone has not been heard from in over a week', function () {
    appOnly_giveApp($this->patryk, ['last_seen_at' => now()->subDays(9)], ['last_used_at' => now()->subDays(9)]);
    appOnly_fakeTelnyx([$this->patrykCell => 'patryk-cell-leg', $this->patrykApp => 'patryk-app-leg']);

    appOnly_startRinging(appOnly_incomingCall());

    expect(appOnly_dialed())->toBe([$this->patrykCell, $this->patrykApp]);
});

it('moves on to the next person when the app leg ends unanswered', function (string $hangupCause) {
    appOnly_giveApp($this->patryk);
    appOnly_fakeTelnyx([$this->patrykApp => 'patryk-app-leg', $this->gregCell => 'greg-cell-leg']);

    $callLog = appOnly_incomingCall();
    appOnly_startRinging($callLog);
    appOnly_legEvent('call.hangup', 'patryk-app-leg', $callLog, $this->patryk, payload: ['hangup_cause' => $hangupCause]);

    expect(appOnly_dialed())->toBe([$this->patrykApp, $this->gregCell])
        ->and(appOnly_voicemailPlayed())->toBeFalse();

    $metadata = $callLog->fresh()->metadata;
    expect($metadata['admin_call_control_ids'])->toBe(['greg-cell-leg'])
        ->and($metadata['cascade_pending_ids'])->toBe([])
        ->and($metadata['admin_dial_count'])->toBe(2);
})->with([
    'nobody answered within the ring window' => 'timeout',
    'they declined in the app' => 'call_rejected',
    'the app could not be reached' => 'normal_temporary_failure',
]);

it('rings the next person in their app too when they have it', function () {
    appOnly_giveApp($this->patryk);
    appOnly_giveApp($this->greg);
    appOnly_fakeTelnyx([$this->patrykApp => 'patryk-app-leg', $this->gregApp => 'greg-app-leg']);

    $callLog = appOnly_incomingCall();
    appOnly_startRinging($callLog);
    appOnly_legEvent('call.hangup', 'patryk-app-leg', $callLog, $this->patryk, payload: ['hangup_cause' => 'timeout']);

    expect(appOnly_dialed())->toBe([$this->patrykApp, $this->gregApp])
        ->and($callLog->fresh()->metadata['admin_call_control_ids'])->toBe(['greg-app-leg'])
        ->and(appOnly_voicemailPlayed())->toBeFalse();
});

it('sends the caller to voicemail when the last person\'s app is not answered', function () {
    appOnly_giveApp($this->patryk);
    appOnly_giveApp($this->greg);
    appOnly_fakeTelnyx([$this->patrykApp => 'patryk-app-leg', $this->gregApp => 'greg-app-leg']);

    $callLog = appOnly_incomingCall();
    appOnly_startRinging($callLog);
    appOnly_legEvent('call.hangup', 'patryk-app-leg', $callLog, $this->patryk, payload: ['hangup_cause' => 'timeout']);
    expect(appOnly_voicemailPlayed())->toBeFalse();

    appOnly_legEvent('call.hangup', 'greg-app-leg', $callLog, $this->greg, payload: ['hangup_cause' => 'timeout']);

    expect(appOnly_voicemailPlayed())->toBeTrue()
        ->and(appOnly_dialed())->toBe([$this->patrykApp, $this->gregApp])
        ->and($callLog->fresh()->metadata['admin_call_control_ids'])->toBe([]);
});

it('connects the caller the moment the app is answered, with no prompt and no other leg to stop', function () {
    appOnly_giveApp($this->patryk);
    appOnly_fakeTelnyx([$this->patrykApp => 'patryk-app-leg']);

    $callLog = appOnly_incomingCall(['metadata' => ['tts_complete' => true]]);
    appOnly_startRinging($callLog);
    appOnly_legEvent('call.answered', 'patryk-app-leg', $callLog, $this->patryk);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/conferences') && $request['call_control_id'] === 'incoming-cc');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'conferences/conf-1/actions/join') && $request['call_control_id'] === 'patryk-app-leg');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/actions/hangup'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'patryk-app-leg/actions/speak'));

    $callLog->refresh();
    expect($callLog->status)->toBe(CallLog::STATUS_TRANSFERRED)
        ->and($callLog->metadata['conference_id'])->toBe('conf-1')
        ->and($callLog->metadata['joined_admin_ids'])->toBe([$this->patryk->id])
        ->and(appOnly_dialed())->toBe([$this->patrykApp])
        ->and(appOnly_voicemailPlayed())->toBeFalse();
});

it('stops the app ringing when the caller gives up first', function () {
    appOnly_giveApp($this->patryk);
    appOnly_fakeTelnyx([$this->patrykApp => 'patryk-app-leg']);

    $callLog = appOnly_incomingCall();
    appOnly_startRinging($callLog);

    $this->postJson('/webhooks/telnyx/voice', ['data' => ['event_type' => 'call.hangup', 'record_type' => 'event', 'payload' => [
        'call_control_id' => 'incoming-cc',
        'hangup_cause' => 'originator_cancel',
    ]]])->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'patryk-app-leg/actions/hangup'));
    expect(appOnly_dialed())->toBe([$this->patrykApp]);
});

// ── The one fallback: Telnyx will not dial the app leg ───────────────────

it('falls back to the cell when Telnyx will not dial the app leg', function (int|false $refusal, int $appAttempts) {
    appOnly_giveApp($this->patryk);
    appOnly_fakeTelnyx([$this->patrykApp => $refusal, $this->patrykCell => 'patryk-cell-leg']);

    $callLog = appOnly_incomingCall();
    appOnly_startRinging($callLog);

    expect(appOnly_dialed())->toBe([...array_fill(0, $appAttempts, $this->patrykApp), $this->patrykCell])
        ->and(appOnly_dialTo($this->patrykCell)['answering_machine_detection'])->toBe('premium')
        ->and(appOnly_voicemailPlayed())->toBeFalse()
        ->and(Cache::has('telnyx_app_ringing:'.$this->patryk->id))->toBeFalse();

    $metadata = $callLog->fresh()->metadata;
    expect($metadata['admin_call_control_ids'])->toBe(['patryk-cell-leg'])
        ->and($metadata['cascade_pending_ids'])->toBe([$this->greg->id])
        ->and($metadata['admin_dial_count'])->toBe(1);
})->with([
    'a Telnyx server error' => [500, 1],
    'refused from the caller\'s number and from the business number' => [422, 2],
    'a connection that never completes' => [false, 1],
]);

it('retries the app leg from the business number when the caller\'s number is refused as caller ID, without touching the cell', function () {
    appOnly_giveApp($this->patryk);
    Http::fake([
        'api.telnyx.com/v2/calls' => Http::sequence()
            ->push(['errors' => [['code' => '10015', 'title' => 'Invalid from']]], 422)
            ->push(['data' => ['call_control_id' => 'patryk-app-leg']]),
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200),
    ]);

    $callLog = appOnly_incomingCall();
    appOnly_startRinging($callLog);

    $dials = collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $request) => str_ends_with($request->url(), '/v2/calls'))->values();
    expect($dials->map(fn (Request $request) => $request['to'])->all())->toBe([$this->patrykApp, $this->patrykApp])
        ->and($dials->map(fn (Request $request) => $request['from'])->all())->toBe(['+17735550111', '+12247354200'])
        ->and($callLog->fresh()->metadata['admin_call_control_ids'])->toBe(['patryk-app-leg']);
});

it('rings the cell when Hive cannot tell whether someone has the app', function () {
    appOnly_giveApp($this->patryk);
    appOnly_fakeTelnyx([$this->patrykCell => 'patryk-cell-leg', $this->patrykApp => 'patryk-app-leg']);
    $this->partialMock(AppVoiceCredentials::class, fn ($voice) => $voice->shouldReceive('hasRingableApp')->andThrow(new RuntimeException('The database went away')));

    $callLog = appOnly_incomingCall();
    appOnly_startRinging($callLog);

    expect(appOnly_dialed())->toBe([$this->patrykCell, $this->patrykApp])
        ->and($callLog->fresh()->metadata['admin_call_control_ids'])->toBe(['patryk-cell-leg', 'patryk-app-leg']);
});

// ── Everyone at once, ring again, and 9 ──────────────────────────────────

it('rings app people in the app and everyone else on their cell when a vendor rings everyone at once', function () {
    $this->company->forceFill(['options' => (object) array_merge((array) $this->company->options, [
        'vendor_call_recipients' => [$this->greg->id, $this->patryk->id],
        'vendor_call_ring_all' => true,
    ])])->save();
    $sub = Vendor::withoutSyncingToSearch(fn () => Vendor::factory()->create(['business_name' => 'Smart Tech Electric', 'business_phone' => '8472123894']));
    $this->company->vendors()->attach($sub->id);

    appOnly_giveApp($this->greg);
    appOnly_fakeTelnyx([$this->gregApp => 'greg-app-leg', $this->patrykCell => 'patryk-cell-leg']);

    $callLog = appOnly_incomingCall(['from_number' => '+18472123894', 'caller_name' => 'Smart Tech Electric']);
    appOnly_startRinging($callLog);

    expect(appOnly_dialed())->toBe([$this->gregApp, $this->patrykCell]);

    $metadata = $callLog->fresh()->metadata;
    expect($metadata['admin_call_control_ids'])->toBe(['greg-app-leg', 'patryk-cell-leg'])
        ->and($metadata['cascade_pending_ids'])->toBe([]);

    // Greg's app gives up while Patryk's cell still rings: nothing new is
    // dialed (least of all Greg's cell) and the caller keeps waiting.
    appOnly_legEvent('call.hangup', 'greg-app-leg', $callLog, $this->greg, payload: ['hangup_cause' => 'timeout']);

    expect(appOnly_dialed())->toBe([$this->gregApp, $this->patrykCell])
        ->and(appOnly_voicemailPlayed())->toBeFalse()
        ->and($callLog->fresh()->metadata['admin_call_control_ids'])->toBe(['patryk-cell-leg']);

    appOnly_legEvent('call.hangup', 'patryk-cell-leg', $callLog, $this->patryk, appLeg: false, payload: ['hangup_cause' => 'timeout']);

    expect(appOnly_voicemailPlayed())->toBeTrue()
        ->and(appOnly_dialed())->toBe([$this->gregApp, $this->patrykCell]);
});

it('rings the app only again when the caller presses 1 at the voicemail menu to try the team once more', function () {
    appOnly_giveApp($this->patryk);
    appOnly_fakeTelnyx([$this->patrykApp => 'patryk-app-leg-2']);

    $callLog = appOnly_incomingCall(['metadata' => ['admin_call_control_ids' => [], 'cascade_pending_ids' => [], 'joined_admin_ids' => [], 'tts_complete' => true]]);

    $this->postJson('/webhooks/telnyx/voice', ['data' => ['event_type' => 'call.speak.ended', 'record_type' => 'event', 'payload' => [
        'call_control_id' => 'incoming-cc',
        'client_state' => base64_encode(json_encode(['action' => 'ivr_retry_connect', 'call_log_id' => $callLog->id, 'original_caller' => '+17735550111'])),
    ]]])->assertSuccessful();

    expect(appOnly_dialed())->toBe([$this->patrykApp])
        ->and($callLog->fresh()->metadata['admin_call_control_ids'])->toBe(['patryk-app-leg-2']);
});

it('invites someone who has the app in the app when 9 is pressed on an incoming call, and joins them when they answer', function () {
    $alex = User::factory()->create(['first_name' => 'Alex', 'cell_phone' => '2249992222', 'primary_vendor_id' => 1]);
    $this->company->forceFill(['options' => (object) array_merge((array) $this->company->options, [
        'call_recipients' => [$this->patryk->id, $this->greg->id, $alex->id],
    ])])->save();

    appOnly_giveApp($this->greg);
    appOnly_fakeTelnyx([$this->gregApp => 'greg-app-leg', '+12249992222' => 'alex-cell-leg']);

    $callLog = appOnly_incomingCall(['status' => CallLog::STATUS_TRANSFERRED, 'metadata' => [
        'admin_call_control_ids' => ['patryk-leg'],
        'joined_admin_ids' => [$this->patryk->id],
        'conference_id' => 'conf-live',
        'tts_complete' => true,
    ]]);
    Cache::put('telnyx_bridged:patryk-leg', true, now()->addMinutes(10));

    $this->postJson('/webhooks/telnyx/voice', ['data' => ['event_type' => 'call.dtmf.received', 'record_type' => 'event', 'payload' => [
        'call_control_id' => 'patryk-leg',
        'digit' => '9',
    ]]])->assertSuccessful();

    expect(appOnly_dialed())->toEqualCanonicalizing([$this->gregApp, '+12249992222'])
        ->and($callLog->fresh()->metadata['admin_call_control_ids'])->toEqualCanonicalizing(['patryk-leg', 'greg-app-leg', 'alex-cell-leg']);

    appOnly_legEvent('call.answered', 'greg-app-leg', $callLog, $this->greg);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'conferences/conf-live/actions/join') && $request['call_control_id'] === 'greg-app-leg');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'greg-app-leg/actions/speak'));
    expect($callLog->fresh()->metadata['joined_admin_ids'])->toEqualCanonicalizing([$this->patryk->id, $this->greg->id]);
});

it('falls back to the cell for a 9 invite when Telnyx will not dial the app leg', function () {
    appOnly_giveApp($this->greg);
    appOnly_fakeTelnyx([$this->gregApp => 500, $this->gregCell => 'greg-cell-leg']);

    $callLog = appOnly_incomingCall(['status' => CallLog::STATUS_TRANSFERRED, 'metadata' => [
        'admin_call_control_ids' => ['patryk-leg'],
        'joined_admin_ids' => [$this->patryk->id],
        'conference_id' => 'conf-live',
    ]]);
    Cache::put('telnyx_bridged:patryk-leg', true, now()->addMinutes(10));

    $this->postJson('/webhooks/telnyx/voice', ['data' => ['event_type' => 'call.dtmf.received', 'record_type' => 'event', 'payload' => [
        'call_control_id' => 'patryk-leg',
        'digit' => '9',
    ]]])->assertSuccessful();

    expect(appOnly_dialed())->toBe([$this->gregApp, $this->gregCell])
        ->and($callLog->fresh()->metadata['admin_call_control_ids'])->toBe(['patryk-leg', 'greg-cell-leg']);
});

it('still dials cells when 9 is pressed on a call the team placed', function () {
    appOnly_giveApp($this->greg);
    appOnly_fakeTelnyx([$this->gregCell => 'greg-cell-leg']);

    CallLog::factory()->create([
        'call_control_id' => 'patryk-leg',
        'direction' => 'outgoing',
        'status' => CallLog::STATUS_TRANSFERRED,
        'user_id' => $this->patryk->id,
        'metadata' => [
            'type' => 'click_to_call',
            'admin_call_control_ids' => ['patryk-leg'],
            'joined_admin_ids' => [$this->patryk->id],
            'conference_id' => 'conf-out',
        ],
    ]);
    Cache::put('telnyx_bridged:patryk-leg', true, now()->addMinutes(10));

    $this->postJson('/webhooks/telnyx/voice', ['data' => ['event_type' => 'call.dtmf.received', 'record_type' => 'event', 'payload' => [
        'call_control_id' => 'patryk-leg',
        'digit' => '9',
    ]]])->assertSuccessful();

    expect(appOnly_dialed())->toBe([$this->gregCell]);
});
