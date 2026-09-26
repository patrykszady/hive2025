<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.admin_api.token' => 'test-admin-api-token']);
});

it('answers a minimal snapshot with automated_actions off', function () {
    $data = $this->getJson('/api/admin/v1/seo/snapshot', adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data)->toHaveKeys(['generated_at', 'automated_actions', 'pulse']);
    expect($data['automated_actions'])->toBeFalse();
    expect(fn () => \Illuminate\Support\Carbon::parse($data['generated_at']))->not->toThrow(\Exception::class);
});

/**
 * ss-systems/platform-kit's Pulse (see App\Providers\AppServiceProvider's
 * SnapshotBuilder binding and docs/PULSE.md in the kit). The marketing site
 * has no search feature, so `search_event` is never configured here — the
 * shape must carry no `searches`/`searched_cities`/`filters` keys at all,
 * never zeros (ss-systems/CLAUDE.md's "a key one site's API omits renders
 * as an empty card").
 */
it('carries a pulse block with no searches, since the marketing site has no search feature', function () {
    $data = $this->getJson('/api/admin/v1/seo/snapshot', adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['pulse'])->toHaveKeys([
        'timezone', 'window_days', 'trend_days', 'mobile_share_pct',
        'totals', 'totals_prev', 'days', 'features', 'hours',
        'visitor_cities', 'top_pages', 'js_errors',
    ]);
    expect($data['pulse']['timezone'])->toBe('America/Chicago');
    expect($data['pulse'])->not->toHaveKey('searched_cities');
    expect($data['pulse'])->not->toHaveKey('filters');
    expect($data['pulse']['totals'])->not->toHaveKey('searches');
    expect($data['pulse']['totals_prev'])->not->toHaveKey('searches');
});

it('counts a signup click recorded via the pulse beacon as a feature, labelled Sign-up clicks', function () {
    $this->postJson('/pulse', ['e' => 'signup', 'm' => [], 'p' => '/en/welcome'])->assertStatus(204);

    $data = $this->getJson('/api/admin/v1/seo/snapshot', adminApiHeaders())
        ->assertOk()
        ->json('data');

    $signup = collect($data['pulse']['features'])->firstWhere('key', 'signup');

    expect($signup)->not->toBeNull();
    expect($signup['label'])->toBe('Sign-up clicks');
    expect($signup['uses'])->toBe(1);
});
