<?php

use App\Models\OAuthToken;
use App\Models\PlatformSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * POST/DELETE platforms/google/credentials since kit 0.14.0 ("one Google",
 * 2026-09-28): the Google sign-in client is shared server configuration
 * (GOOGLE_OAUTH_CLIENT_ID/_SECRET, SsSystems\Platform\Google\OAuthClient),
 * never a per-site value typed into the admin, so both endpoints refuse in
 * a sentence and store nothing. The save/clear/client-swap behaviour this
 * file used to pin belonged to App\Support\GoogleOAuthApp, which is gone;
 * the kit's own tests cover the grant-belongs-to-its-client rule.
 */
beforeEach(function () {
    config(['services.admin_api.token' => ADMIN_API_TEST_TOKEN]);
    Http::preventStrayRequests();
});

it('refuses to save a per-site google client, on the client_id field', function () {
    $this->postJson('/api/admin/v1/platforms/google/credentials', [
        'client_id' => '123-abc.apps.googleusercontent.com',
        'client_secret' => 'shh',
    ], adminApiHeaders())
        ->assertStatus(422)
        ->assertJsonPath('errors.client_id.0', fn (string $message) => str_contains($message, 'ss.systems provides to each site'));

    expect(PlatformSetting::get('google.oauth.client_id'))->toBeNull();
    expect(PlatformSetting::get('google.oauth.client_secret'))->toBeNull();
});

it('never echoes the client secret it was sent', function () {
    $response = $this->postJson('/api/admin/v1/platforms/google/credentials', [
        'client_id' => 'id',
        'client_secret' => 'super-secret-value',
    ], adminApiHeaders())->assertStatus(422);

    expect(json_encode($response->json()))->not->toContain('super-secret-value');
});

it('refuses a clear as a calm notice: a message and no field errors', function () {
    $response = $this->deleteJson('/api/admin/v1/platforms/google/credentials', [], adminApiHeaders())
        ->assertStatus(422);

    expect($response->json('message'))->toContain('shared Google sign-in client');
    expect($response->json())->not->toHaveKey('errors');
});

it('leaves a stored business profile grant alone when a client is pasted', function () {
    OAuthToken::storeTokens('google_business_profile', 'refresh-token');

    $this->postJson('/api/admin/v1/platforms/google/credentials', [
        'client_id' => 'second-client',
        'client_secret' => 'secret-2',
    ], adminApiHeaders())->assertStatus(422);

    expect(OAuthToken::forProvider('google_business_profile'))->not->toBeNull();
});

it('ignores a per-site client left in platform_settings by the old admin form', function () {
    PlatformSetting::put('google.oauth.client_id', 'stored-per-site-client.apps.googleusercontent.com');
    PlatformSetting::put('google.oauth.client_secret', 'stored-secret');

    $google = $this->getJson('/api/admin/v1/platforms/status', adminApiHeaders())
        ->assertOk()
        ->json('data.google');

    expect($google['configured'])->toBeFalse();
    expect($google['client_id_hint'])->toBeNull();

    config([
        'services.google.oauth.client_id' => '31627704418-shared.apps.googleusercontent.com',
        'services.google.oauth.client_secret' => 'shared-secret',
    ]);

    $google = $this->getJson('/api/admin/v1/platforms/status', adminApiHeaders())
        ->assertOk()
        ->json('data.google');

    expect($google['configured'])->toBeTrue();
    expect($google['source'])->toBe('env');
    expect($google['shared'])->toBeTrue();
    expect($google['legacy_config'])->toBeFalse();
    expect($google['project_id'])->toBe('31627704418');
    expect($google['client_id_hint'])->toStartWith('31627704418-sh');
    expect(json_encode($google))->not->toContain('shared-secret');
});
