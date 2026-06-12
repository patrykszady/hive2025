<?php

use App\Support\AmazonOAuthPayload;

it('builds amazon refresh token payload without access_token field', function () {
    config()->set('services.amazon.client_id', 'test-client-id');
    config()->set('services.amazon.client_secret', 'test-client-secret');

    $payload = AmazonOAuthPayload::refreshToken('test-refresh-token');

    expect($payload)->toBe([
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'refresh_token' => 'test-refresh-token',
        'grant_type' => 'refresh_token',
    ])->and($payload)->not->toHaveKey('access_token');
});
