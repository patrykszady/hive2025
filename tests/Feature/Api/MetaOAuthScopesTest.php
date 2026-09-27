<?php

use App\Models\PlatformSetting;
use App\Services\MetaSocialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * hive.contractors' own Meta OAuth scope set, asserted by CONTENT — now
 * that the scope list travels as a call-time argument into the kit's
 * `SsSystems\Platform\Social\MetaGraphClient` (2026-09-27, kit 0.13.0 unit
 * 9) rather than a hardcoded value inside it. This app's set is its OWN
 * distinct 4 scopes (drops `instagram_content_publish` — this app never
 * publishes; adds `pages_read_engagement`), not the same as gsc/
 * jpeterson's — see `MetaGraphClient::getOAuthUrl()`'s docblock. gsc and
 * jpeterson-design each carry their own sibling of this test.
 *
 * `MetaSocialService` does NOT re-declare `getOAuthUrl()`/
 * `exchangeCodeAndStore()` at a narrower arity (PHP's override-
 * compatibility rules refuse that, however many parent parameters carry
 * defaults), so every call site passes `MetaSocialService::OAUTH_SCOPES`
 * explicitly. This test guards both ends: the constant's own content, and
 * that what a real call site sends is genuinely that content.
 */
it('carries its own four production scopes, distinct from gsc/jpeterson', function () {
    expect(MetaSocialService::OAUTH_SCOPES)->toBe([
        'pages_show_list',
        'pages_read_engagement',
        'business_management',
        'instagram_basic',
    ]);

    // This app never publishes to Instagram — no publish scope, unlike
    // gsc/jpeterson's set.
    expect(MetaSocialService::OAUTH_SCOPES)->not->toContain('instagram_content_publish');
});

it('encodes exactly the oauth scopes constant in the built authorize url', function () {
    config(['services.meta.app_id' => 'test-app-id']);

    $url = app(MetaSocialService::class)->getOAuthUrl(
        'https://hive.contractors/admin-oauth/meta/callback',
        MetaSocialService::OAUTH_SCOPES,
        'test-state',
    );

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($query['scope'])->toBe(implode(',', MetaSocialService::OAUTH_SCOPES));
});

it('records exactly the oauth scopes constant on a real code exchange', function () {
    config([
        'services.meta.app_id' => 'test-app-id',
        'services.meta.app_secret' => 'test-app-secret',
    ]);

    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::sequence()
            ->push(['access_token' => 'short-lived'])
            ->push(['access_token' => 'long-lived']),
        'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [
            ['id' => '123', 'name' => 'Hive Page', 'access_token' => 'page-token', 'instagram_business_account' => ['id' => '456', 'username' => 'hivepage']],
        ]]),
        'graph.facebook.com/*/me*' => Http::response(['id' => '999', 'name' => 'Owner', 'email' => 'owner@example.test']),
    ]);

    $result = app(MetaSocialService::class)->exchangeCodeAndStore(
        'the-code',
        'https://hive.contractors/admin-oauth/meta/callback',
        MetaSocialService::OAUTH_SCOPES,
    );

    expect($result['success'])->toBeTrue();

    // hive's PlatformSettingCredentialStore adapter has no 'scopes' row to
    // put this in (see that class's docblock) — the grant still stores
    // correctly, and this simply confirms the omission is silent, not an
    // error, matching this app's pre-port behaviour.
    expect(PlatformSetting::get('meta.access_token'))->toBe('page-token');
    expect(PlatformSetting::get('meta.scopes'))->toBeNull();
});
