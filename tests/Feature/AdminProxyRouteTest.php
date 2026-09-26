<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The /admin/{path?} proxy route (AdminProxyController) must be registered
 * so that a request under /admin/* always reaches it rather than being
 * swallowed by an auth/session/CSRF middleware or (if one is ever added
 * later) a catch-all route. Mirrors gsc's/dawnsellshomes' identical test.
 */
it('reaches the proxy for a nested admin path rather than 404ing or redirecting to login', function () {
    config(['services.ss.url' => 'http://ss.test', 'services.ss.service_secret' => 'secret']);
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $response = $this->get('/admin/gsc/login')
        ->assertStatus(502)
        ->assertSee('Admin is temporarily unavailable');

    // NoIndexNonPublic (appended to the 'web' group) runs after the
    // controller and replaces the header with its own, stricter value —
    // still a noindex signal, just a more thorough one, so this checks
    // the substance rather than the exact string.
    expect($response->headers->get('X-Robots-Tag'))->toContain('noindex');
});

it('reaches the proxy at the admin root too', function () {
    config(['services.ss.url' => 'http://ss.test', 'services.ss.service_secret' => 'secret']);
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $this->get('/admin')->assertStatus(502);
});

it('never CSRF-blocks a POST under admin', function () {
    config(['services.ss.url' => 'http://ss.test', 'services.ss.service_secret' => 'secret']);
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    // A blocked CSRF token would 419, not 502 — proving the route's
    // withoutMiddleware + VerifyCsrfToken's except both took effect.
    $this->post('/admin/login', ['foo' => 'bar'])->assertStatus(502);
});

it('serves the down page without an outbound call when no service secret is configured', function () {
    config(['services.ss.service_secret' => '']);
    Http::fake(); // any outbound call here would fail the test via an unexpected request

    $this->get('/admin/gsc/login')->assertStatus(502);

    Http::assertNothingSent();
});

it('relays a successful upstream response, including the noindex header', function () {
    config(['services.ss.url' => 'http://ss.test', 'services.ss.service_secret' => 'secret']);
    Http::fake([
        'ss.test/*' => Http::response('<html>hi</html>', 200, ['Content-Type' => 'text/html']),
    ]);

    $response = $this->get('/admin/gsc/login')
        ->assertOk()
        ->assertSee('hi');

    expect($response->headers->get('X-Robots-Tag'))->toContain('noindex');

    Http::assertSent(fn ($request) => $request->url() === 'http://ss.test/admin/gsc/login'
        && $request->hasHeader('X-Site-Key', 'hive')
        && $request->hasHeader('X-Ss-Service-Secret', 'secret'));
});
