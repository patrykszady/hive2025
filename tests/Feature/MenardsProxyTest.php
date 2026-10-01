<?php

use App\Support\MenardsProxy;

/**
 * The residential proxy the Menards browser exits through: composed from the
 * same CAPTCHA_PROXY_* keys gsc uses, one fixed session id so the browser and
 * every captcha solve share an exit, and Hive, Google and 2captcha kept off
 * it.
 */
beforeEach(function () {
    config([
        'app.url' => 'https://hive.contractors',
        'services.menards.proxy_host' => 'na.proxy.2captcha.com:2334',
        'services.menards.proxy_username' => 'user-zone-custom',
        'services.menards.proxy_password' => 'secret',
        'services.menards.proxy_region' => 'us',
        'services.menards.proxy_session' => 'menards',
    ]);
});

it('is absent until every credential is set', function (string $missing) {
    config(["services.menards.{$missing}" => '']);

    expect(MenardsProxy::fromConfig())->toBeNull();
})->with(['proxy_host', 'proxy_username', 'proxy_password']);

it('pins the browser to a US exit and one session on the pool', function () {
    $proxy = MenardsProxy::fromConfig();

    expect($proxy)->not->toBeNull()
        ->and($proxy->host)->toBe('na.proxy.2captcha.com')
        ->and($proxy->port)->toBe(2334)
        ->and($proxy->username)->toBe('user-zone-custom-region-us-session-menards')
        ->and($proxy->password)->toBe('secret')
        ->and($proxy->label())->toBe('na.proxy.2captcha.com:2334 as user-zone-custom-region-us-session-menards')
        ->and($proxy->forExtension())->toBe([
            'host' => 'na.proxy.2captcha.com',
            'port' => 2334,
            'username' => 'user-zone-custom-region-us-session-menards',
            'password' => 'secret',
        ]);
});

it('leaves the region out when none is configured', function () {
    config(['services.menards.proxy_region' => '']);

    expect(MenardsProxy::fromConfig()->username)->toBe('user-zone-custom-session-menards');
});

it('routes Chrome through the proxy but keeps Hive, Google and 2captcha direct', function () {
    $arguments = MenardsProxy::fromConfig()->chromeArguments();

    expect($arguments)->toContain("--proxy-server='http://na.proxy.2captcha.com:2334'")
        ->and($arguments)->toContain('--proxy-bypass-list=')
        ->and($arguments)->toContain('hive.contractors')
        ->and($arguments)->toContain('127.0.0.1;localhost')
        ->and($arguments)->toContain('*.google.com')
        ->and($arguments)->toContain('*.2captcha.com')
        ->and($arguments)->not->toContain('secret');
});

it('stays off when MENARDS_PROXY is false even with credentials set', function () {
    config(['services.menards.proxy_enabled' => false]);

    expect(MenardsProxy::fromConfig())->toBeNull();
});

it('uses the bare username when neither region nor session is configured', function () {
    config(['services.menards.proxy_session' => '', 'services.menards.proxy_region' => '']);

    expect(MenardsProxy::fromConfig()->username)->toBe('user-zone-custom');
});

/**
 * config/services.php's menards block, read with the given environment
 * variables set (null leaves one unset).
 *
 * @param  array<string, string|null>  $env
 * @return array<string, mixed>
 */
function menards_proxyConfigWith(array $env): array
{
    foreach ($env as $key => $value) {
        if ($value !== null) {
            $_SERVER[$key] = $_ENV[$key] = $value;
        }
    }

    try {
        return (require config_path('services.php'))['menards'];
    } finally {
        foreach (array_keys($env) as $key) {
            unset($_SERVER[$key], $_ENV[$key]);
        }
    }
}

it('sends a fixed MENARDS_PROXY_HOST the bare username, without the pool options', function () {
    $menards = menards_proxyConfigWith([
        'MENARDS_PROXY_HOST' => '203.0.113.7:12323',
        'MENARDS_PROXY_USERNAME' => 'fixed-user',
        'MENARDS_PROXY_PASSWORD' => 'fixed-secret',
        'MENARDS_PROXY_REGION' => null,
        'MENARDS_PROXY_SESSION' => null,
    ]);

    expect($menards['proxy_region'])->toBe('')
        ->and($menards['proxy_session'])->toBe('');

    config([
        'services.menards.proxy_host' => $menards['proxy_host'],
        'services.menards.proxy_username' => $menards['proxy_username'],
        'services.menards.proxy_password' => $menards['proxy_password'],
        'services.menards.proxy_region' => $menards['proxy_region'],
        'services.menards.proxy_session' => $menards['proxy_session'],
    ]);

    expect(MenardsProxy::fromConfig()->label())->toBe('203.0.113.7:12323 as fixed-user');
});

it('keeps the US region and one session on the shared pool', function () {
    $menards = menards_proxyConfigWith([
        'MENARDS_PROXY_HOST' => null,
        'MENARDS_PROXY_REGION' => null,
        'MENARDS_PROXY_SESSION' => null,
    ]);

    expect($menards['proxy_region'])->toBe('us')
        ->and($menards['proxy_session'])->toBe('menards');
});
