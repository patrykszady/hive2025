<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Where visitors come from (kit 0.17): the `/pulse` beacon stores each
 * visit's arrival on `site_events` (App\Providers\AppServiceProvider's
 * Recorder binding already passes the arrival/fresh/ownHosts BeaconController
 * sends — only the migration adding TrafficSchema's columns was new here),
 * traffic:collect fills traffic_source_days from the beacon, and the admin
 * API (App\Http\Controllers\Api\Admin\V1\TrafficController) serves it to the
 * SEO screen. This app is single-tenant, so unlike gs.construction's
 * equivalent test there is no site_id to assert and no tenant argument on
 * traffic:collect.
 */
beforeEach(function () {
    config(['services.admin_api.token' => ADMIN_API_TEST_TOKEN]);
});

it('stores where the visit came from on the beacon', function () {
    $this->postJson('/pulse', [
        'e' => 'page',
        'p' => '/kitchens',
        'a' => ['r' => 'https://www.google.com/', 'us' => '', 'um' => '', 'uc' => '', 'l' => '/kitchens'],
        'f' => 1,
    ], ['User-Agent' => 'Mozilla/5.0 (iPhone)'])->assertStatus(204);

    $row = DB::table('site_events')->latest('id')->first();

    expect([$row->channel, $row->source, $row->landing_path, (bool) $row->arrival])
        ->toBe(['search', 'Google', '/kitchens', true]);
});

it('credits a later beacon event to the visits arrival, not the page it happened on', function () {
    // BeaconScript.php's client-side script re-reads the ss_arrival cookie
    // into `window.__ssArrival` and sends it as `a` on every beacon call it
    // makes, not only the first page's — the server never reads a cookie
    // for this; BeaconController takes `a` straight from the POST body.
    $this->postJson('/pulse', [
        'e' => 'call',
        'm' => ['n' => '8478097344'],
        'p' => '/en/welcome',
        'a' => ['r' => 'https://l.facebook.com/', 'l' => '/kitchens'],
    ])->assertStatus(204);

    $row = DB::table('site_events')->where('event', 'call')->latest('id')->first();

    // Not the visit's first page, so `arrival` (the visits-per-source flag)
    // stays false even though channel/source are still named.
    expect([$row->channel, $row->source, (bool) $row->arrival])->toBe(['social', 'Facebook', false]);
});

it('runs traffic:collect for the beacon provider and the API serves the day', function () {
    $yesterday = now()->subDay()->setTime(15, 0)->toDateTimeString();

    DB::table('site_events')->insert([
        ['event' => 'page', 'path' => '/', 'vhash' => 'a', 'mobile' => false, 'created_at' => $yesterday, 'channel' => 'search', 'source' => 'Google', 'arrival' => true],
        ['event' => 'page', 'path' => '/', 'vhash' => 'b', 'mobile' => false, 'created_at' => $yesterday, 'channel' => 'ai', 'source' => 'ChatGPT', 'arrival' => true],
        ['event' => 'call', 'path' => '/', 'vhash' => 'a', 'mobile' => false, 'created_at' => $yesterday, 'channel' => 'search', 'source' => 'Google', 'arrival' => false],
    ]);

    // Restricted to the beacon provider: traffic:collect must never reach a
    // real Clarity/Search Console/Bing endpoint from a test or a dev machine
    // (Clarity's quota is 10 requests/day, shared with production).
    $this->artisan('traffic:collect', ['--days' => 1, '--provider' => ['beacon']])->assertSuccessful();

    $stored = DB::table('traffic_source_days')->orderBy('metric')->orderBy('source')->get(['provider', 'metric', 'source', 'value'])
        ->map(fn ($r) => "{$r->provider} {$r->metric} {$r->source} {$r->value}")->all();
    expect($stored)->toBe(['beacon calls Google 1', 'beacon visits ChatGPT 1', 'beacon visits Google 1']);

    $data = $this->getJson('/api/admin/v1/traffic/sources?days=28', adminApiHeaders())
        ->assertOk()->json('data');

    expect($data['channels'][0]['channel'])->toBe('search');
    expect($data['channels'][0]['metrics']['visits'])->toBe(1);
    expect($data['channels'][0]['metrics']['calls'])->toBe(1);
    expect($data['channels'][0]['sources'][0]['source'])->toBe('Google');
    expect($data['channels'][1]['label'])->toBe('AI assistants');
    expect($data['providers']['beacon'])->toBe(now()->subDay()->toDateString());
});

it('requires a bearer token', function () {
    $this->getJson('/api/admin/v1/traffic/sources')->assertUnauthorized();
});
