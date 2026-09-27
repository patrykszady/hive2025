<?php

use App\Models\PlatformSetting;
use App\Support\Seo\ClaritySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.admin_api.token' => ADMIN_API_TEST_TOKEN]);
});

it('saves Clarity credentials from the admin and reports configured with source admin', function () {
    $this->postJson('/api/admin/v1/platforms/clarity/credentials', [
        'project_id' => 'proj123',
        'api_token' => 'tok123',
    ], adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.clarity.configured', true)
        ->assertJsonPath('data.clarity.source', 'admin');

    expect(PlatformSetting::get(ClaritySettings::SETTING_PROJECT_ID))->toBe('proj123');
    expect(PlatformSetting::get(ClaritySettings::SETTING_API_TOKEN))->toBe('tok123');
});

it('is not configured with only the project id saved', function () {
    $this->postJson('/api/admin/v1/platforms/clarity/credentials', [
        'project_id' => 'proj123',
        'api_token' => '',
    ], adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.clarity.configured', false);
});

it('keeps the stored token when the field is resubmitted blank', function () {
    PlatformSetting::put(ClaritySettings::SETTING_PROJECT_ID, 'already-stored-id');
    PlatformSetting::put(ClaritySettings::SETTING_API_TOKEN, 'already-stored-token');

    $this->postJson('/api/admin/v1/platforms/clarity/credentials', [
        'project_id' => '',
        'api_token' => '',
    ], adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.clarity.configured', true);

    expect(PlatformSetting::get(ClaritySettings::SETTING_PROJECT_ID))->toBe('already-stored-id');
    expect(PlatformSetting::get(ClaritySettings::SETTING_API_TOKEN))->toBe('already-stored-token');
});

it('clears both Clarity fields on delete', function () {
    PlatformSetting::put(ClaritySettings::SETTING_PROJECT_ID, 'to-be-cleared');
    PlatformSetting::put(ClaritySettings::SETTING_API_TOKEN, 'to-be-cleared');

    $this->deleteJson('/api/admin/v1/platforms/clarity/credentials', [], adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.clarity.configured', false)
        ->assertJsonPath('data.clarity.source', null);

    expect(PlatformSetting::get(ClaritySettings::SETTING_PROJECT_ID))->toBeNull();
    expect(PlatformSetting::get(ClaritySettings::SETTING_API_TOKEN))->toBeNull();
});

it('reports env source once both env values are set but nothing is stored in the admin', function () {
    config([
        'services.microsoft.clarity.project_id' => 'env-proj',
        'services.microsoft.clarity.api_token' => 'env-tok',
    ]);

    $data = $this->getJson('/api/admin/v1/platforms/status', adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['clarity'])->toBe(['configured' => true, 'source' => 'env']);
});

it('imports the env Clarity credentials into the admin on request', function () {
    config([
        'services.microsoft.clarity.project_id' => 'env-proj',
        'services.microsoft.clarity.api_token' => 'env-tok',
    ]);

    $data = $this->postJson('/api/admin/v1/platforms/seo-credentials/import', ['sources' => ['clarity']], adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['imported'])->toBe(['Clarity project ID', 'Clarity API token']);
    expect($data['status']['clarity'])->toBe(['configured' => true, 'source' => 'admin']);
    expect(PlatformSetting::get(ClaritySettings::SETTING_PROJECT_ID))->toBe('env-proj');
    expect(PlatformSetting::get(ClaritySettings::SETTING_API_TOKEN))->toBe('env-tok');
});

it('busts the welcome pages cache when Clarity credentials are saved', function () {
    foreach (array_keys(config('locales.supported')) as $locale) {
        Cache::put('public-page:'.md5("{$locale}/welcome"), '<html>stale</html>', now()->addHour());
    }

    $this->postJson('/api/admin/v1/platforms/clarity/credentials', [
        'project_id' => 'proj123',
        'api_token' => 'tok123',
    ], adminApiHeaders())->assertOk();

    foreach (array_keys(config('locales.supported')) as $locale) {
        expect(Cache::has('public-page:'.md5("{$locale}/welcome")))->toBeFalse();
    }
});

it('busts the welcome pages cache when Clarity credentials are cleared', function () {
    PlatformSetting::put(ClaritySettings::SETTING_PROJECT_ID, 'x');
    PlatformSetting::put(ClaritySettings::SETTING_API_TOKEN, 'y');
    Cache::put('public-page:'.md5('en/welcome'), '<html>stale</html>', now()->addHour());

    $this->deleteJson('/api/admin/v1/platforms/clarity/credentials', [], adminApiHeaders())->assertOk();

    expect(Cache::has('public-page:'.md5('en/welcome')))->toBeFalse();
});
