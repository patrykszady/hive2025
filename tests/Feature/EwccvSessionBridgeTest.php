<?php

use App\Http\Controllers\EwccvSessionController;
use Illuminate\Support\Facades\Cache;

/**
 * The browser extension hands over an EWCCV search session because the server
 * cannot pass ewccv.com's reCAPTCHA v3 itself. That makes this endpoint a
 * credential sink, so its auth is worth pinning.
 */
beforeEach(function () {
    config()->set('services.ewccv.bridge_token', 'test-bridge-token-abc123');
    Cache::forget(EwccvSessionController::CACHE_KEY);
});

it('stores a session handed over with the right bearer token', function () {
    $response = $this->withHeader('Authorization', 'Bearer test-bridge-token-abc123')
        ->postJson('/api/ewccv/session', [
            'accessToken' => str_repeat('a', 120),
            'jwt' => 'the-login-jwt',
            'stateToken' => '8b095d21-fd2b-4901-b7da-624416e3e114',
        ]);

    $response->assertOk()->assertJson(['ok' => true]);

    $stored = Cache::get(EwccvSessionController::CACHE_KEY);

    expect($stored)->toBeArray()
        ->and($stored['access_token'])->toBe(str_repeat('a', 120))
        ->and($stored['jwt'])->toBe('the-login-jwt')
        ->and($stored['received_at'])->not->toBeEmpty();
});

it('rejects a wrong or missing bearer token and stores nothing', function () {
    $this->withHeader('Authorization', 'Bearer not-the-token')
        ->postJson('/api/ewccv/session', ['accessToken' => str_repeat('a', 120)])
        ->assertStatus(401);

    $this->postJson('/api/ewccv/session', ['accessToken' => str_repeat('a', 120)])
        ->assertStatus(401);

    expect(Cache::get(EwccvSessionController::CACHE_KEY))->toBeNull();
});

it('refuses to accept sessions when the server has no bridge token configured', function () {
    // Otherwise an unconfigured server would treat an empty secret as a match.
    config()->set('services.ewccv.bridge_token', null);

    $this->withHeader('Authorization', 'Bearer ')
        ->postJson('/api/ewccv/session', ['accessToken' => str_repeat('a', 120)])
        ->assertStatus(503);

    expect(Cache::get(EwccvSessionController::CACHE_KEY))->toBeNull();
});

it('validates the payload', function () {
    $this->withHeader('Authorization', 'Bearer test-bridge-token-abc123')
        ->postJson('/api/ewccv/session', ['accessToken' => 'tooshort'])
        ->assertStatus(422);
});
