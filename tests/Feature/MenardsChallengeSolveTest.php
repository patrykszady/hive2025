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
        // A solver that reports no user_agent (2captcha, or a provider that
        // simply doesn't) must still get the key back, as null — the
        // extension's hive-ua-rule-add path only triggers on a real value.
        ->assertJson(['ok' => true, 'token' => 'P0_ey-token', 'user_agent' => null, 'solve_id' => null]);
});

it('passes the solve\'s own user_agent and solve_id back to the extension', function () {
    // NoneCap's 2026-10-22 trial found it solves as a DIFFERENT Chrome than
    // the one it was asked for — the token in the reply is earned as
    // "Chrome/153", not the "Chrome/151 test agent" the request carried.
    // The extension needs exactly the former to match headers against.
    $this->mock(MenardsCaptchaSolver::class, function ($mock) {
        $mock->shouldReceive('configured')->andReturn(true);
        $mock->shouldReceive('solve')->once()->andReturn([
            'ok' => true,
            'token' => 'P1_token',
            'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36',
            'solve_id' => 'solve_01J',
            'seconds' => 18.2,
        ]);
    });

    $this->withHeader('Authorization', 'Bearer test-bridge-token')
        ->postJson('/api/menards/solve-challenge', solvePayload())
        ->assertOk()
        ->assertJson([
            'ok' => true,
            'token' => 'P1_token',
            'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36',
            'solve_id' => 'solve_01J',
        ]);
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

    expect($result['ok'])->toBeTrue()
        ->and($result['token'])->toBe('P1_eyJ0eXAi')
        // NoneCap's own report of what it solved as, not necessarily the
        // $agent requested — that mismatch is the whole reason this exists.
        ->and($result['user_agent'])->toBe($agent)
        ->and($result['solve_id'])->toBe('solve_01J');

    \Illuminate\Support\Facades\Http::assertSent(fn ($request) => preg_match('#^https://api\.nonecap\.com/v1/solves\?wait=(\d+)$#', $request->url(), $m) === 1 && (int) $m[1] <= 50
        && $request->hasHeader('Authorization', 'Bearer nc_live_test')
        && $request['type'] === 'hcaptcha'
        && $request['sitekey'] === '10000000-ffff-ffff-ffff-000000000001'
        && $request['url'] === 'https://www.menards.com/main/checkcredentials.html'
        && $request['user_agent'] === $agent
        && $request['proxy'] === 'http://gs-user:p%40ss@141.140.4.3:12323');
});

it('reports the user_agent NoneCap actually solved as, even when it ignored the one requested', function () {
    // The 2026-10-22 trial: asked for Chrome/151 (this browser's real
    // version), NoneCap solved as a random Chrome/153 anyway. The caller
    // needs to know the ACTUAL one to make the Incapsula POST match it.
    noneCapConfig();
    $requested = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36';
    $solvedAs = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36';
    \Illuminate\Support\Facades\Http::fake(['api.nonecap.com/*' => \Illuminate\Support\Facades\Http::response([
        'id' => 'solve_02K', 'status' => 'solved', 'token' => 'P1_mismatch', 'user_agent' => $solvedAs,
    ])]);

    $result = app(MenardsCaptchaSolver::class)->solve('10000000-ffff-ffff-ffff-000000000001', 'https://www.menards.com/main/login.html', $requested);

    expect($result['ok'])->toBeTrue()
        ->and($result['user_agent'])->toBe($solvedAs)
        ->and($result['user_agent'])->not->toBe($requested);
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

it('waits and tries a failed NoneCap solve again, since failures are free', function () {
    // Both live solves on 2026-10-22 failed with proxy_error the second the
    // wall appeared; the same requests solved minutes later.
    noneCapConfig();
    \Illuminate\Support\Sleep::fake();
    \Illuminate\Support\Facades\Http::fakeSequence('api.nonecap.com/*')
        ->push(['id' => 'solve_A', 'status' => 'failed', 'error_code' => 'proxy_error', 'token' => null, 'cost_credits' => 0])
        ->push(['id' => 'solve_B', 'status' => 'solved', 'token' => 'P1_second', 'credits_charged' => 3]);

    $result = app(MenardsCaptchaSolver::class)->solve('10000000-ffff-ffff-ffff-000000000001', 'https://www.menards.com/main/login.html', 'Chrome/151');

    expect($result['ok'])->toBeTrue()->and($result['token'])->toBe('P1_second');
    \Illuminate\Support\Sleep::assertSleptTimes(1);
    \Illuminate\Support\Facades\Http::assertSentCount(2);
    // The second try only asks NoneCap to wait for what is left of the request.
    \Illuminate\Support\Facades\Http::assertSent(fn ($request) => preg_match('/wait=(\d+)$/', $request->url(), $m) === 1 && (int) $m[1] <= 50);
});

it('stops trying while the extension\'s request still has time', function () {
    noneCapConfig();
    \Illuminate\Support\Sleep::fake();
    \Illuminate\Support\Facades\Http::fake(['api.nonecap.com/*' => \Illuminate\Support\Facades\Http::response(['id' => 'solve_X', 'status' => 'failed', 'error_code' => 'proxy_error', 'token' => null])]);

    $result = app(MenardsCaptchaSolver::class)->solve('10000000-ffff-ffff-ffff-000000000001', 'https://www.menards.com/main/login.html');

    // At most three solves in one request, however the clock reads.
    expect($result['ok'])->toBeFalse()->and($result['error'])->toContain('failed');
    \Illuminate\Support\Facades\Http::assertSentCount(3);
});

