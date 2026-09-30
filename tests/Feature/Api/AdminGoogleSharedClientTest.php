<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SsSystems\Platform\Google\Adapters\PlatformSettingSharedClient;

/**
 * ss.systems owns the one Google sign-in client and provisions it here
 * (kit 0.15.0, 2026-09-30): no Google keys in this site's .env.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.admin_api.token' => ADMIN_API_TEST_TOKEN]);
    config(['services.google.oauth' => ['client_id' => null, 'client_secret' => null, 'project_id' => null]]);
    Http::fake();
});

it('takes the shared Google client from ss.systems and signs in with it', function () {
    $this->getJson('/api/admin/v1/platforms/status', adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.google.configured', false);

    $this->putJson('/api/admin/v1/platforms/google/shared-client', [
        'client_id' => '31627704418-shared.apps.googleusercontent.com',
        'client_secret' => 'GOCSPX-shared',
        'project_id' => 'gen-lang-client-1',
    ], adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.stored', true)
        ->assertJsonMissingPath('data.client_secret');

    $this->getJson('/api/admin/v1/platforms/status', adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.google.configured', true)
        ->assertJsonPath('data.google.source', 'platform');

    expect((string) DB::table('platform_settings')->where('key', PlatformSettingSharedClient::CLIENT_SECRET)->value('value'))
        ->not->toContain('GOCSPX-shared');

    $this->deleteJson('/api/admin/v1/platforms/google/shared-client', [], adminApiHeaders())->assertOk();
    $this->getJson('/api/admin/v1/platforms/status', adminApiHeaders())->assertJsonPath('data.google.configured', false);
});

it('needs the admin token', function () {
    $this->putJson('/api/admin/v1/platforms/google/shared-client', ['client_id' => 'x.apps.googleusercontent.com', 'client_secret' => 'y'])
        ->assertUnauthorized();
});
