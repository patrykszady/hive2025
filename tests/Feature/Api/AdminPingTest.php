<?php

/**
 * `domains` is the union every routes/api-admin/*.php file declares via
 * App\Support\AdminApi::declare() — 'dashboard-stats' (core.php), 'seo'
 * (seo.php) and 'platforms' (platforms.php, added once this app grew a
 * real, if server-managed/read-only, Platforms screen). `platform_kit`
 * reports SsSystems\Platform\Kit::VERSION now that ss-systems/platform-kit
 * is installed (see composer.json and App\Providers\AppServiceProvider's
 * Pulse bindings) — the drift trip-wire the central admin compares across
 * every tenant.
 */
beforeEach(function () {
    config(['services.admin_api.token' => 'test-admin-api-token']);
});

it('reports the site, domains and brand shape', function () {
    config(['app.name' => 'Hive Contractors']);

    $data = $this->getJson('/api/admin/v1/ping', adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['site'])->toBe('Hive Contractors');
    expect($data['platform_kit'])->toBe(\SsSystems\Platform\Kit::VERSION);
    expect($data['domains'])->toEqualCanonicalizing(['dashboard-stats', 'seo', 'platforms']);
    expect($data['brand']['name'])->toBe('Hive Contractors');
    expect($data['brand']['logo'])->toEndWith('/images/hive-mark.svg');
    expect($data['brand']['logo_dark'])->toEndWith('/images/hive-mark-dark.svg');
    expect($data['brand']['accent'])->toBeNull();
});
