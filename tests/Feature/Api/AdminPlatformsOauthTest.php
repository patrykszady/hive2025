<?php

use App\Models\OAuthToken;
use App\Models\PlatformSetting;
use App\Services\MetaSocialService;
use SsSystems\Platform\Auth\OAuthState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use SsSystems\Platform\Google\BusinessProfile\Client;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.admin_api.token' => ADMIN_API_TEST_TOKEN]);
    Http::preventStrayRequests();
});

it('builds a gbp oauth url through the shared client, carrying a signed, verifiable state', function () {
    config([
        'services.google.oauth.client_id' => '31627704418-shared.apps.googleusercontent.com',
        'services.google.oauth.client_secret' => 'shared-secret',
    ]);

    $url = $this->getJson('/api/admin/v1/platforms/gbp/oauth-url', adminApiHeaders())
        ->assertOk()
        ->json('data.url');

    expect($url)->toContain('accounts.google.com');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    expect($query['client_id'])->toBe('31627704418-shared.apps.googleusercontent.com');
    expect($query['redirect_uri'])->toBe(route('admin-oauth.callback', ['provider' => 'gbp']));
    expect(explode(' ', $query['scope']))->toContain(Client::BUSINESS_SCOPE);
    expect($query['access_type'])->toBe('offline');
    expect(OAuthState::verify($query['state'], 'gbp'))->toBeTrue();
    expect(OAuthState::verify($query['state'], 'meta'))->toBeFalse();
    expect($url)->not->toContain('shared-secret');
});

it('answers a sentence instead of a google error page when the server has no sign-in client', function () {
    $this->getJson('/api/admin/v1/platforms/gbp/oauth-url', adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.url', null)
        ->assertJsonPath('data.message', fn (string $message) => str_contains($message, 'not set up on the server'));
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
