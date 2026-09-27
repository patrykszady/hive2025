<?php

use App\Models\OAuthToken;
use App\Models\PlatformSetting;
use App\Services\MetaSocialService;
use SsSystems\Platform\Auth\OAuthState;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.admin_api.token' => ADMIN_API_TEST_TOKEN]);
});

it('builds a gbp oauth url carrying a signed, verifiable state', function () {
    config(['services.google.business_profile.client_id' => 'client-id']);

    $url = $this->getJson('/api/admin/v1/platforms/gbp/oauth-url', adminApiHeaders())
        ->assertOk()
        ->json('data.url');

    expect($url)->toContain('accounts.google.com');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    expect(OAuthState::verify($query['state'], 'gbp'))->toBeTrue();
    expect(OAuthState::verify($query['state'], 'meta'))->toBeFalse();
});

it('builds a meta oauth url carrying a signed, verifiable state', function () {
    config(['services.meta.app_id' => 'app-id']);

    $url = $this->getJson('/api/admin/v1/platforms/meta/oauth-url', adminApiHeaders())
        ->assertOk()
        ->json('data.url');

    expect($url)->toContain('facebook.com');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    expect(OAuthState::verify($query['state'], 'meta'))->toBeTrue();
});

it('refuses an unknown provider for both oauth-url and disconnect', function () {
    $this->getJson('/api/admin/v1/platforms/yelp/oauth-url', adminApiHeaders())->assertNotFound();
    $this->deleteJson('/api/admin/v1/platforms/yelp', [], adminApiHeaders())->assertNotFound();
});

it('disconnects gbp by clearing the stored token', function () {
    OAuthToken::storeTokens('google_business_profile', 'refresh-token');

    $this->deleteJson('/api/admin/v1/platforms/gbp', [], adminApiHeaders())->assertNoContent();

    expect(OAuthToken::forProvider('google_business_profile'))->toBeNull();
});

it('disconnects meta by clearing the stored grant', function () {
    PlatformSetting::put('meta.access_token', 'tok');
    PlatformSetting::put('meta.page_id', '123');

    $this->deleteJson('/api/admin/v1/platforms/meta', [], adminApiHeaders())->assertNoContent();

    expect(PlatformSetting::get('meta.access_token'))->toBeNull();
    expect(app(MetaSocialService::class)->isConnected())->toBeFalse();
});
