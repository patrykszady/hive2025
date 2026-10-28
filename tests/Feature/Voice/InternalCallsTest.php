<?php

use App\Jobs\Voice\InternalCallTimeout;
use App\Jobs\Voice\SendInternalCallPush;
use App\Livewire\TeamPhone;
use App\Models\CallLog;
use App\Models\PushSubscription;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\User;
use App\Models\UserVoiceCredential;
use App\Models\Vendor;
use App\Services\Voice\InternalCalls;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Calls between team members that stay inside Hive (Patryk, 2026-10-27):
 * the caller's Hive login rings and answers itself, the callee gets a push
 * with Answer and a banner in open tabs, Answer rings their login into the
 * waiting recorded call; no cell fallback; 30 seconds then "didn't pick up"
 * and a missed-call note in their team thread.
 */

beforeEach(function () {
    config([
        'scout.driver' => null,
        'services.telnyx.api_key' => 'test-api-key',
        'services.telnyx.from' => '+12247354200',
        'services.telnyx.connection_id' => 'call-control-app',
        'services.telnyx.app_connection_id' => 'app-connection',
        'services.telnyx.public_url' => 'https://example.com',
        'call_recording.mode' => 'auto',
    ]);
    $this->gs = Vendor::withoutGlobalScopes()->find(1) ?? Vendor::query()->forceCreate(['id' => 1, 'business_name' => 'GS Construction']);
    $this->gs->forceFill(['options' => (object) ['short_name' => 'GS Construction']])->save();
    // The roster is exactly these three (the seeded test DB has other vendor-1 users).
    $this->gs->users()->detach();
    $make = function (string $first, string $cell, ?string $nick = null, string $sip = '') {
        $u = User::factory()->create(['first_name' => $first, 'nickname' => $nick, 'last_name' => 'Szady', 'cell_phone' => $cell, 'primary_vendor_id' => 1]);
        $this->gs->users()->attach($u->id, ['role_id' => 1, 'is_employed' => 1]);
        if ($sip !== '') {
            UserVoiceCredential::create(['user_id' => $u->id, 'telnyx_credential_id' => 'cred-'.$sip, 'sip_username' => $sip]);
        }

        return $u;
    };
    $this->patryk = $make('Patryk', '2249993880', null, 'gencredpatryk');
    $this->greg = $make('Grzegorz', '8478737217', 'Greg', 'gencredgreg');
    $this->cezary = $make('Cezary', '8475550123', null, 'gencredcezary');
    $this->outsider = User::factory()->create(['first_name' => 'Out', 'cell_phone' => '2245550199']);
});

function telnyxFake(): void
{
    Http::fake([
        'api.telnyx.com/v2/calls' => Http::response(['data' => ['call_control_id' => 'cc-'.uniqid(), 'call_session_id' => 'sess-1', 'call_leg_id' => 'leg-1']], 200),
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok', 'id' => 'conf-1']], 200),
    ]);
}

/** @return array<string, mixed> */
function clientStateOf(Request $request): array
{
    return (array) json_decode(base64_decode((string) $request['client_state']), true);
}

/**
 * Post one Telnyx voice event the way Telnyx delivers it (record_type event, state base64).
 *
 * @param  array<string, mixed>  $payload
 * @param  array<string, mixed>  $clientState
 */
function voiceEvent(string $eventType, array $payload, array $clientState): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/webhooks/telnyx/voice', ['data' => [
        'record_type' => 'event',
        'id' => 'evt-'.uniqid(),
        'event_type' => $eventType,
        'payload' => $payload + ['client_state' => base64_encode(json_encode($clientState))],
    ]]);
}

it('rings the caller\'s own Hive first, pushes "is calling" to the callee and schedules the 30-second limit', function () {
    telnyxFake();
    Queue::fake();
    $this->actingAs($this->patryk);

    $result = Livewire::test(TeamPhone::class)->call('callUser', $this->greg->id)->get('enabled');
    $callLog = CallLog::query()->latest('id')->first();

    expect($callLog)->not->toBeNull()
        ->and($callLog->metadata['type'])->toBe('internal_call')
        ->and($callLog->metadata['target_user_id'])->toBe($this->greg->id)
        ->and($callLog->metadata['target_phones'])->toBe(['sip:gencredgreg@sip.telnyx.com'])
        ->and($callLog->user_id)->toBe($this->patryk->id)
        ->and($callLog->status)->toBe(CallLog::STATUS_INITIATED)
        ->and($callLog->caller_name)->toBe('Greg (Hive)');

    Http::assertSent(function (Request $r) use ($callLog) {
        if (! str_ends_with($r->url(), '/v2/calls')) {
            return false;
        }
        $state = clientStateOf($r);

        return $r['to'] === 'sip:gencredpatryk@sip.telnyx.com'
            && $r['from_display_name'] === 'Greg'
            && collect($r['custom_headers'])->contains(fn ($h) => $h['name'] === 'X-Hive-Outbound-Call' && $h['value'] === (string) $callLog->id)
            && $state['action'] === 'click_to_call' && $state['internal'] === true && $state['target_phones'] === ['sip:gencredgreg@sip.telnyx.com'];
    });
    Queue::assertPushed(SendInternalCallPush::class, fn ($job) => $job->callLogId === $callLog->id);
    Queue::assertPushed(InternalCallTimeout::class, fn ($job) => $job->callLogId === $callLog->id && (int) round(abs($job->delay->diffInSeconds(now()))) === InternalCalls::RING_SECONDS);
});

it('only calls teammates, and only from a team account', function () {
    telnyxFake();
    Queue::fake();

    $this->actingAs($this->patryk);
    expect(Livewire::test(TeamPhone::class)->call('callUser', $this->outsider->id)->get('enabled'))->toBeTrue();
    expect(CallLog::count())->toBe(0);
    $result = app(InternalCalls::class)->start($this->patryk, $this->outsider);
    expect($result['ok'])->toBeFalse()->and($result['error'])->toContain('your team');

    $this->actingAs($this->outsider);
    expect(Livewire::test(TeamPhone::class)->get('enabled'))->toBeFalse();
    Livewire::test(TeamPhone::class)->call('callUser', $this->greg->id)->assertForbidden();
});

it('pushes the ring to the callee\'s devices only, with Answer and Decline buttons', function () {
    telnyxFake();
    Queue::fake();
    PushSubscription::create(['user_id' => $this->greg->id, 'endpoint' => 'https://push.example/greg-phone', 'p256dh' => 'k', 'auth' => 'a', 'sms_inbound_enabled' => false]);
    PushSubscription::create(['user_id' => $this->greg->id, 'endpoint' => 'https://push.example/greg-laptop', 'p256dh' => 'k', 'auth' => 'a']);
    PushSubscription::create(['user_id' => $this->cezary->id, 'endpoint' => 'https://push.example/cezary', 'p256dh' => 'k', 'auth' => 'a']);
    $callLog = app(InternalCalls::class)->start($this->patryk, $this->greg)['call_log'];

    $this->mock(WebPushService::class, function ($mock) use ($callLog) {
        $mock->shouldReceive('sendToSubscriptions')->once()->withArgs(function ($subs, $payload, $channel, $options) use ($callLog) {
            return $subs->pluck('endpoint')->sort()->values()->all() === ['https://push.example/greg-laptop', 'https://push.example/greg-phone']
                && $options['TTL'] === InternalCalls::RING_SECONDS + 10 && $options['urgency'] === 'high' && $options['topic'] === "hive-call-{$callLog->id}"
                && $payload['title'] === 'Patryk is calling'
                && $payload['requireInteraction'] === true
                && collect($payload['actions'])->pluck('action')->all() === ['answer', 'decline']
                && $payload['data']['answerUrl'] === "/messages?answerCall={$callLog->id}"
                && $payload['data']['url'] === "/messages?incomingCall={$callLog->id}"
                && str_contains($payload['data']['declineUrl'], "/internal-calls/{$callLog->id}/decline?")
                && str_contains($payload['data']['declineUrl'], 'signature=');
        });
    });

    (new SendInternalCallPush($callLog->id))->handle(app(WebPushService::class), app(InternalCalls::class));
});

it('dials the callee\'s Hive login without machine detection or the recording disclosure once the caller is on', function () {
    telnyxFake();
    Queue::fake();
    $callLog = app(InternalCalls::class)->start($this->patryk, $this->greg)['call_log'];
    $callerLeg = $callLog->call_control_id;

    // The caller's browser answered its own ring.
    voiceEvent('call.answered', ['call_control_id' => $callerLeg],
        ['action' => 'click_to_call', 'call_log_id' => $callLog->id, 'target_phone' => 'sip:gencredgreg@sip.telnyx.com', 'target_phones' => ['sip:gencredgreg@sip.telnyx.com'], 'internal' => true])->assertOk();

    Http::assertSent(function (Request $r) use ($callLog, $callerLeg) {
        if (! str_ends_with($r->url(), '/v2/calls') || ($r['to'] ?? '') !== 'sip:gencredgreg@sip.telnyx.com') {
            return false;
        }
        $state = clientStateOf($r);

        return ! isset($r['answering_machine_detection'])
            && $r['timeout_secs'] === InternalCalls::RING_SECONDS
            && $r['from_display_name'] === 'Patryk'
            && collect($r['custom_headers'])->contains(fn ($h) => $h['name'] === 'X-Hive-Call-Log' && $h['value'] === (string) $callLog->id)
            && $state['action'] === 'click_to_call_target_ring' && $state['internal'] === true && $state['user_call_control_id'] === $callerLeg;
    });
    expect($callLog->fresh()->status)->toBe(CallLog::STATUS_ANSWERED);

    // Greg's Hive was closed: that leg fails at once. The caller keeps waiting — no "did not answer" yet.
    voiceEvent('call.hangup', ['call_control_id' => 'cc-greg-closed', 'hangup_cause' => 'user_not_registered'],
        ['action' => 'click_to_call_target_ring', 'call_log_id' => $callLog->id, 'user_call_control_id' => $callerLeg, 'internal' => true])->assertOk();
    expect($callLog->fresh()->status)->toBe(CallLog::STATUS_ANSWERED);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/actions/speak'));

    // Greg's login answers (from the push): the target joins without the disclosure speech, recording on.
    voiceEvent('call.answered', ['call_control_id' => 'cc-greg-answered'],
        ['action' => 'click_to_call_target_ring', 'call_log_id' => $callLog->id, 'user_call_control_id' => $callerLeg, 'target_phone' => 'sip:gencredgreg@sip.telnyx.com', 'internal' => true])->assertOk();
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/actions/speak'));
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'cc-greg-answered/actions/record_start'));
});

it('lets the callee answer from a push by ringing their login into the waiting call, and refuses anyone else', function () {
    telnyxFake();
    Queue::fake();
    $callLog = app(InternalCalls::class)->start($this->patryk, $this->greg)['call_log'];

    // Before the caller's own leg is up: not ready yet (the browser retries).
    $this->actingAs($this->greg);
    expect(Livewire::test(TeamPhone::class)->call('answer', $callLog->id)->get('enabled'))->toBeTrue();
    expect(app(InternalCalls::class)->answer($callLog->fresh(), $this->greg)['error'])->toBe('not_ready');

    $callLog->update(['status' => CallLog::STATUS_ANSWERED]);
    $result = app(InternalCalls::class)->answer($callLog->fresh(), $this->greg);
    expect($result['ok'])->toBeTrue();
    Http::assertSent(function (Request $r) use ($callLog) {
        if (! str_ends_with($r->url(), '/v2/calls') || ($r['to'] ?? '') !== 'sip:gencredgreg@sip.telnyx.com') {
            return false;
        }
        $state = clientStateOf($r);

        return collect($r['custom_headers'])->contains(fn ($h) => $h['name'] === 'X-Hive-Outbound-Call')
            && $state['action'] === 'click_to_call_target_ring' && $state['internal'] === true && $state['user_call_control_id'] === $callLog->call_control_id;
    });

    expect(app(InternalCalls::class)->answer($callLog->fresh(), $this->cezary)['error'])->toBe('That call is not for you.');
    $callLog->update(['status' => CallLog::STATUS_COMPLETED]);
    expect(app(InternalCalls::class)->answer($callLog->fresh(), $this->greg)['error'])->toBe('That call has ended.');
});

it('declines: the caller hears so, the call ends, and a missed-call note lands in their team thread', function () {
    telnyxFake();
    Queue::fake();
    $callLog = app(InternalCalls::class)->start($this->patryk, $this->greg)['call_log'];
    $callLog->update(['status' => CallLog::STATUS_ANSWERED]);

    // The push's Decline button: a signed link, no session.
    $this->get(InternalCalls::declineUrl($callLog, $this->greg))->assertNoContent();

    $callLog->refresh();
    expect($callLog->status)->toBe(CallLog::STATUS_MISSED)->and($callLog->hangup_cause)->toBe('declined');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), $callLog->call_control_id.'/actions/speak') && $r['payload'] === 'Greg declined the call.');

    $thread = SmsGroupThread::query()->where('kind', SmsGroupThread::KIND_TEAM)->sole();
    expect($thread->memberIds())->toBe(collect([$this->patryk->id, $this->greg->id])->sort()->values()->all());
    $note = SmsMessage::query()->where('thread_id', $thread->id)->sole();
    expect($note->text)->toBe('📞 Missed call from Patryk')->and($note->provider)->toBe('hive')->and(data_get($note->raw_payload, 'call_log_id'))->toBe($callLog->id);

    // A tampered or expired link does nothing.
    $this->get(str_replace('signature=', 'signature=x', InternalCalls::declineUrl($callLog, $this->greg)))->assertForbidden();
});

it('ends a call nobody took after 30 seconds, once, and pushes "missed call" to the callee', function () {
    telnyxFake();
    Queue::fake();
    $callLog = app(InternalCalls::class)->start($this->patryk, $this->greg)['call_log'];
    $callLog->update(['status' => CallLog::STATUS_ANSWERED]);
    $this->mock(WebPushService::class)->shouldReceive('sendToUsers')->once()->withArgs(fn ($ids, $payload) => collect($ids)->all() === [$this->greg->id] && $payload['title'] === 'Missed call from Patryk');

    (new InternalCallTimeout($callLog->id))->handle(app(InternalCalls::class));

    $callLog->refresh();
    expect($callLog->status)->toBe(CallLog::STATUS_MISSED)->and($callLog->hangup_cause)->toBe('no_answer');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/actions/speak') && $r['payload'] === "Greg didn't pick up.");
    expect(SmsMessage::query()->where('text', '📞 Missed call from Patryk')->count())->toBe(1);

    // Running again (or after the caller hung up first) adds nothing.
    (new InternalCallTimeout($callLog->id))->handle(app(InternalCalls::class));
    expect(SmsMessage::query()->where('text', '📞 Missed call from Patryk')->count())->toBe(1);
});

it('leaves a connected call alone when the timer fires', function () {
    telnyxFake();
    Queue::fake();
    $callLog = app(InternalCalls::class)->start($this->patryk, $this->greg)['call_log'];
    $callLog->update(['status' => CallLog::STATUS_TRANSFERRED, 'metadata' => $callLog->metadata + ['joined_target_call_control_ids' => ['cc-greg']]]);
    $this->mock(WebPushService::class)->shouldNotReceive('sendToUsers');

    (new InternalCallTimeout($callLog->id))->handle(app(InternalCalls::class));

    expect($callLog->fresh()->status)->toBe(CallLog::STATUS_TRANSFERRED)
        ->and(SmsMessage::query()->count())->toBe(0);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/actions/speak'));
});

it('calls the whole crew: every Hive rings, the first to answer opens the recorded call, a decline only drops that person', function () {
    telnyxFake();
    Queue::fake();
    PushSubscription::create(['user_id' => $this->greg->id, 'endpoint' => 'https://push.example/greg', 'p256dh' => 'k', 'auth' => 'a']);
    PushSubscription::create(['user_id' => $this->cezary->id, 'endpoint' => 'https://push.example/cezary', 'p256dh' => 'k', 'auth' => 'a']);
    $this->actingAs($this->patryk);

    $result = Livewire::test(TeamPhone::class)->call('callCrew')->get('enabled');
    $callLog = CallLog::query()->latest('id')->first();
    $callerLeg = $callLog->call_control_id;
    expect($callLog->metadata['crew'])->toBeTrue()
        ->and($callLog->metadata['target_user_ids'])->toBe([$this->greg->id, $this->cezary->id])
        ->and($callLog->metadata['target_phones'])->toBe(['sip:gencredgreg@sip.telnyx.com', 'sip:gencredcezary@sip.telnyx.com'])
        ->and($callLog->caller_name)->toBe('Crew (Hive)');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v2/calls') && $r['to'] === 'sip:gencredpatryk@sip.telnyx.com' && $r['from_display_name'] === 'Crew');

    // Both get the push, each with their own Decline link.
    $this->mock(WebPushService::class, function ($mock) use ($callLog) {
        $mock->shouldReceive('sendToSubscriptions')->twice()->withArgs(function ($subs, $payload) use ($callLog) {
            $endpoint = $subs->pluck('endpoint')->sole();
            $who = str_ends_with($endpoint, '/greg') ? $this->greg->id : $this->cezary->id;

            return $payload['title'] === 'Patryk is calling the crew'
                && $payload['data']['crew'] === true
                && str_contains($payload['data']['declineUrl'], "/internal-calls/{$callLog->id}/decline?")
                && str_contains($payload['data']['declineUrl'], "user={$who}");
        });
    });
    (new SendInternalCallPush($callLog->id))->handle(app(WebPushService::class), app(InternalCalls::class));

    // The caller's Hive answered: both logins are dialed at once.
    voiceEvent('call.answered', ['call_control_id' => $callerLeg],
        ['action' => 'click_to_call', 'call_log_id' => $callLog->id, 'target_phone' => 'sip:gencredgreg@sip.telnyx.com', 'target_phones' => ['sip:gencredgreg@sip.telnyx.com', 'sip:gencredcezary@sip.telnyx.com'], 'internal' => true])->assertOk();
    foreach (['sip:gencredgreg@sip.telnyx.com', 'sip:gencredcezary@sip.telnyx.com'] as $sip) {
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v2/calls') && ($r['to'] ?? '') === $sip && ! isset($r['answering_machine_detection']) && clientStateOf($r)['action'] === 'click_to_call_target_ring');
    }

    // Greg answers: the recorded conference opens. Cezary declines: only he is out, the call goes on.
    voiceEvent('call.answered', ['call_control_id' => 'cc-greg'],
        ['action' => 'click_to_call_target_ring', 'call_log_id' => $callLog->id, 'user_call_control_id' => $callerLeg, 'target_phone' => 'sip:gencredgreg@sip.telnyx.com', 'internal' => true])->assertOk();
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'cc-greg/actions/record_start'));
    expect(app(InternalCalls::class)->hasConnected($callLog->fresh()))->toBeTrue();

    expect(app(InternalCalls::class)->decline($callLog->fresh(), $this->cezary))->toBeTrue();
    $callLog->refresh();
    expect($callLog->metadata['declined_user_ids'])->toBe([$this->cezary->id])
        ->and(app(InternalCalls::class)->hasEnded($callLog))->toBeFalse();
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/actions/speak'));

    // The 30-second timer leaves a connected crew call alone.
    (new InternalCallTimeout($callLog->id))->handle(app(InternalCalls::class));
    expect($callLog->fresh()->status)->not->toBe(CallLog::STATUS_MISSED)
        ->and(SmsMessage::query()->where('text', 'like', '%Missed crew call%')->count())->toBe(0);

    // Cezary may still join late from his push: his own login is rung into the call.
    expect(app(InternalCalls::class)->answer($callLog->fresh(), $this->cezary)['ok'])->toBeTrue();
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v2/calls') && ($r['to'] ?? '') === 'sip:gencredcezary@sip.telnyx.com' && collect($r['custom_headers'])->contains(fn ($h) => $h['name'] === 'X-Hive-Call-Log'));
});

it('ends a crew call nobody took: the caller hears so, one note lands in the crew thread, and only those who did not decline get the missed push', function () {
    telnyxFake();
    Queue::fake();
    $callLog = app(InternalCalls::class)->startCrew($this->patryk)['call_log'];
    $callLog->update(['status' => CallLog::STATUS_ANSWERED]);
    app(InternalCalls::class)->decline($callLog->fresh(), $this->greg);
    expect($callLog->fresh()->status)->toBe(CallLog::STATUS_ANSWERED, 'one decline does not end a crew call');

    $this->mock(WebPushService::class)->shouldReceive('sendToUsers')->once()->withArgs(fn ($ids, $payload) => collect($ids)->all() === [$this->cezary->id] && $payload['title'] === 'Missed crew call from Patryk' && $payload['data']['url'] === '/messages?activeTab=team');
    (new InternalCallTimeout($callLog->id))->handle(app(InternalCalls::class));

    $callLog->refresh();
    expect($callLog->status)->toBe(CallLog::STATUS_MISSED)->and($callLog->hangup_cause)->toBe('no_answer');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/actions/speak') && $r['payload'] === 'Nobody picked up.');
    $note = SmsMessage::query()->where('text', '📞 Missed crew call from Patryk')->sole();
    expect($note->thread->name)->toBe('GS Construction Crew')
        ->and($note->thread->memberIds())->toBe([$this->patryk->id, $this->greg->id, $this->cezary->id]);

    // Everyone declining ends it the same way, without the missed push.
    $second = app(InternalCalls::class)->startCrew($this->patryk)['call_log'];
    $second->update(['status' => CallLog::STATUS_ANSWERED]);
    app(InternalCalls::class)->decline($second->fresh(), $this->greg);
    app(InternalCalls::class)->decline($second->fresh(), $this->cezary);
    expect($second->fresh()->status)->toBe(CallLog::STATUS_MISSED)->and($second->fresh()->hangup_cause)->toBe('declined');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/actions/speak') && $r['payload'] === 'Everyone declined the call.');
    expect(SmsMessage::query()->where('text', '📞 Missed crew call from Patryk')->count())->toBe(2);
});

it('refuses a second call while one is ringing or connected', function () {
    telnyxFake();
    Queue::fake();
    $first = app(InternalCalls::class)->start($this->patryk, $this->greg);
    expect($first['ok'])->toBeTrue();

    $second = app(InternalCalls::class)->start($this->patryk, $this->greg);
    expect($second['ok'])->toBeFalse()->and($second['error'])->toContain('already on a call');
    expect(app(InternalCalls::class)->startCrew($this->patryk)['ok'])->toBeFalse();

    // Once it is over, calling again is fine.
    $first['call_log']->update(['status' => CallLog::STATUS_COMPLETED]);
    expect(app(InternalCalls::class)->start($this->patryk, $this->greg)['ok'])->toBeTrue();
});
