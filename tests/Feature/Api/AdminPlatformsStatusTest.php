<?php

beforeEach(function () {
    config(['services.admin_api.token' => 'test-admin-api-token']);
});

it('answers calmly so the SEO screen Connect Services modal has something to render', function () {
    $data = $this->getJson('/api/admin/v1/platforms/status', adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['services'])->toBe(['gsc', 'bing']);
    expect($data['gsc'])->toBe(['connected' => false, 'configured' => false, 'managed' => 'server']);
    expect($data['bing'])->toBe(['configured' => false, 'source' => null]);
});
