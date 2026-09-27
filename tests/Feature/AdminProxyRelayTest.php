<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * hive's AdminProxyController is now a ~15-line wrapper around
 * SsSystems\Platform\Http\AdminProxyRelay (kit 0.13.0) — AdminProxyRouteTest
 * already pins pass-through/empty-secret/CSRF-exemption behaviour byte-for-
 * byte; this file adds what that one didn't cover: the Cache-Control fix
 * (hive had the append bug, same as gsc), Location rewrite, and the breaker
 * actually skipping a second outbound call once tripped (as opposed to only
 * the empty-secret early return, which the older test already covers).
 */
beforeEach(function () {
    config([
        'services.ss.url' => 'http://ss.test',
        'services.ss.service_secret' => 'secret',
    ]);
});

it('gives an admin asset a cacheable Cache-Control header not merged with the default', function () {
    Http::fake([
        'ss.test/*' => Http::response('body{}', 200, [
            'Content-Type' => 'text/css',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]),
    ]);

    $response = $this->get('/admin/_assets/app.css')->assertOk();

    expect($response->headers->hasCacheControlDirective('public'))->toBeTrue();
    expect($response->headers->hasCacheControlDirective('immutable'))->toBeTrue();
    expect($response->headers->getCacheControlDirective('max-age'))->toBe('31536000');
    expect($response->headers->hasCacheControlDirective('no-cache'))->toBeFalse();
});

it('rewrites a redirect Location pointing at ss-systems to this host\'s own origin', function () {
    Http::fake([
        'ss.test/*' => Http::response('', 302, ['Location' => 'http://ss.test/admin/login']),
    ]);

    $response = $this->get('http://hive.test/admin/logout');

    $response->assertStatus(302);
    expect($response->headers->get('Location'))->toBe('http://hive.test/admin/login');
});

it('opens the breaker on a connection failure so the next hit skips the outbound call entirely', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $this->get('/admin/login')->assertStatus(502);

    Http::fake(); // any outbound call now would fail the test via an unexpected request
    $this->get('/admin/login')->assertStatus(502);

    Http::assertNothingSent();
});

it('serves the down page without an outbound call once the breaker is already open', function () {
    Cache::put('admin-proxy-down', true, now()->addSeconds(60));

    Http::fake(); // any outbound call here would fail the test via an unexpected request

    $this->get('/admin')->assertStatus(502);

    Http::assertNothingSent();
});
