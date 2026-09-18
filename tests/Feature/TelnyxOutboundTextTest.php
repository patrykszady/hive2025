<?php

use App\Models\CallLog;
use App\Models\SmsMessage;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Pressing 1 on the GS user's leg of an outbound call texts the person we
 * called ("GS Construction tried reaching you…"), records it in their thread
 * and confirms in the user's ear (2026-09-17).
 */
beforeEach(function () {
    Cache::flush();
    config([
        'services.telnyx.api_key' => 'test-key',
        'services.telnyx.from' => '+12245554444',
        'services.telnyx.numbers' => ['+12245554444'],
        'services.telnyx.dev_to' => null,
        'services.telnyx.messaging_profile_id' => null,
    ]);
    $this->vendor = Vendor::find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $this->vendor->options = ['short_name' => 'GS Construction'];
    $this->vendor->save();
    $this->user = User::query()->create(['first_name' => 'Patryk', 'last_name' => 'S', 'email' => 'p-'.uniqid().'@example.com', 'cell_phone' => '2249993880', 'primary_vendor_id' => 1]);
    $this->callLog = CallLog::factory()->create([
        'call_control_id' => 'user-cc-id',
        'user_id' => $this->user->id,
        'status' => CallLog::STATUS_TRANSFERRED,
        'metadata' => [
            'type' => 'click_to_call',
            'target_phone' => '+18475550123',
            'target_phones' => ['+18475550123'],
            'target_call_control_ids' => ['target-cc-1'],
            'answered_target_call_control_id' => 'target-cc-1',
            'admin_call_control_ids' => ['user-cc-id'],
            'conference_id' => 'conf-123',
        ],
    ]);
    app()->forgetInstance('Illuminate\Http\Client\Factory');
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake([
        'api.telnyx.com/v2/messages' => Http::response(['data' => ['id' => 'msg-telnyx-1']], 200),
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200),
    ]);
});

function pressDigit(string $leg, string $digit): void
{
    test()->postJson('/webhooks/telnyx/voice', ['data' => ['event_type' => 'call.dtmf.received', 'record_type' => 'event', 'payload' => [
        'call_control_id' => $leg,
        'digit' => $digit,
    ]]])->assertSuccessful();
}

it('texts the target when the GS user presses 1, records it in the thread, and confirms in their ear', function () {
    pressDigit('user-cc-id', '1');

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v2/messages')
        && ($r->data()['to'] ?? null) === '+18475550123'
        && ($r->data()['from'] ?? null) === '+12245554444'
        && ($r->data()['text'] ?? null) === 'GS Construction tried reaching you. Please give us a call back.');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'user-cc-id/actions/speak') && ($r->data()['payload'] ?? null) === 'Text sent.');

    $message = SmsMessage::query()->where('provider_message_id', 'msg-telnyx-1')->first();
    expect($message)->not->toBeNull();
    expect($message->direction)->toBe(SmsMessage::DIRECTION_OUTBOUND);
    expect($message->to_numbers)->toBe(['+18475550123']);
    expect($message->sent_by_user_id)->toBe($this->user->id);
    expect($message->thread_id)->not->toBeNull();
    expect($message->thread->participants)->toContain('+18475550123');
});

it('uses the vendor\'s own wording and sends only once per call', function () {
    $this->vendor->options = ['short_name' => 'GS', 'missed_call_text' => 'Hi from {company} — we just tried you. Call us back when you can.'];
    $this->vendor->save();

    pressDigit('user-cc-id', '1');
    pressDigit('user-cc-id', '1');

    Http::assertSentCount(3); // one text + "Text sent.", then "Text already sent." only
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v2/messages') && ($r->data()['text'] ?? null) === 'Hi from GS — we just tried you. Call us back when you can.');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'user-cc-id/actions/speak') && ($r->data()['payload'] ?? null) === 'Text already sent.');
    expect(SmsMessage::query()->count())->toBe(1);
});

it('does nothing when the target presses 1, or on a leg that is not an outbound call', function () {
    pressDigit('target-cc-1', '1');
    CallLog::factory()->create(['call_control_id' => 'incoming-cc', 'status' => CallLog::STATUS_TRANSFERRED, 'metadata' => ['admin_call_control_ids' => ['admin-cc-1'], 'conference_id' => 'conf-9']]);
    pressDigit('admin-cc-1', '1');

    Http::assertNothingSent();
    expect(SmsMessage::query()->count())->toBe(0);
});

it('whispers the option to the user when the target turns out to be voicemail', function () {
    test()->postJson('/webhooks/telnyx/voice', ['data' => ['event_type' => 'call.machine.premium.detection.ended', 'record_type' => 'event', 'payload' => [
        'call_control_id' => 'target-cc-1',
        'result' => 'machine',
        'client_state' => base64_encode(json_encode(['action' => 'click_to_call_target_ring', 'call_log_id' => $this->callLog->id, 'user_call_control_id' => 'user-cc-id'])),
    ]]])->assertSuccessful();

    Http::assertSent(fn ($r) => str_contains($r->url(), 'user-cc-id/actions/speak') && str_starts_with((string) ($r->data()['payload'] ?? ''), 'Voicemail. Press one'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'target-cc-1/actions/hangup'));
});

// ---------------------------------------------------------------------------
// Inbound: the admin who hears "Call from {name}" presses 1 instead of taking
// it. The caller is texted "{company} will call you right back", told the
// same by voice and released; no voicemail, no bridge.
// ---------------------------------------------------------------------------

function inboundFixture(): CallLog
{
    return CallLog::factory()->create([
        'call_control_id' => 'incoming-cc',
        'status' => CallLog::STATUS_ANSWERED,
        'from_number' => '+18472123894',
        'caller_name' => 'Bob Smith',
        'metadata' => ['admin_call_control_ids' => ['admin-cc-1', 'admin-cc-2'], 'tts_complete' => true, 'joined_admin_ids' => []],
    ]);
}

function adminState(CallLog $callLog, string $action = 'admin_screen_done'): string
{
    return base64_encode(json_encode(['action' => $action, 'call_log_id' => $callLog->id, 'incoming_call_control_id' => 'incoming-cc', 'admin_user_id' => 7]));
}

it('texts the caller a callback promise when the announced admin presses 1, and releases everyone', function () {
    $callLog = inboundFixture();

    test()->postJson('/webhooks/telnyx/voice', ['data' => ['event_type' => 'call.dtmf.received', 'record_type' => 'event', 'payload' => [
        'call_control_id' => 'admin-cc-1', 'digit' => '1', 'client_state' => adminState($callLog),
    ]]])->assertSuccessful();

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v2/messages') && ($r->data()['to'] ?? null) === '+18472123894' && ($r->data()['text'] ?? null) === 'GS Construction will call you right back.');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'admin-cc-1/actions/speak') && str_starts_with((string) ($r->data()['payload'] ?? ''), 'Text sent.'));
    Http::assertSent(fn ($r) => str_contains($r->url(), 'admin-cc-2/actions/hangup'));
    Http::assertSent(fn ($r) => str_contains($r->url(), 'incoming-cc/actions/playback_stop'));
    Http::assertSent(fn ($r) => str_contains($r->url(), 'incoming-cc/actions/speak') && ($r->data()['payload'] ?? null) === "Thanks for your call Bob. GS Construction can't take your call and will call you right back.");
    Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/v2/conferences'));

    $fresh = $callLog->fresh();
    expect($fresh->status)->toBe(CallLog::STATUS_MISSED);
    expect($fresh->hangup_cause)->toBe('callback_promised');
    $message = SmsMessage::query()->where('provider_message_id', 'msg-telnyx-1')->first();
    expect($message?->to_numbers)->toBe(['+18472123894']);
    expect($message?->thread?->participants)->toContain('+18472123894');

    // The prompts finish: each leg is hung up, and the screening path does not bridge or start voicemail.
    test()->postJson('/webhooks/telnyx/voice', ['data' => ['event_type' => 'call.speak.ended', 'record_type' => 'event', 'payload' => [
        'call_control_id' => 'incoming-cc', 'client_state' => base64_encode(json_encode(['action' => 'hangup_after_speak', 'call_log_id' => $callLog->id])),
    ]]])->assertSuccessful();
    Http::assertSent(fn ($r) => str_contains($r->url(), 'incoming-cc/actions/hangup'));

    test()->postJson('/webhooks/telnyx/voice', ['data' => ['event_type' => 'call.speak.ended', 'record_type' => 'event', 'payload' => [
        'call_control_id' => 'admin-cc-1', 'client_state' => adminState($callLog),
    ]]])->assertSuccessful();
    test()->postJson('/webhooks/telnyx/voice', ['data' => ['event_type' => 'call.hangup', 'record_type' => 'event', 'payload' => [
        'call_control_id' => 'admin-cc-1', 'hangup_cause' => 'normal_clearing', 'client_state' => adminState($callLog, 'admin_ring'),
    ]]])->assertSuccessful();
    test()->postJson('/webhooks/telnyx/voice', ['data' => ['event_type' => 'call.hangup', 'record_type' => 'event', 'payload' => [
        'call_control_id' => 'admin-cc-2', 'hangup_cause' => 'normal_clearing', 'client_state' => adminState($callLog, 'admin_ring'),
    ]]])->assertSuccessful();
    Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/v2/conferences'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'incoming-cc/actions/gather_using_speak'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'incoming-cc/actions/transfer'));
});

it('only the first admin to press 1 sends the text', function () {
    $callLog = inboundFixture();
    foreach (['admin-cc-1', 'admin-cc-2'] as $leg) {
        test()->postJson('/webhooks/telnyx/voice', ['data' => ['event_type' => 'call.dtmf.received', 'record_type' => 'event', 'payload' => [
            'call_control_id' => $leg, 'digit' => '1', 'client_state' => adminState($callLog),
        ]]])->assertSuccessful();
    }
    expect(collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/v2/messages'))->count())->toBe(1);
    expect(SmsMessage::query()->count())->toBe(1);
});
