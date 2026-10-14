<?php

use App\Services\MenardsRemoteBrowserService;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * A Menards sign-in fetches receipts right away instead of at the next
 * window (Patryk, 2026-10-14), once per half hour, and not when a sync is
 * the one signing in.
 */
function signInLanded(): void
{
    (new ReflectionMethod(MenardsRemoteBrowserService::class, 'markSignedIn'))->invoke(new MenardsRemoteBrowserService);
}

beforeEach(function (): void {
    Queue::fake();
    Cache::flush();
});

it('queues a receipt sync right after a sign-in, and clears the needs-sign-in alert', function (): void {
    Cache::put(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY, 'challenge');

    signInLanded();

    Queue::assertPushedOn('background', CallQueuedClosure::class);
    Queue::assertPushed(CallQueuedClosure::class, 1);
    expect(Cache::has(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY))->toBeFalse();
});

it('asks for one sync per half hour however many sign-ins land', function (): void {
    signInLanded();
    signInLanded();

    Queue::assertPushed(CallQueuedClosure::class, 1);
});

it('queues nothing when a sync is the one signing in', function (): void {
    Cache::put(MenardsRemoteBrowserService::SYNC_IN_FLIGHT_KEY, now()->toIso8601String(), now()->addMinutes(5));

    signInLanded();

    Queue::assertNothingPushed();
});
