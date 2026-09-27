<?php

use App\Models\PlatformSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.admin_api.token' => ADMIN_API_TEST_TOKEN]);
});

it('reports meta not connected calmly, never as an error', function () {
    $data = $this->postJson('/api/admin/v1/platforms/meta/test-connection', [], adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['ok'])->toBeFalse();
    expect($data['message'])->toBe('Meta is not connected yet.');
});

it('reports a not-yet-available message once a grant is on file', function () {
    PlatformSetting::put('meta.access_token', 'tok');
    PlatformSetting::put('meta.page_id', '123');

    $data = $this->postJson('/api/admin/v1/platforms/meta/test-connection', [], adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['ok'])->toBeFalse();
    expect($data['message'])->toBe('Meta test connection is not available on this site yet.');
});

it('reads the meta status block once connected via env', function () {
    config([
        'services.meta.enabled' => true,
        'services.meta.page_access_token' => 'env-token',
        'services.meta.facebook_page_id' => '999',
    ]);

    $data = $this->getJson('/api/admin/v1/platforms/status', adminApiHeaders())->assertOk()->json('data');

    expect($data['meta']['connected'])->toBeTrue();
    expect($data['meta']['source'])->toBe('env');
    expect($data['meta']['page_id'])->toBe('999');
    expect($data['meta']['facebook_configured'])->toBeTrue();
});
