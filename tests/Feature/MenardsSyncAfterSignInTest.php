<?php

use App\Services\MenardsRemoteBrowserService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * A Menards sign-in no longer fetches receipts straight away. Tried on
 * 2026-10-14: the receipt call 35 seconds after the 07:30 sign-in met
 * Imperva's wall, and the sign-in that followed stuck at the hCaptcha
 * (2026-10-15). The next scheduled window fetches instead.
 */
function signInLanded(): void
{
    (new ReflectionMethod(MenardsRemoteBrowserService::class, 'markSignedIn'))->invoke(new MenardsRemoteBrowserService);
}

beforeEach(function (): void {
    Queue::fake();
    Cache::flush();
});

it('clears the needs-sign-in alert after a sign-in and queues no immediate receipt sync', function (): void {
    Cache::put(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY, 'challenge');

    signInLanded();

    Queue::assertNothingPushed();
    expect(Cache::has(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY))->toBeFalse();
});
