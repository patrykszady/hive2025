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
    config(['services.menards.bridge_token' => 'test-bridge-token', 'services.menards.auto_solve' => true]);
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

it('spends nothing while automatic solving is off, which is the default', function () {
    config(['services.menards.auto_solve' => false]);
    $this->mock(\App\Services\MenardsCaptchaSolver::class); // any call would fail the test
    Cache::forget(MenardsSolveChallengeController::COUNTER_KEY);

    $this->withToken('test-bridge-token')
        ->postJson('/api/menards/solve-challenge', ['siteKey' => str_repeat('a', 36), 'pageUrl' => 'https://www.menards.com/main/login.html'])
        ->assertStatus(403)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error', fn ($e) => str_contains($e, 'MENARDS_AUTO_SOLVE'));

    expect(Cache::get(MenardsSolveChallengeController::COUNTER_KEY))->toBeNull();
});

it('hands the extension the auto-solve setting in defaults.json', function () {
    config(['services.menards.bridge_token' => 'test-bridge-token', 'app.url' => 'https://hive.test/', 'services.menards.auto_solve' => false]);
    $dir = sys_get_temp_dir() . '/menards-ext-' . uniqid();
    mkdir($dir);

    $browser = Mockery::mock(\App\Services\MenardsRemoteBrowserService::class)->makePartial();
    $browser->shouldReceive('extensionDir')->andReturn($dir);
    $browser->writeExtensionDefaults();

    $defaults = json_decode(file_get_contents($dir . '/defaults.json'), true);

    expect($defaults)->toMatchArray(['serverUrl' => 'https://hive.test', 'token' => 'test-bridge-token', 'solveChallenges' => false]);

    @unlink($dir . '/defaults.json');
    @rmdir($dir);
});

/*
 * NoneCap (2026-10-22): the one hCaptcha solver still selling after 2Captcha,
 * Anti-Captcha and CapSolver dropped it. Unlike September's 2captcha tokens,
 * the solve runs through our proxy as our browser.
 */
function noneCapConfig(): void
{
    config([
        'services.menards.nonecap_key' => 'nc_live_test',
        'services.menards.twocaptcha_key' => null,
        'services.menards.proxy_enabled' => true,
        'services.menards.proxy_host' => '141.140.4.3:12323',
        'services.menards.proxy_username' => 'gs-user',
        'services.menards.proxy_password' => 'p@ss',
        'services.menards.proxy_region' => '',
        'services.menards.proxy_session' => '',
    ]);
}

it('buys from NoneCap through our proxy with the browser\'s user agent', function () {
    noneCapConfig();
    $agent = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36';
    \Illuminate\Support\Facades\Http::fake(['api.nonecap.com/*' => \Illuminate\Support\Facades\Http::response([
        'id' => 'solve_01J', 'status' => 'solved', 'token' => 'P1_eyJ0eXAi', 'user_agent' => $agent, 'credits_charged' => 3, 'resolve_ms' => 14200,
    ])]);

    $result = app(MenardsCaptchaSolver::class)->solve('10000000-ffff-ffff-ffff-000000000001', 'https://www.menards.com/main/checkcredentials.html', $agent);

    expect($result['ok'])->toBeTrue()->and($result['token'])->toBe('P1_eyJ0eXAi');

    \Illuminate\Support\Facades\Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.nonecap.com/v1/solves?wait=90')
        && $request->hasHeader('Authorization', 'Bearer nc_live_test')
        && $request['type'] === 'hcaptcha'
        && $request['sitekey'] === '10000000-ffff-ffff-ffff-000000000001'
        && $request['url'] === 'https://www.menards.com/main/checkcredentials.html'
        && $request['user_agent'] === $agent
        && $request['proxy'] === 'http://gs-user:p%40ss@141.140.4.3:12323');
});

it('reports a refused NoneCap solve in NoneCap\'s own words', function () {
    noneCapConfig();
    \Illuminate\Support\Facades\Http::fake(['api.nonecap.com/*' => \Illuminate\Support\Facades\Http::response(['error' => 'insufficient_credits'], 402)]);

    $result = app(MenardsCaptchaSolver::class)->solve('10000000-ffff-ffff-ffff-000000000001', 'https://www.menards.com/main/login.html');

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('HTTP 402')
        ->and($result['error'])->toContain('insufficient_credits');
});

it('passes the browser\'s user agent from the extension\'s request to the solver', function () {
    $this->mock(MenardsCaptchaSolver::class, function ($mock) {
        $mock->shouldReceive('configured')->andReturn(true);
        $mock->shouldReceive('solve')->once()
            ->with('10000000-ffff-ffff-ffff-000000000001', 'https://www.menards.com/main/login.html', 'Chrome/151 test agent')
            ->andReturn(['ok' => true, 'token' => 'P1_token', 'seconds' => 12.0]);
    });

    $this->withHeaders(['Authorization' => 'Bearer test-bridge-token', 'User-Agent' => 'Chrome/151 test agent'])
        ->postJson('/api/menards/solve-challenge', solvePayload())
        ->assertSuccessful();
});
