<?php

use App\Jobs\SendCallAnsweredBrowserNotifications;
use App\Jobs\SendIncomingCallBrowserNotifications;
use App\Models\CallLog;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\Vendor;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Both call notifications pointed at /calls, which is not a route — the calls
 * list is a TAB on /messages. Clicking one landed on a 404. These pin the
 * deep link, including the ?callId= that opens the specific call.
 */
function makeCallNotificationFixture(): array
{
    $user = User::query()->create([
        'first_name' => 'Call',
        'last_name' => 'Recipient',
        'email' => 'call.recipient.'.uniqid().'@example.com',
        'cell_phone' => fake()->unique()->numerify('224777####'),
    ]);

    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1]);
    $vendor->options = ['call_recipients' => [$user->id]];
    $vendor->save();

    PushSubscription::query()->create([
        'user_id' => $user->id,
        'endpoint' => 'https://push.example.com/'.uniqid(),
        'p256dh' => 'test-p256dh',
        'auth' => 'test-auth',
        'sms_inbound_enabled' => true,
    ]);

    $call = CallLog::query()->create([
        'direction' => 'incoming',
        'from_number' => '+12245550001',
        'to_number' => '+12245554444',
        'status' => 'ringing',
    ]);

    return ['user' => $user, 'call' => $call];
}

it('links an incoming-call notification to the call, not to /calls', function (): void {
    ['call' => $call] = makeCallNotificationFixture();

    $payloads = [];
    $this->mock(WebPushService::class, function ($mock) use (&$payloads) {
        $mock->shouldReceive('sendToSubscriptions')
            ->andReturnUsing(function ($subscriptions, array $payload) use (&$payloads) {
                $payloads[] = $payload;
            });
    });

    (new SendIncomingCallBrowserNotifications($call->id))->handle(app(WebPushService::class));

    expect($payloads)->not->toBeEmpty();

    foreach ($payloads as $payload) {
        $url = $payload['data']['url'] ?? null;

        expect($url)->not->toBe('/calls')
            ->and($url)->toBe("/messages?activeTab=calls&callId={$call->id}");
    }
});

it('links an answered-call notification to the call too', function (): void {
    ['user' => $user, 'call' => $call] = makeCallNotificationFixture();

    // This job notifies the OTHER admins, never the one who answered — so
    // the answerer has to be someone else or nothing is sent at all.
    $answerer = User::query()->create([
        'first_name' => 'Someone',
        'last_name' => 'Else',
        'email' => 'answerer.'.uniqid().'@example.com',
        'cell_phone' => fake()->unique()->numerify('224888####'),
    ]);

    $payloads = [];
    $this->mock(WebPushService::class, function ($mock) use (&$payloads) {
        $mock->shouldReceive('sendToSubscriptions')
            ->andReturnUsing(function ($subscriptions, array $payload) use (&$payloads) {
                $payloads[] = $payload;
            });
    });

    (new SendCallAnsweredBrowserNotifications($call->id, $answerer->id))->handle(app(WebPushService::class));

    expect($payloads)->not->toBeEmpty();

    foreach ($payloads as $payload) {
        expect($payload['data']['url'] ?? null)->toBe("/messages?activeTab=calls&callId={$call->id}");
    }
});

it('routes that deep link to the messages page', function (): void {
    // The URL is only useful if it resolves — /calls did not.
    $request = Illuminate\Http\Request::create('/messages?activeTab=calls&callId=1', 'GET');

    expect(app('router')->getRoutes()->match($request)->getName())->toBe('sms.index');
});
