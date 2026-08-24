<?php

use App\Http\Controllers\MenardsSolveChallengeController;
use App\Http\Controllers\MenardsSyncStatusController;
use App\Services\MenardsCaptchaSolver;
use Illuminate\Support\Facades\Cache;

/**
 * The two endpoints the server-side browser talks back through: one reporting
 * how a sync went, one buying an hCaptcha token for the Imperva wall.
 *
 * The spend cap is the test that matters most here. Every solve costs money,
 * and a wall that is refusing the BROWSER re-challenges forever — so the guard
 * against an overnight loop is worth more than the happy path.
 */
beforeEach(function () {
    config(['services.menards.bridge_token' => 'test-bridge-token']);
    Cache::forget(MenardsSolveChallengeController::COUNTER_KEY);
    Cache::forget(MenardsSyncStatusController::CACHE_KEY);
});

function solvePayload(array $overrides = []): array
{
    return array_merge([
        'siteKey' => '10000000-ffff-ffff-ffff-000000000001',
        'pageUrl' => 'https://www.menards.com/main/login.html',
    ], $overrides);
}

it('refuses a solve without the bridge token', function () {
    $this->postJson('/api/menards/solve-challenge', solvePayload())
        ->assertStatus(401);
});

it('refuses a solve with the wrong bridge token', function () {
    $this->withHeader('Authorization', 'Bearer wrong')
        ->postJson('/api/menards/solve-challenge', solvePayload())
        ->assertStatus(401);
});

it('stops spending once the hourly cap is reached', function () {
    // Pre-spend the cap. No real solve is attempted, which is the point:
    // proving the guard must not itself cost money.
    Cache::put(MenardsSolveChallengeController::COUNTER_KEY, 4, now()->addHour());

    // If the cap were not honoured this would reach the solver, so a strict
    // mock doubles as the assertion that no purchase was attempted.
    $this->mock(MenardsCaptchaSolver::class, function ($mock) {
        $mock->shouldReceive('configured')->andReturn(true);
        $mock->shouldNotReceive('solve');
    });

    $this->withHeader('Authorization', 'Bearer test-bridge-token')
        ->postJson('/api/menards/solve-challenge', solvePayload())
        ->assertStatus(429);
});

it('counts an attempt even when the solve fails, so a refused wall cannot loop', function () {
    $this->mock(MenardsCaptchaSolver::class, function ($mock) {
        $mock->shouldReceive('configured')->andReturn(true);
        $mock->shouldReceive('solve')->once()->andReturn(['ok' => false, 'error' => 'ERROR_CAPTCHA_UNSOLVABLE']);
    });

    $this->withHeader('Authorization', 'Bearer test-bridge-token')
        ->postJson('/api/menards/solve-challenge', solvePayload())
        ->assertStatus(502);

    // 2captcha charges for solves Imperva then rejects — so the counter has to
    // move on failure, not just on success.
    expect(Cache::get(MenardsSolveChallengeController::COUNTER_KEY))->toBe(1);
});

it('returns the token when a solve succeeds', function () {
    $this->mock(MenardsCaptchaSolver::class, function ($mock) {
        $mock->shouldReceive('configured')->andReturn(true);
        $mock->shouldReceive('solve')->once()->andReturn(['ok' => true, 'token' => 'P0_ey-token', 'seconds' => 21.5]);
    });

    $this->withHeader('Authorization', 'Bearer test-bridge-token')
        ->postJson('/api/menards/solve-challenge', solvePayload())
        ->assertOk()
        ->assertJson(['ok' => true, 'token' => 'P0_ey-token']);
});

it('records an expired session so the server stops claiming it is signed in', function () {
    $this->withHeader('Authorization', 'Bearer test-bridge-token')
        ->postJson('/api/menards/sync-status', [
            'ok' => false,
            'error' => 'initialize.ajx returned HTML — the browser session has expired, sign in again.',
        ])
        ->assertOk();

    $status = Cache::get(MenardsSyncStatusController::CACHE_KEY);

    expect($status['session_expired'])->toBeTrue()
        ->and($status['ok'])->toBeFalse();
});

it('does not mark an ordinary sync failure as an expired session', function () {
    // A download that failed is not a dead session, and treating it as one
    // would send `ensure` off to re-login — a navigation, which is the single
    // thing most likely to draw the challenge.
    $this->withHeader('Authorization', 'Bearer test-bridge-token')
        ->postJson('/api/menards/sync-status', ['ok' => false, 'error' => 'a receipt PDF failed to download'])
        ->assertOk();

    expect(Cache::get(MenardsSyncStatusController::CACHE_KEY)['session_expired'])->toBeFalse();
});

it('records a healthy sync', function () {
    $this->withHeader('Authorization', 'Bearer test-bridge-token')
        ->postJson('/api/menards/sync-status', ['ok' => true, 'receipts' => 13])
        ->assertOk();

    $status = Cache::get(MenardsSyncStatusController::CACHE_KEY);

    expect($status['ok'])->toBeTrue()
        ->and($status['receipts'])->toBe(13)
        ->and($status['session_expired'])->toBeFalse();
});
