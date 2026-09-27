<?php

use App\Services\GoogleSearchConsoleService;

/**
 * SearchConsoleClient's isReady()/isAuthStandingCondition() hooks — added
 * to the contract by kit/search-console-contract. This site has no
 * separate OAuth grant the way gs.construction's/jpeterson-design's
 * services do (a service account has no such intermediate state), so
 * isReady() is simply isConfigured() — the plan's own decision for every
 * service-account-authenticated adopter (hive.contractors,
 * dawnsellshomes.com). isAuthStandingCondition() mirrors the same
 * [401,403,404] rule the OAuth-flavor sites use, since describeFailure()
 * already turns each of those into an owner setup instruction.
 */
it('is not ready with no credential configured', function () {
    config([
        'services.google.search_console_credentials' => null,
        'services.google.search_console_property' => 'sc-domain:hive.contractors',
    ]);

    expect(app(GoogleSearchConsoleService::class)->isReady())->toBeFalse();
});

it('is ready once a credential and a property are both configured', function () {
    config([
        'services.google.search_console_credentials' => json_encode([
            'type' => 'service_account',
            'client_email' => 'sa@example.iam.gserviceaccount.com',
            'private_key' => 'not-a-real-key',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]),
        'services.google.search_console_property' => 'sc-domain:hive.contractors',
    ]);

    expect(app(GoogleSearchConsoleService::class)->isReady())->toBeTrue();
});

it('treats 401, 403 and 404 as standing conditions', function () {
    $service = app(GoogleSearchConsoleService::class);

    expect($service->isAuthStandingCondition(['status' => 401, 'message' => 'x']))->toBeTrue();
    expect($service->isAuthStandingCondition(['status' => 403, 'message' => 'x']))->toBeTrue();
    expect($service->isAuthStandingCondition(['status' => 404, 'message' => 'x']))->toBeTrue();
});

it('does not treat a 5xx or no error at all as a standing condition', function () {
    $service = app(GoogleSearchConsoleService::class);

    expect($service->isAuthStandingCondition(['status' => 500, 'message' => 'x']))->toBeFalse();
    expect($service->isAuthStandingCondition(null))->toBeFalse();
});
