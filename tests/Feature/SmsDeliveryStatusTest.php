<?php

use App\Events\SmsMessageStatusUpdated;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function makeOutboundMessage(string $providerId, string $status = 'sent'): SmsMessage
{
    $thread = SmsGroupThread::create([
        'vendor_id' => 1,
        'from_number' => '+12245550100',
        'participants' => ['+12245550101'],
    ]);

    return SmsMessage::create([
        'thread_id' => $thread->id,
        'provider' => 'telnyx',
        'provider_message_id' => $providerId,
        'direction' => 'outbound',
        'from_number' => '+12245550100',
        'to_numbers' => ['+12245550101'],
        'text' => 'test',
        'status' => $status,
    ]);
}

function telnyxStatusWebhook(string $eventType, string $providerId, array $to, array $errors = []): array
{
    return [
        'data' => [
            'event_type' => $eventType,
            'id' => 'evt-'.uniqid(),
            'payload' => array_filter([
                'id' => $providerId,
                'to' => $to,
                'errors' => $errors ?: null,
            ]),
        ],
    ];
}

it('marks the message delivered on the message.delivered webhook and broadcasts', function () {
    Event::fake([SmsMessageStatusUpdated::class]);
    $message = makeOutboundMessage('tx-del-1');

    $this->postJson('/webhooks/telnyx/messaging', telnyxStatusWebhook(
        'message.delivered', 'tx-del-1', [['phone_number' => '+12245550101', 'status' => 'delivered']],
    ))->assertOk();

    expect($message->fresh()->status)->toBe('delivered');
    Event::assertDispatched(SmsMessageStatusUpdated::class, fn ($e) => $e->messageId === $message->id
        && $e->threadId === $message->thread_id
        && $e->status === 'delivered');
});

it('marks the message failed with errors on message.failed', function () {
    Event::fake([SmsMessageStatusUpdated::class]);
    $message = makeOutboundMessage('tx-fail-1');

    $this->postJson('/webhooks/telnyx/messaging', telnyxStatusWebhook(
        'message.failed', 'tx-fail-1',
        [['phone_number' => '+12245550101', 'status' => 'sending_failed']],
        [['code' => '40300', 'title' => 'Blocked as spam']],
    ))->assertOk();

    $fresh = $message->fresh();
    expect($fresh->status)->toBe('failed')
        ->and($fresh->raw_payload['delivery_errors'][0]['code'])->toBe('40300');
    Event::assertDispatched(SmsMessageStatusUpdated::class, fn ($e) => $e->status === 'failed');
});

it('never downgrades failed back to delivered on a late per-recipient event', function () {
    Event::fake([SmsMessageStatusUpdated::class]);
    $message = makeOutboundMessage('tx-race-1', 'failed');

    $this->postJson('/webhooks/telnyx/messaging', telnyxStatusWebhook(
        'message.delivered', 'tx-race-1', [['phone_number' => '+12245550101', 'status' => 'delivered']],
    ))->assertOk();

    expect($message->fresh()->status)->toBe('failed');
    Event::assertNotDispatched(SmsMessageStatusUpdated::class);
});

it('resolves the final state from message.finalized recipients', function () {
    Event::fake([SmsMessageStatusUpdated::class]);
    $message = makeOutboundMessage('tx-fin-1');

    $this->postJson('/webhooks/telnyx/messaging', telnyxStatusWebhook(
        'message.finalized', 'tx-fin-1',
        [
            ['phone_number' => '+12245550101', 'status' => 'delivered'],
            ['phone_number' => '+12245550102', 'status' => 'delivery_failed'],
        ],
    ))->assertOk();

    expect($message->fresh()->status)->toBe('failed');
});

it('ignores statuses for inbound messages and unknown provider ids', function () {
    Event::fake([SmsMessageStatusUpdated::class]);
    $message = makeOutboundMessage('tx-in-1');
    $message->update(['direction' => 'inbound', 'status' => 'received']);

    $this->postJson('/webhooks/telnyx/messaging', telnyxStatusWebhook(
        'message.delivered', 'tx-in-1', [['phone_number' => '+12245550101', 'status' => 'delivered']],
    ))->assertOk();

    $this->postJson('/webhooks/telnyx/messaging', telnyxStatusWebhook(
        'message.delivered', 'tx-nope', [['phone_number' => '+12245550101', 'status' => 'delivered']],
    ))->assertOk();

    expect($message->fresh()->status)->toBe('received');
    Event::assertNotDispatched(SmsMessageStatusUpdated::class);
});
