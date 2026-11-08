<?php

use App\Jobs\CheckExpoPushReceipts;
use App\Jobs\SendExpoBadgeUpdate;
use App\Jobs\SendExpoPushForNotification;
use App\Models\AppNotification;
use App\Models\Client;
use App\Models\PushToken;
use App\Models\SmsGroupThread;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Notifications\AppNotificationFeed;
use App\Services\Notifications\ExpoPushClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function expoPushUser(): User
{
    return User::factory()->create();
}

it('dispatches SendExpoPushForNotification whenever an AppNotification is created', function () {
    Queue::fake();
    $user = expoPushUser();

    $notification = AppNotification::create([
        'user_id' => $user->id,
        'type' => 'sms_received',
        'title' => 'New Text from Jane',
        'body' => 'Hello',
        'action_url' => '/messages?threadId=1',
    ]);

    Queue::assertPushed(SendExpoPushForNotification::class, fn ($job) => $job->notificationId === $notification->id);
});

it('posts the expected Expo payload, with badge equal to the unread count and the classified target', function () {
    // The sync queue connection ignores a dispatch's ->delay() and runs it
    // immediately, so CheckExpoPushReceipts' own getReceipts call would
    // otherwise show up as a second recorded request here too — faked out,
    // it has its own coverage below ("queues a delayed receipts check...").
    Queue::fake([CheckExpoPushReceipts::class]);
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-1']]])]);

    $user = expoPushUser();
    PushToken::factory()->create(['user_id' => $user->id, 'token' => 'ExponentPushToken[device-1]']);

    // One already-unread notification, so the badge on the one that
    // triggers the push must be 2, not 1.
    AppNotification::create([
        'user_id' => $user->id,
        'type' => 'lead_created',
        'title' => 'Earlier',
        'body' => 'Earlier body',
        'action_url' => null,
    ]);

    AppNotification::create([
        'user_id' => $user->id,
        'type' => 'missed_call',
        'title' => 'Missed call from (224) 555-0100',
        'body' => 'No one was available to answer.',
        'action_url' => '/messages?activeTab=calls&callId=9',
    ]);

    Http::assertSent(function ($request) {
        $message = $request->data()[0];

        // Two notifications were created, so two /send requests were
        // recorded — only the missed_call one is this assertion's target.
        if (($message['data']['type'] ?? null) !== 'missed_call') {
            return false;
        }

        expect($request->url())->toBe('https://exp.host/--/api/v2/push/send')
            ->and($message['to'])->toBe('ExponentPushToken[device-1]')
            ->and($message['title'])->toBe('Missed call from (224) 555-0100')
            ->and($message['body'])->toBe('No one was available to answer.')
            ->and($message['sound'])->toBe('default')
            ->and($message['badge'])->toBe(2)
            ->and($message['interruptionLevel'])->toBe('time-sensitive')
            ->and($message['data']['target'])->toBe(['type' => 'call', 'id' => 9, 'path' => null, 'title' => null]);

        return true;
    });
});

it('uses an active interruption level for a non-time-sensitive type', function () {
    Queue::fake([CheckExpoPushReceipts::class]);
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-1']]])]);

    $user = expoPushUser();
    PushToken::factory()->create(['user_id' => $user->id]);

    AppNotification::create([
        'user_id' => $user->id,
        'type' => 'lead_created',
        'title' => 'New Lead: Jane',
        'body' => 'New lead.',
        'action_url' => null,
    ]);

    Http::assertSent(fn ($request) => $request->data()[0]['interruptionLevel'] === 'active');
});

it('never sends a push for menards_browser notifications', function () {
    Http::fake();

    $user = expoPushUser();
    PushToken::factory()->create(['user_id' => $user->id]);

    AppNotification::create([
        'user_id' => $user->id,
        'type' => 'menards_browser',
        'title' => 'Menards needs a human',
        'body' => 'The browser is stuck on a challenge.',
        'action_url' => null,
    ]);

    Http::assertNothingSent();
});

it('no-ops when the user has no registered push token', function () {
    Http::fake();

    $user = expoPushUser();

    AppNotification::create([
        'user_id' => $user->id,
        'type' => 'sms_received',
        'title' => 'New Text from Jane',
        'body' => 'Hello',
        'action_url' => '/messages?threadId=1',
    ]);

    Http::assertNothingSent();
});

it('deletes the push token immediately when Expo reports DeviceNotRegistered', function () {
    Http::fake(['exp.host/*/send' => Http::response([
        'data' => [['status' => 'error', 'message' => 'not registered', 'details' => ['error' => 'DeviceNotRegistered']]],
    ])]);

    $user = expoPushUser();
    $token = PushToken::factory()->create(['user_id' => $user->id, 'token' => 'ExponentPushToken[dead]']);

    AppNotification::create([
        'user_id' => $user->id,
        'type' => 'lead_created',
        'title' => 'New Lead: Jane',
        'body' => 'New lead.',
        'action_url' => null,
    ]);

    expect(PushToken::find($token->id))->toBeNull();
});

it('does not queue a receipts check when every ticket was already resolved as DeviceNotRegistered', function () {
    // Only CheckExpoPushReceipts is faked — SendExpoPushForNotification
    // itself must actually run (sync queue) so its own dispatch of
    // CheckExpoPushReceipts is the thing under test.
    Queue::fake([CheckExpoPushReceipts::class]);
    Http::fake(['exp.host/*/send' => Http::response([
        'data' => [['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]],
    ])]);

    $user = expoPushUser();
    PushToken::factory()->create(['user_id' => $user->id]);

    AppNotification::create([
        'user_id' => $user->id,
        'type' => 'lead_created',
        'title' => 'New Lead: Jane',
        'body' => 'New lead.',
        'action_url' => null,
    ]);

    Queue::assertNotPushed(CheckExpoPushReceipts::class);
});

it('queues a delayed receipts check carrying the tickets a successful send returned', function () {
    Queue::fake([CheckExpoPushReceipts::class]);
    Http::fake(['exp.host/*/send' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-xyz']]])]);

    $user = expoPushUser();
    PushToken::factory()->create(['user_id' => $user->id, 'token' => 'ExponentPushToken[device-1]']);

    AppNotification::create([
        'user_id' => $user->id,
        'type' => 'lead_created',
        'title' => 'New Lead: Jane',
        'body' => 'New lead.',
        'action_url' => null,
    ]);

    Queue::assertPushed(CheckExpoPushReceipts::class, function ($job) {
        expect($job->tickets)->toBe([['ticket_id' => 'ticket-xyz', 'token' => 'ExponentPushToken[device-1]']]);

        return $job->delay !== null;
    });
});

it('a sms_received push carries threadId, categoryId and a subtitle naming the client', function () {
    Queue::fake([CheckExpoPushReceipts::class]);
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-1']]])]);

    $user = expoPushUser();
    PushToken::factory()->create(['user_id' => $user->id]);
    $client = Client::factory()->create(['business_name' => 'Jane Smith']);
    $thread = SmsGroupThread::create([
        'kind' => SmsGroupThread::KIND_SMS,
        'from_number' => '+13125550100',
        'participants' => ['+13125550199'],
        'client_id' => $client->id,
    ]);

    AppNotification::create([
        'user_id' => $user->id,
        'type' => 'sms_received',
        'title' => 'New Text from Jane Smith',
        'body' => 'Can you stop by Tuesday?',
        'action_url' => "/messages?threadId={$thread->id}&messageId=7",
        'data' => ['sms_message_id' => 7, 'thread_id' => $thread->id, 'from_number' => '+13125550199'],
    ]);

    Http::assertSent(function ($request) use ($thread) {
        $message = $request->data()[0];

        expect($message['categoryId'])->toBe('message')
            ->and($message['threadId'])->toBe((string) $thread->id)
            ->and($message['subtitle'])->toBe('Jane Smith')
            ->and($message['data']['thread_id'])->toBe($thread->id);

        return true;
    });
});

it('a team_message push subtitles with the thread\'s own name when it has one', function () {
    Queue::fake([CheckExpoPushReceipts::class]);
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-1']]])]);

    Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1]);
    $recipient = expoPushUser();
    $sender = expoPushUser();
    PushToken::factory()->create(['user_id' => $recipient->id]);
    $thread = SmsGroupThread::create([
        'kind' => SmsGroupThread::KIND_TEAM,
        'from_number' => '+13125550100',
        'participants' => [],
        'member_user_ids' => [$recipient->id, $sender->id],
        'name' => 'Job Site Crew',
        'vendor_id' => 1,
    ]);

    AppNotification::create([
        'user_id' => $recipient->id,
        'type' => 'team_message',
        'title' => "{$sender->first_name} · Job Site Crew",
        'body' => 'On my way',
        'action_url' => "/messages?threadId={$thread->id}&messageId=3",
        'data' => ['sms_message_id' => 3, 'thread_id' => $thread->id, 'from_user_id' => $sender->id],
    ]);

    Http::assertSent(function ($request) use ($thread) {
        $message = $request->data()[0];

        expect($message['threadId'])->toBe((string) $thread->id)
            ->and($message['subtitle'])->toBe('Job Site Crew');

        return true;
    });
});

it('checking receipts prunes a token whose receipt reports DeviceNotRegistered', function () {
    Http::fake(['exp.host/*/getReceipts' => Http::response([
        'data' => [
            'ticket-alive' => ['status' => 'ok'],
            'ticket-dead' => ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']],
        ],
    ])]);

    $alive = PushToken::factory()->create(['token' => 'ExponentPushToken[alive]']);
    $dead = PushToken::factory()->create(['token' => 'ExponentPushToken[dead]']);

    (new CheckExpoPushReceipts([
        ['ticket_id' => 'ticket-alive', 'token' => 'ExponentPushToken[alive]'],
        ['ticket_id' => 'ticket-dead', 'token' => 'ExponentPushToken[dead]'],
    ]))->handle(app(ExpoPushClient::class));

    expect(PushToken::find($alive->id))->not->toBeNull()
        ->and(PushToken::find($dead->id))->toBeNull();
});

it('marking everything read dispatches a silent badge-0 push to every registered device', function () {
    Queue::fake();
    $user = expoPushUser();

    app(AppNotificationFeed::class)->markAllRead($user->id);

    Queue::assertPushed(SendExpoBadgeUpdate::class, fn ($job) => $job->userId === $user->id && $job->badge === 0);
});

it('the badge-0 job posts a silent, sound-less payload to every token', function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-1']]])]);

    $user = expoPushUser();
    PushToken::factory()->create(['user_id' => $user->id, 'token' => 'ExponentPushToken[device-1]']);

    (new SendExpoBadgeUpdate($user->id))->handle(app(ExpoPushClient::class));

    Http::assertSent(function ($request) {
        $message = $request->data()[0];

        expect($message['to'])->toBe('ExponentPushToken[device-1]')
            ->and($message['badge'])->toBe(0)
            ->and($message['sound'])->toBeNull()
            ->and($message['data'])->toBe(['badge_only' => true])
            ->and($message)->not->toHaveKey('title');

        return true;
    });
});
