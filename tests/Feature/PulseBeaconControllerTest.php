<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The `/pulse` sendBeacon endpoint — ss-systems/platform-kit's
 * SsSystems\Platform\Pulse\BeaconController, bound in
 * App\Providers\AppServiceProvider and routed in routes/web.php. Feeds the
 * central admin's "Site Pulse" card (seo/snapshot.pulse) via `site_events`.
 */
it('stores a whitelisted page event and answers 204', function () {
    $this->postJson('/pulse', ['e' => 'page', 'm' => [], 'p' => '/en/welcome'])
        ->assertStatus(204);

    $this->assertDatabaseHas('site_events', [
        'event' => 'page',
        'path' => '/en/welcome',
    ]);
});

it('stores a signup event fired from the marketing pages sign-up CTA', function () {
    $this->postJson('/pulse', ['e' => 'signup', 'm' => [], 'p' => '/en/welcome'])
        ->assertStatus(204);

    $this->assertDatabaseHas('site_events', [
        'event' => 'signup',
        'path' => '/en/welcome',
    ]);
});

it('silently drops an event outside this sites whitelist', function () {
    // 'search' is not in this site's whitelist (no search feature on the
    // marketing site — see AppServiceProvider's Recorder binding: ['page',
    // 'call', 'email', 'jserr', 'signup']). The beacon must still answer
    // 204 (it never surfaces a failure to the page), but nothing is written.
    $this->postJson('/pulse', ['e' => 'search', 'm' => ['q' => 'kitchen'], 'p' => '/en/welcome'])
        ->assertStatus(204);

    $this->assertDatabaseCount('site_events', 0);
});

it('records nothing for a bot user agent', function () {
    $this->postJson('/pulse', ['e' => 'page', 'm' => [], 'p' => '/en/welcome'], [
        'User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    ])->assertStatus(204);

    $this->assertDatabaseCount('site_events', 0);
});

it('answers 204 for call and email events from the beacons own click listener', function () {
    $this->postJson('/pulse', ['e' => 'call', 'm' => ['n' => '8478097344'], 'p' => '/en/welcome'])
        ->assertStatus(204);
    $this->postJson('/pulse', ['e' => 'email', 'm' => ['n' => 'hello@hive.contractors'], 'p' => '/en/welcome'])
        ->assertStatus(204);

    $this->assertDatabaseHas('site_events', ['event' => 'call', 'path' => '/en/welcome']);
    $this->assertDatabaseHas('site_events', ['event' => 'email', 'path' => '/en/welcome']);
});

it('is CSRF-exempt: a plain form POST with no token still reaches the beacon', function () {
    // sendBeacon cannot set a CSRF header — App\Http\Middleware\
    // VerifyCsrfToken's $except lists 't'. Post through the ordinary `post()`
    // helper (not postJson) with no _token in the payload: a 419 here would
    // mean the exemption regressed.
    $response = $this->post('/pulse', ['e' => 'page', 'm' => [], 'p' => '/en/welcome']);

    $response->assertStatus(204);
});

it('never errors on a malformed body and still answers 204', function () {
    $response = $this->call('POST', '/pulse', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], 'not valid json {{{');

    $response->assertStatus(204);
    $this->assertDatabaseCount('site_events', 0);
});

it('leaves /t to the SMS short link to the Terms page', function () {
    // The beacon lives on /pulse because /t was already taken.
    $this->get('/t')->assertRedirect('/welcome/legal/terms');
    $this->assertDatabaseCount('site_events', 0);
});
