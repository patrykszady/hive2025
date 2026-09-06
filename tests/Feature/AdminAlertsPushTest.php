<?php

use App\Jobs\SendBrowserNotificationsToUsers;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\WebPushService;
use App\Support\AdminAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function pushUser(string $tag): User
{
    return User::query()->create([
        'first_name' => 'Push', 'last_name' => $tag, 'email' => "push-{$tag}-".uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224777####'), 'password' => bcrypt('password'),
    ]);
}

it('queues one browser push for the given admins', function () {
    Queue::fake();
    $a = pushUser('a');
    $b = pushUser('b');

    AdminAlerts::push([$a->id, $b->id, $a->id], 'lead_times_picked', 'Jon Corteen picked consultation times', 'Tue, Sep 9 · 7-9 AM', 'https://hive.test/leads?lead=162');

    Queue::assertPushed(SendBrowserNotificationsToUsers::class, function (SendBrowserNotificationsToUsers $job) use ($a, $b) {
        return $job->userIds === [$a->id, $b->id]
            && $job->payload['title'] === 'Jon Corteen picked consultation times'
            && $job->payload['body'] === 'Tue, Sep 9 · 7-9 AM'
            && $job->payload['data']['url'] === 'https://hive.test/leads?lead=162'
            && str_starts_with($job->payload['tag'], 'lead_times_picked-');
    });
});

it('pushes to every subscribed device that has realtime alerts on, and nobody else', function () {
    $admin = pushUser('admin');
    $other = pushUser('other');
    $muted = pushUser('muted');
    $make = fn (User $u, bool $realtime) => PushSubscription::query()->create([
        'user_id' => $u->id, 'endpoint' => 'https://push.example.com/'.uniqid(), 'p256dh' => 'k', 'auth' => 'a', 'realtime_enabled' => $realtime,
    ]);
    $make($admin, true);
    $make($admin, true);
    $make($muted, false);
    $make($other, true);

    $sent = [];
    $this->mock(WebPushService::class, function ($mock) use (&$sent) {
        $mock->shouldReceive('sendToSubscriptions')->once()
            ->andReturnUsing(function ($subscriptions, array $payload) use (&$sent) {
                $sent = ['users' => $subscriptions->pluck('user_id')->unique()->values()->all(), 'count' => $subscriptions->count(), 'payload' => $payload];
            });
    });

    (new SendBrowserNotificationsToUsers([$admin->id, $muted->id], ['title' => 'T', 'body' => 'B', 'tag' => 'x', 'data' => ['url' => '/planner']]))
        ->handle(app(WebPushService::class));

    expect($sent['users'])->toBe([$admin->id])
        ->and($sent['count'])->toBe(2)
        ->and($sent['payload']['title'])->toBe('T')
        ->and($sent['payload']['data']['url'])->toBe('/planner')
        ->and($sent['payload']['icon'])->toBe('/favicons/icon-192x192.png');
});

it('sends nothing when no target device is subscribed', function () {
    $this->mock(WebPushService::class, fn ($mock) => $mock->shouldReceive('sendToSubscriptions')->never());

    (new SendBrowserNotificationsToUsers([pushUser('none')->id], ['title' => 'T', 'body' => 'B']))->handle(app(WebPushService::class));
});
