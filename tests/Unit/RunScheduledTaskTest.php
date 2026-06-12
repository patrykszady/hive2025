<?php

use App\Jobs\RunScheduledTask;

it('applies configured queue execution options when provided', function () {
    $job = new RunScheduledTask(
        controllerClass: 'App\\Http\\Controllers\\ReceiptController',
        method: 'amazon_orders_api',
        arguments: [],
        label: null,
        configuredTries: 1,
        configuredTimeout: 5400,
        configuredUniqueFor: 7200,
    );

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(5400)
        ->and($job->uniqueFor)->toBe(7200)
        ->and($job->uniqueId())->toBe('App\\Http\\Controllers\\ReceiptController@amazon_orders_api');
});

it('keeps default queue execution options when none are provided', function () {
    $job = new RunScheduledTask(
        controllerClass: 'App\\Http\\Controllers\\ReceiptController',
        method: 'amazon_orders_api',
    );

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(1800)
        ->and($job->uniqueFor)->toBe(1800);
});
