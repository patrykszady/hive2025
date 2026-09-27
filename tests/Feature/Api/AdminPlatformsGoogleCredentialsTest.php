<?php

use App\Models\PlatformSetting;
use App\Support\GoogleOAuthApp;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.admin_api.token' => 'test-admin-api-token']);
});

it('saves the google oauth client and reports it configured with source admin', function () {
    $this->postJson('/api/admin/v1/platforms/google/credentials', [
        'client_id' => '123-abc.apps.googleusercontent.com',
        'client_secret' => 'shh',
    ], adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.google.configured', true)
        ->assertJsonPath('data.google.source', 'admin');

    expect(PlatformSetting::get(GoogleOAuthApp::SETTING_CLIENT_ID))->toBe('123-abc.apps.googleusercontent.com');
    expect(config('services.google.business_profile.client_id'))->toBe('123-abc.apps.googleusercontent.com');
});

it('never returns the client secret', function () {
    $response = $this->postJson('/api/admin/v1/platforms/google/credentials', [
        'client_id' => 'id',
        'client_secret' => 'super-secret-value',
    ], adminApiHeaders())->assertOk();

    expect(json_encode($response->json()))->not->toContain('super-secret-value');
});

it('requires both fields', function () {
    $this->postJson('/api/admin/v1/platforms/google/credentials', ['client_id' => 'id'], adminApiHeaders())
        ->assertStatus(422);
});

it('clears the stored client on delete, falling back to env', function () {
    GoogleOAuthApp::save('id', 'secret');

    $this->deleteJson('/api/admin/v1/platforms/google/credentials', [], adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.google.configured', false)
        ->assertJsonPath('data.google.source', null);

    expect(PlatformSetting::get(GoogleOAuthApp::SETTING_CLIENT_ID))->toBeNull();
});

it('drops any stored gbp grant when the client id changes underneath it', function () {
    GoogleOAuthApp::save('first-client', 'secret');
    \App\Models\OAuthToken::storeTokens('google_business_profile', 'refresh-token');

    $this->postJson('/api/admin/v1/platforms/google/credentials', [
        'client_id' => 'second-client',
        'client_secret' => 'secret-2',
    ], adminApiHeaders())->assertOk();

    expect(\App\Models\OAuthToken::forProvider('google_business_profile'))->toBeNull();
});
