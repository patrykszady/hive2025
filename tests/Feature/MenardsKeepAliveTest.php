<?php

use App\Console\Commands\MenardsBrowser;
use App\Services\MenardsRemoteBrowserService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Menards drops an idle session within about an hour (2026-10-01: signed in
 * at 23:21, the 00:31 sync got 401), and each new sign-in draws Imperva's
 * hCaptcha. `menards:browser keepalive` makes one receipt-API call from the
 * parked tab every 15 minutes, never a navigation, and steps aside whenever
 * the browser is someone else's to drive.
 */
function keepAliveLogger(): Mockery\MockInterface
{
    $logger = Mockery::spy();
    Log::shouldReceive('channel')->with('menards')->andReturn($logger);

    return $logger;
}

it('pings the session and logs only when the answer changes', function () {
    $logger = keepAliveLogger();
    $this->mock(MenardsRemoteBrowserService::class, function ($mock) {
        $mock->shouldReceive('looksLikeChallengeWall')->andReturn(false);
        $mock->shouldReceive('keepSessionAlive')->times(3)->andReturn(
            ['ok' => true, 'stage' => 'called', 'status' => 200, 'json' => true],
            ['ok' => true, 'stage' => 'called', 'status' => 200, 'json' => true],
            ['ok' => true, 'stage' => 'called', 'status' => 401, 'json' => false],
        );
    });

    $this->artisan('menards:browser', ['action' => 'keepalive'])->expectsOutputToContain('Session alive')->assertSuccessful();
    $this->artisan('menards:browser', ['action' => 'keepalive'])->expectsOutputToContain('Session alive')->assertSuccessful();
    $this->artisan('menards:browser', ['action' => 'keepalive'])->expectsOutputToContain('Session expired (401)')->assertSuccessful();

    $logger->shouldHaveReceived('info')->with('Menards keep-alive: alive', Mockery::any())->once();
    $logger->shouldHaveReceived('warning')->with('Menards keep-alive: expired', Mockery::any())->once();
    expect(Cache::get(MenardsBrowser::KEEPALIVE_STATE_KEY)['state'])->toBe('expired');
});

it('reads an HTML answer as Imperva in the way, not a live session', function () {
    keepAliveLogger();
    $this->mock(MenardsRemoteBrowserService::class, function ($mock) {
        $mock->shouldReceive('looksLikeChallengeWall')->andReturn(false);
        $mock->shouldReceive('keepSessionAlive')->once()->andReturn(['ok' => true, 'stage' => 'called', 'status' => 200, 'json' => false]);
    });

    $this->artisan('menards:browser', ['action' => 'keepalive'])
        ->expectsOutputToContain('Imperva may be challenging it')
        ->assertSuccessful();
});

it('steps aside when the browser is not its to drive', function (string $case) {
    keepAliveLogger();

    match ($case) {
        'switched off' => config(['services.menards.keepalive' => false]),
        'a sign-in is owed' => Cache::put(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY, ['reason' => 'challenge', 'at' => now()->toIso8601String()], 600),
        'a sync is running' => Cache::put(MenardsRemoteBrowserService::SYNC_IN_FLIGHT_KEY, now()->toIso8601String(), 300),
        'a sign-in is running' => Cache::lock(MenardsRemoteBrowserService::SIGNIN_LOCK, 120)->get(),
        'the wall is on screen' => null,
    };

    $this->mock(MenardsRemoteBrowserService::class, function ($mock) use ($case) {
        $mock->shouldReceive('looksLikeChallengeWall')->andReturn($case === 'the wall is on screen');
        $mock->shouldReceive('keepSessionAlive')->never();
    });

    $this->artisan('menards:browser', ['action' => 'keepalive'])->assertSuccessful();

    expect(Cache::get(MenardsBrowser::KEEPALIVE_STATE_KEY))->toBeNull();
})->with(['switched off', 'a sign-in is owed', 'a sync is running', 'a sign-in is running', 'the wall is on screen']);

it('runs every 15 minutes in production only', function () {
    $event = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'menards:browser keepalive'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/15 * * * *')
        ->and($event->environments)->toBe(['production']);
});
