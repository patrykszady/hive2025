<?php

/**
 * GET /api/admin/v1/ping — what the central admin reads first: the site's
 * name, the shared-kit version it runs (SsSystems\Platform\Kit::VERSION —
 * the drift trip-wire compared across every tenant), its brand, and every
 * domain its routes/api-admin/*.php files declare through App\Support\
 * AdminApi. The domains list is the sidebar's switchboard on the admin side:
 * a domain named here must have working endpoints behind it.
 */
beforeEach(function () {
    config(['services.admin_api.token' => ADMIN_API_TEST_TOKEN]);
});

it('reports the site, domains and brand shape', function () {
    config(['app.name' => 'Hive Contractors']);

    $data = $this->getJson('/api/admin/v1/ping', adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['site'])->toBe('Hive Contractors');
    expect($data['platform_kit'])->toBe(\SsSystems\Platform\Kit::VERSION);
    expect($data['domains'])->toEqualCanonicalizing(['analytics', 'blog', 'citations', 'dashboard-stats', 'js-errors', 'landing-pages', 'leads', 'pages', 'platforms', 'seo', 'services', 'social-media', 'testimonials']);
    expect($data['brand']['name'])->toBe('Hive Contractors');
    expect($data['brand']['logo'])->toEndWith('/images/hive-mark.svg');
    expect($data['brand']['logo_dark'])->toEndWith('/images/hive-mark-dark.svg');
    expect($data['brand']['accent'])->toBeNull();
});
