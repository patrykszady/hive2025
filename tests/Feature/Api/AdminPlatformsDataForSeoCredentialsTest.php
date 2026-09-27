<?php

use App\Models\PlatformSetting;
use App\Support\Seo\DataForSeoSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.admin_api.token' => 'test-admin-api-token']);
});

it('saves DataForSEO credentials and reports configured with source platform', function () {
    $this->postJson('/api/admin/v1/platforms/dataforseo/credentials', [
        'login' => 'a-login',
        'password' => 'a-password',
    ], adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.dataforseo.configured', true)
        ->assertJsonPath('data.dataforseo.source', 'platform');

    expect(PlatformSetting::get(DataForSeoSettings::SETTING_LOGIN))->toBe('a-login');
    expect(PlatformSetting::get(DataForSeoSettings::SETTING_PASSWORD))->toBe('a-password');
});

it('keeps the stored password when the field is resubmitted blank', function () {
    PlatformSetting::put(DataForSeoSettings::SETTING_LOGIN, 'already-stored-login');
    PlatformSetting::put(DataForSeoSettings::SETTING_PASSWORD, 'already-stored-password');

    $this->postJson('/api/admin/v1/platforms/dataforseo/credentials', [
        'login' => '',
        'password' => '',
    ], adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.dataforseo.configured', true);

    expect(PlatformSetting::get(DataForSeoSettings::SETTING_LOGIN))->toBe('already-stored-login');
    expect(PlatformSetting::get(DataForSeoSettings::SETTING_PASSWORD))->toBe('already-stored-password');
});

it('clears both DataForSEO fields on delete', function () {
    PlatformSetting::put(DataForSeoSettings::SETTING_LOGIN, 'x');
    PlatformSetting::put(DataForSeoSettings::SETTING_PASSWORD, 'y');

    $this->deleteJson('/api/admin/v1/platforms/dataforseo/credentials', [], adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.dataforseo.configured', false)
        ->assertJsonPath('data.dataforseo.source', null);

    expect(PlatformSetting::get(DataForSeoSettings::SETTING_LOGIN))->toBeNull();
    expect(PlatformSetting::get(DataForSeoSettings::SETTING_PASSWORD))->toBeNull();
});

it('imports the env DataForSEO credentials into the admin on request', function () {
    config([
        'services.dataforseo.login' => 'env-login',
        'services.dataforseo.password' => 'env-password',
    ]);

    $data = $this->postJson('/api/admin/v1/platforms/seo-credentials/import', ['sources' => ['dataforseo']], adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['imported'])->toBe(['DataForSEO login', 'DataForSEO password']);
    expect($data['status']['dataforseo'])->toBe(['configured' => true, 'source' => 'platform']);
    expect(PlatformSetting::get(DataForSeoSettings::SETTING_LOGIN))->toBe('env-login');
});
