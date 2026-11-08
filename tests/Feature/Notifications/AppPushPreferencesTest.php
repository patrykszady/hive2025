<?php

use App\Jobs\CheckExpoPushReceipts;
use App\Jobs\SendDigestNotifications;
use App\Jobs\SendInboundSmsBrowserNotifications;
use App\Jobs\SendOutboundSmsBrowserNotifications;
use App\Jobs\SendRealtimeTaskNotification;
use App\Models\AppNotification;
use App\Models\Client;
use App\Models\Project;
use App\Models\PushSubscription;
use App\Models\PushToken;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Notifications\AppDevicePush;
use App\Services\TaskNotificationService;
use App\Services\WebPushService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 * Each phone with the Hive Contractors app carries the same four switches a
 * browser subscription has, plus its own on/off, all set on the users page
 * (see UserNotificationSettingsAppPushTest). This file proves every send
 * path honours them: in-app notification rows, a client's texts, a
 * teammate's reply on a client thread, the morning/evening digests and
 * realtime schedule changes.
 */

function appPrefsFakeExpo(): void
{
    Queue::fake([CheckExpoPushReceipts::class]);
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-1']]])]);
}

/** Every Expo message recorded so far, across all /send requests. @return list<array<string, mixed>> */
function appPrefsMessages(): array
{
    return Http::recorded()->flatMap(fn (array $pair) => $pair[0]->data())->values()->all();
}

/** The tokens the messages of one `data.type` went to. @return list<string> */
function appPrefsRecipients(string $type): array
{
    return collect(appPrefsMessages())
        ->filter(fn (array $message) => ($message['data']['type'] ?? null) === $type)
        ->pluck('to')->sort()->values()->all();
}

function appPrefsVendor(): Vendor
{
    return Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1]);
}

/** A WebPushService that never reaches the network — browser delivery has its own tests. */
function appPrefsSilentWebPush(): WebPushService
{
    $webPush = Mockery::mock(WebPushService::class);
    $webPush->shouldReceive('sendToSubscriptions')->andReturnNull();

    return $webPush;
}

it('a phone switched off on the users page gets no push at all', function () {
    appPrefsFakeExpo();
    $user = User::factory()->create();
    PushToken::factory()->create(['user_id' => $user->id, 'enabled' => false]);

    AppNotification::create(['user_id' => $user->id, 'type' => 'missed_call', 'title' => 'Missed call', 'body' => 'x', 'action_url' => '/messages?activeTab=calls&callId=1']);
    AppNotification::create(['user_id' => $user->id, 'type' => 'sms_received', 'title' => 'New Text', 'body' => 'x', 'action_url' => '/messages?threadId=1']);

    Http::assertNothingSent();
});

it('a client\'s text only reaches phones with text activity on; a team message and a missed call ignore that switch', function () {
    appPrefsFakeExpo();
    $user = User::factory()->create();
    PushToken::factory()->create(['user_id' => $user->id, 'token' => 'ExponentPushToken[texts-on]', 'sms_inbound_enabled' => true]);
    PushToken::factory()->create(['user_id' => $user->id, 'token' => 'ExponentPushToken[texts-off]', 'sms_inbound_enabled' => false]);

    AppNotification::create(['user_id' => $user->id, 'type' => 'sms_received', 'title' => 'New Text from Jane', 'body' => 'Hi', 'action_url' => '/messages?threadId=1']);
    AppNotification::create(['user_id' => $user->id, 'type' => 'team_message', 'title' => 'Greg', 'body' => 'On my way', 'action_url' => '/messages?threadId=2']);
    AppNotification::create(['user_id' => $user->id, 'type' => 'missed_call', 'title' => 'Missed call', 'body' => 'x', 'action_url' => '/messages?activeTab=calls&callId=1']);

    expect(appPrefsRecipients('sms_received'))->toBe(['ExponentPushToken[texts-on]'])
        ->and(appPrefsRecipients('team_message'))->toBe(['ExponentPushToken[texts-off]', 'ExponentPushToken[texts-on]'])
        ->and(appPrefsRecipients('missed_call'))->toBe(['ExponentPushToken[texts-off]', 'ExponentPushToken[texts-on]']);
});

it('an inbound text makes a notification row for a user whose only text-activity device is the app', function () {
    appPrefsFakeExpo();
    $client = Client::factory()->create(['business_name' => 'Jane Smith']);
    $thread = SmsGroupThread::create(['kind' => SmsGroupThread::KIND_SMS, 'from_number' => '+12247354200', 'participants' => ['+13125550199'], 'client_id' => $client->id]);
    $message = SmsMessage::create(['thread_id' => $thread->id, 'direction' => 'inbound', 'from_number' => '+13125550199', 'to_numbers' => ['+12247354200'], 'text' => 'Can you stop by Tuesday?', 'status' => 'received']);

    $appOnly = User::factory()->create();
    PushToken::factory()->create(['user_id' => $appOnly->id, 'token' => 'ExponentPushToken[app-only]']);
    $appOff = User::factory()->create();
    PushToken::factory()->create(['user_id' => $appOff->id, 'sms_inbound_enabled' => false]);
    $phoneOff = User::factory()->create();
    PushToken::factory()->create(['user_id' => $phoneOff->id, 'enabled' => false]);
    $browser = User::factory()->create();
    PushSubscription::create(['user_id' => $browser->id, 'endpoint' => 'https://push.example/b', 'p256dh' => 'k', 'auth' => 'a', 'sms_inbound_enabled' => true, 'realtime_enabled' => true]);

    (new SendInboundSmsBrowserNotifications($message->id))->handle(appPrefsSilentWebPush(), app(AppDevicePush::class));

    expect(AppNotification::where('type', 'sms_received')->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$appOnly->id, $browser->id])->sort()->values()->all())
        ->and(appPrefsRecipients('sms_received'))->toBe(['ExponentPushToken[app-only]']);
});

it('a teammate\'s reply on a client thread is pushed to the other phones with text activity on, never the sender\'s', function () {
    appPrefsFakeExpo();
    appPrefsVendor();
    $client = Client::factory()->create(['business_name' => 'Jane Smith']);
    $thread = SmsGroupThread::create(['kind' => SmsGroupThread::KIND_SMS, 'from_number' => '+12247354200', 'participants' => ['+13125550199'], 'client_id' => $client->id]);

    $sender = User::factory()->create(['first_name' => 'Patryk']);
    PushToken::factory()->create(['user_id' => $sender->id, 'token' => 'ExponentPushToken[sender]']);
    $greg = User::factory()->create();
    PushToken::factory()->create(['user_id' => $greg->id, 'token' => 'ExponentPushToken[greg]']);
    $textsOff = User::factory()->create();
    PushToken::factory()->create(['user_id' => $textsOff->id, 'sms_inbound_enabled' => false]);

    $message = SmsMessage::create(['thread_id' => $thread->id, 'direction' => 'outbound', 'from_number' => '+12247354200', 'to_numbers' => ['+13125550199'], 'text' => 'On my way', 'status' => 'sent', 'sent_by_user_id' => $sender->id]);

    (new SendOutboundSmsBrowserNotifications($message->id, $sender->id))->handle(appPrefsSilentWebPush(), app(AppDevicePush::class));

    $pushes = collect(appPrefsMessages())->where('data.type', 'sms_reply')->values();
    expect($pushes->pluck('to')->all())->toBe(['ExponentPushToken[greg]'])
        ->and($pushes->first()['title'])->toBe('Patryk replied to Jane Smith')
        ->and($pushes->first()['body'])->toBe('On my way')
        ->and($pushes->first()['threadId'])->toBe((string) $thread->id)
        ->and($pushes->first()['categoryId'])->toBe('message')
        ->and($pushes->first()['data']['target'])->toBe(['type' => 'thread', 'id' => $thread->id, 'path' => null, 'title' => null])
        ->and(AppNotification::count())->toBe(0);
});

it('the morning digest reaches phones with the morning switch on, counting that user\'s own tasks', function () {
    Carbon::setTestNow('2026-11-10 07:00:00');
    Queue::fake();
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-1']]])]);
    Http::preventStrayRequests();
    appPrefsVendor();

    $busy = User::factory()->create(['primary_vendor_id' => 1]);
    PushToken::factory()->create(['user_id' => $busy->id, 'token' => 'ExponentPushToken[busy]']);
    $optedOut = User::factory()->create();
    PushToken::factory()->create(['user_id' => $optedOut->id, 'token' => 'ExponentPushToken[opted-out]', 'morning_enabled' => false]);
    $idle = User::factory()->create();
    PushToken::factory()->create(['user_id' => $idle->id, 'token' => 'ExponentPushToken[idle]']);

    $this->actingAs($busy);
    $project = Project::factory()->create(['client_id' => Client::factory()->create()->id]);
    $today = Carbon::today()->format('Y-m-d');
    Task::create(['title' => 'Tile the bathroom', 'project_id' => $project->id, 'type' => 'Task', 'start_date' => $today, 'end_date' => $today, 'order' => 0, 'user_ids' => [$busy->id, $optedOut->id]]);
    Task::create(['title' => 'Hang drywall', 'project_id' => $project->id, 'type' => 'Task', 'start_date' => $today, 'end_date' => $today, 'order' => 1, 'user_ids' => [$busy->id]]);

    (new SendDigestNotifications('morning'))->handle(app(TaskNotificationService::class));

    $pushes = collect(appPrefsMessages())->where('data.type', 'task_digest')->values();
    expect($pushes->pluck('to')->all())->toBe(['ExponentPushToken[busy]'])
        ->and($pushes->first()['title'])->toBe("Today's Tasks")
        ->and($pushes->first()['body'])->toBe('You have 2 tasks scheduled for today.')
        ->and($pushes->first()['data']['action_url'])->toBe('/hub');

    Carbon::setTestNow();
});

it('a schedule change reaches phones with the realtime switch on, once the change gate has a baseline', function () {
    // 10:00 in Chicago: inside the realtime window, like a real change.
    Carbon::setTestNow('2026-11-10 16:00:00');
    Queue::fake();
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-1']]])]);
    Http::preventStrayRequests();
    appPrefsVendor();

    $user = User::factory()->create(['primary_vendor_id' => 1]);
    PushToken::factory()->create(['user_id' => $user->id, 'token' => 'ExponentPushToken[realtime-on]']);
    PushToken::factory()->create(['user_id' => $user->id, 'token' => 'ExponentPushToken[realtime-off]', 'realtime_enabled' => false]);

    $this->actingAs($user);
    $project = Project::factory()->create(['client_id' => Client::factory()->create()->id]);
    $today = Carbon::today()->format('Y-m-d');
    Task::create(['title' => 'Tile the bathroom', 'project_id' => $project->id, 'type' => 'Task', 'start_date' => $today, 'end_date' => $today, 'order' => 0, 'user_ids' => [$user->id]]);

    // The first check of a day only records a baseline (the morning digest
    // covers it); a change is a hash that differs from the recorded one.
    Cache::put("push_tasks_hash:{$user->id}:{$today}", 'yesterday', now()->endOfDay());

    (new SendRealtimeTaskNotification($project->id, [$user->id]))->handle(app(TaskNotificationService::class));

    $pushes = collect(appPrefsMessages())->where('data.type', 'schedule_updated')->values();
    expect($pushes->pluck('to')->all())->toBe(['ExponentPushToken[realtime-on]'])
        ->and($pushes->first()['title'])->toBe('Schedule Updated')
        ->and($pushes->first()['body'])->toBe('Your schedule for today has been updated. You have 1 task.');

    Carbon::setTestNow();
});

it('shouldNotify answers for the app channel from the phones\' switches', function () {
    $user = User::factory()->create();
    $service = app(TaskNotificationService::class);

    expect($service->shouldNotify($user, 'app', 'morning'))->toBeFalse();

    $phone = PushToken::factory()->create(['user_id' => $user->id, 'morning_enabled' => true, 'evening_enabled' => false]);

    expect($service->shouldNotify($user, 'app', 'morning'))->toBeTrue()
        ->and($service->shouldNotify($user, 'app', 'evening'))->toBeFalse()
        ->and($service->shouldNotify($user, 'app', 'realtime'))->toBeTrue();

    $phone->update(['enabled' => false]);

    expect($service->shouldNotify($user, 'app', 'morning'))->toBeFalse();
});
