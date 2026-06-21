<?php

use Illuminate\Support\Arr;

it('keeps redis retry_after greater than every horizon supervisor timeout', function () {
    $retryAfter = (int) config('queue.connections.redis.retry_after');

    $timeouts = collect(config('horizon.defaults'))
        ->pluck('timeout')
        ->filter(fn ($timeout) => $timeout !== null)
        ->map(fn ($timeout) => (int) $timeout);

    expect($timeouts)->not->toBeEmpty();

    $maxTimeout = $timeouts->max();

    expect($retryAfter)->toBeGreaterThan(
        $maxTimeout,
        "queue.connections.redis.retry_after ({$retryAfter}) must exceed the longest Horizon supervisor timeout ({$maxTimeout}) to avoid 'attempted too many times' failures."
    );
});

it('keeps redis retry_after greater than the ForwardCompanyEmailReceipts job timeout', function () {
    $retryAfter = (int) config('queue.connections.redis.retry_after');
    $jobTimeout = (new ReflectionClass(App\Jobs\ForwardCompanyEmailReceipts::class))
        ->getDefaultProperties()['timeout'];

    expect($retryAfter)->toBeGreaterThan((int) $jobTimeout);
});
