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
        'services.menards.proxy_session' => 'menards',
    ]);
});

it('is absent until every credential is set', function (string $missing) {
    config(["services.menards.{$missing}" => '']);

    expect(MenardsProxy::fromConfig())->toBeNull();
})->with(['proxy_host', 'proxy_username', 'proxy_password']);

it('pins the browser to one session on the pool', function () {
    $proxy = MenardsProxy::fromConfig();

    expect($proxy)->not->toBeNull()
        ->and($proxy->host)->toBe('na.proxy.2captcha.com')
        ->and($proxy->port)->toBe(2334)
        ->and($proxy->username)->toBe('user-zone-custom-session-menards')
        ->and($proxy->password)->toBe('secret')
        ->and($proxy->label())->toBe('na.proxy.2captcha.com:2334 as user-zone-custom-session-menards')
        ->and($proxy->forExtension())->toBe([
            'host' => 'na.proxy.2captcha.com',
            'port' => 2334,
            'username' => 'user-zone-custom-session-menards',
            'password' => 'secret',
        ]);
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

it('uses the bare username when no session id is configured', function () {
    config(['services.menards.proxy_session' => '']);

    expect(MenardsProxy::fromConfig()->username)->toBe('user-zone-custom');
});
