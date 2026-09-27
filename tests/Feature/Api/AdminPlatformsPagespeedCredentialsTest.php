<?php

use App\Models\PlatformSetting;
use App\Support\Seo\PsiSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.admin_api.token' => ADMIN_API_TEST_TOKEN]);
});

it('saves a PageSpeed API key from the admin and reports using_own_key', function () {
    $this->postJson('/api/admin/v1/platforms/pagespeed/credentials', ['api_key' => 'a-real-key'], adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.pagespeed.configured', true)
        ->assertJsonPath('data.pagespeed.using_own_key', true)
        ->assertJsonPath('data.pagespeed.source', 'admin');

    expect(PlatformSetting::get(PsiSettings::SETTING_API_KEY))->toBe('a-real-key');
});

it('keeps the stored PageSpeed key when the field is resubmitted blank', function () {
    PlatformSetting::put(PsiSettings::SETTING_API_KEY, 'already-stored');

    $this->postJson('/api/admin/v1/platforms/pagespeed/credentials', ['api_key' => ''], adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.pagespeed.using_own_key', true);

    expect(PlatformSetting::get(PsiSettings::SETTING_API_KEY))->toBe('already-stored');
});

it('clears the PageSpeed key on delete, back to the shared quota', function () {
    PlatformSetting::put(PsiSettings::SETTING_API_KEY, 'to-be-cleared');

    $this->deleteJson('/api/admin/v1/platforms/pagespeed/credentials', [], adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.pagespeed.using_own_key', false)
        ->assertJsonPath('data.pagespeed.source', null);

    expect(PlatformSetting::get(PsiSettings::SETTING_API_KEY))->toBeNull();
});

it('reports env source once PAGESPEED_API_KEY is set but nothing is stored in the admin', function () {
    config(['services.pagespeed.api_key' => 'from-env']);

    $data = $this->getJson('/api/admin/v1/platforms/status', adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['pagespeed'])->toBe(['configured' => true, 'using_own_key' => true, 'source' => 'env']);
});

it('imports the env PageSpeed key into the admin on request', function () {
    config(['services.pagespeed.api_key' => 'from-env']);

    $data = $this->postJson('/api/admin/v1/platforms/seo-credentials/import', ['sources' => ['pagespeed']], adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['imported'])->toBe(['PageSpeed API key']);
    expect($data['status']['pagespeed'])->toBe(['configured' => true, 'using_own_key' => true, 'source' => 'admin']);
    expect(PlatformSetting::get(PsiSettings::SETTING_API_KEY))->toBe('from-env');
});
