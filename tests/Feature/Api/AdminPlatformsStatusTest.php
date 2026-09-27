<?php

use App\Models\PlatformSetting;
use App\Models\SeoSyncRun;
use App\Support\Seo\BingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.admin_api.token' => ADMIN_API_TEST_TOKEN]);
});

it('reads unconfigured/not-connected calmly when nothing is set up', function () {
    $data = $this->getJson('/api/admin/v1/platforms/status', adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['services'])->toBe(['gsc', 'bing', 'clarity', 'pagespeed', 'dataforseo']);
    expect($data['google'])->toBe([
        'configured' => false,
        'source' => null,
        'client_id_hint' => null,
        'project_id' => null,
        'redirect_uris' => ['gbp' => route('admin-oauth.callback', ['provider' => 'gbp'])],
    ]);
    expect($data['gbp'])->toBe([
        'connected' => false,
        'source' => null,
        'email' => null,
        'granted_at' => null,
        'updated_at' => null,
        'access_token_expires_at' => null,
        'scopes' => null,
        'app_credentials_configured' => false,
        'fully_configured' => false,
        'client_id_configured' => false,
        'client_secret_configured' => false,
        'account_id_configured' => false,
        'location_id_configured' => false,
        'refresh_token_present' => false,
        'business_scope_granted' => false,
        'listing_source' => null,
        'reviews_count' => 0,
        'latest_review_date' => null,
    ]);
    expect($data['meta'])->toBe([
        'enabled' => false,
        'connected' => false,
        'source' => null,
        'page_id' => null,
        'page_name' => null,
        'instagram_id' => null,
        'instagram_username' => null,
        'instagram_configured' => false,
        'facebook_configured' => false,
        'granted_by' => null,
        'granted_at' => null,
        'updated_at' => null,
        'app_credentials_configured' => false,
    ]);
    expect($data['gsc'])->toBe([
        'connected' => false,
        'configured' => false,
        'managed' => 'server',
        'property' => 'sc-domain:hive.contractors',
        'last_synced_at' => null,
        'last_sync_status' => null,
        'last_sync_error' => null,
        'sync_stale' => null,
    ]);
    expect($data['bing'])->toBe(['configured' => false, 'source' => null]);
    expect($data['clarity'])->toBe(['configured' => false, 'source' => null]);
    expect($data['pagespeed'])->toBe(['configured' => false, 'using_own_key' => false, 'source' => null]);
    expect($data['dataforseo'])->toBe(['configured' => false, 'source' => null]);
});

it('reads back the last sync bookkeeping regardless of live configuration state', function () {
    SeoSyncRun::record('search_console', [
        'status' => 'ok',
        'started_at' => now()->subMinutes(5)->toIso8601String(),
        'finished_at' => now()->subMinutes(4)->toIso8601String(),
        'error' => null,
    ]);

    $data = $this->getJson('/api/admin/v1/platforms/status', adminApiHeaders())
        ->assertOk()
        ->json('data');

    // Still not "configured" (no real service-account file), but the sync
    // bookkeeping is read back regardless of live configuration state.
    expect($data['gsc']['last_sync_status'])->toBe('ok');
    expect($data['gsc']['sync_stale'])->toBeFalse();
});

it('saves a Bing API key from the admin and reports it configured with source admin', function () {
    $this->postJson('/api/admin/v1/platforms/bing/credentials', ['api_key' => 'a-real-key'], adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.bing.configured', true)
        ->assertJsonPath('data.bing.source', 'admin');

    expect(PlatformSetting::get(BingSettings::SETTING_API_KEY))->toBe('a-real-key');
});

it('keeps the stored Bing key when the field is resubmitted blank', function () {
    PlatformSetting::put(BingSettings::SETTING_API_KEY, 'already-stored');

    $this->postJson('/api/admin/v1/platforms/bing/credentials', ['api_key' => ''], adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.bing.configured', true);

    expect(PlatformSetting::get(BingSettings::SETTING_API_KEY))->toBe('already-stored');
});

it('clears the Bing key on delete', function () {
    PlatformSetting::put(BingSettings::SETTING_API_KEY, 'to-be-cleared');

    $this->deleteJson('/api/admin/v1/platforms/bing/credentials', [], adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.bing.configured', false)
        ->assertJsonPath('data.bing.source', null);

    expect(PlatformSetting::get(BingSettings::SETTING_API_KEY))->toBeNull();
});

it('reports env source once BING_WMT_KEY is set but nothing is stored in the admin', function () {
    config(['services.bing_wmt.key' => 'from-env']);

    $data = $this->getJson('/api/admin/v1/platforms/status', adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['bing'])->toBe(['configured' => true, 'source' => 'env']);
});

it('imports the env Bing key into the admin on request', function () {
    config(['services.bing_wmt.key' => 'from-env']);

    $data = $this->postJson('/api/admin/v1/platforms/seo-credentials/import', ['sources' => ['bing']], adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['imported'])->toBe(['Bing API key']);
    expect($data['already_stored'])->toBe([]);
    expect($data['status']['bing'])->toBe(['configured' => true, 'source' => 'admin']);
    expect(PlatformSetting::get(BingSettings::SETTING_API_KEY))->toBe('from-env');
});

it('reports absent when there is nothing on the server to import', function () {
    $data = $this->postJson('/api/admin/v1/platforms/seo-credentials/import', ['sources' => ['bing']], adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['imported'])->toBe([]);
    expect($data['absent'])->toBe(['Bing API key']);
});
