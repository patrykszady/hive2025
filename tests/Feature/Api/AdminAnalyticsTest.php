<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * App\Http\Controllers\Api\Admin\V1\AnalyticsController — the Analytics
 * screen's two endpoints, read over SsSystems\Platform\Pulse's site_events
 * table (call/email/signup), never a TrackedEvent/Lead table. See that
 * controller's docblock for the phone/email/cta/form mapping.
 */
beforeEach(function () {
    config(['services.admin_api.token' => ADMIN_API_TEST_TOKEN]);
});

function seedSiteEvent(string $event, array $overrides = []): void
{
    DB::table('site_events')->insert(array_merge([
        'event' => $event,
        'path' => null,
        'meta' => null,
        'vhash' => 'v-'.uniqid('', true),
        'city' => null,
        'mobile' => false,
        'created_at' => now(),
    ], $overrides));
}

it('summarizes counts by type, with cta from signup clicks and form always zero', function () {
    seedSiteEvent('call', ['meta' => json_encode(['n' => '5551234567']), 'path' => '/contact']);
    seedSiteEvent('email', ['meta' => json_encode(['n' => 'jane@example.com']), 'path' => '/contact']);
    seedSiteEvent('signup', ['path' => '/']);
    // Never counted: page/jserr are not analytics types.
    seedSiteEvent('page', ['path' => '/']);
    seedSiteEvent('jserr', ['meta' => json_encode(['m' => 'boom'])]);

    $data = $this->getJson('/api/admin/v1/analytics/summary?days=7', adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['days'])->toBe(7);
    expect($data['stats']['phone'])->toBe(1);
    expect($data['stats']['email'])->toBe(1);
    expect($data['stats']['cta'])->toBe(1);
    expect($data['stats']['form'])->toBe(0);
    expect($data['stats']['total'])->toBe(3);

    expect($data['stats_prev']['total'])->toBe(0);
    expect($data['trend'])->toHaveCount(7);

    expect(array_sum(array_column($data['trend'], 'phone')))->toBe(1);
    expect(array_sum(array_column($data['trend'], 'email')))->toBe(1);
    expect(array_sum(array_column($data['trend'], 'form')))->toBe(0);
    expect(array_sum(array_column($data['trend'], 'cta')))->toBe(1);
});

it('falls back to the default span when days is invalid', function () {
    $data = $this->getJson('/api/admin/v1/analytics/summary?days=13', adminApiHeaders())
        ->assertOk()->json('data');

    expect($data['days'])->toBe(28);
});

it('reports stats_prev as the prior same-length window', function () {
    seedSiteEvent('call', ['created_at' => now()->subDays(1)]);
    seedSiteEvent('call', ['created_at' => now()->subDays(10)]);
    seedSiteEvent('call', ['created_at' => now()->subDays(11)]);
    seedSiteEvent('call', ['created_at' => now()->subDays(30)]);

    $data = $this->getJson('/api/admin/v1/analytics/summary?days=7', adminApiHeaders())
        ->assertOk()->json('data');

    expect($data['stats']['phone'])->toBe(1);
    expect($data['stats_prev']['phone'])->toBe(2);
});

it('scopes top_pages by the type filter without narrowing the tiles', function () {
    seedSiteEvent('call', ['path' => '/contact']);
    seedSiteEvent('email', ['path' => '/about']);
    seedSiteEvent('call', ['path' => '/contact']);

    $data = $this->getJson('/api/admin/v1/analytics/summary?days=7&type_filter=phone_click', adminApiHeaders())
        ->assertOk()->json('data');

    expect($data['stats']['phone'])->toBe(2);
    expect($data['stats']['email'])->toBe(1);
    expect($data['top_pages'])->toBe(['/contact' => 2]);
});

it('answers events with the paginated envelope and every row key', function () {
    seedSiteEvent('call', ['meta' => json_encode(['n' => '5551234567']), 'path' => '/contact']);
    seedSiteEvent('signup');

    $response = $this->getJson('/api/admin/v1/analytics/events?days=7', adminApiHeaders())->assertOk();

    $response->assertJsonStructure([
        'data' => [[
            'id', 'type', 'type_label', 'label', 'page_path', 'referrer',
            'utm_source', 'utm_medium', 'utm_campaign', 'country', 'created_at',
        ]],
        'meta' => ['current_page', 'per_page', 'total', 'last_page'],
    ]);
    expect($response->json('meta.total'))->toBe(2);
    expect($response->json('meta.per_page'))->toBe(20);
});

it('shapes a phone-click row from a call event', function () {
    seedSiteEvent('call', ['meta' => json_encode(['n' => '5551234567']), 'path' => 'contact']);

    $row = $this->getJson('/api/admin/v1/analytics/events?days=7', adminApiHeaders())
        ->assertOk()->json('data.0');

    expect($row['type'])->toBe('phone_click');
    expect($row['type_label'])->toBe('Phone click');
    expect($row['label'])->toBe('5551234567');
    expect($row['page_path'])->toBe('/contact');
    expect($row['referrer'])->toBeNull();
    expect($row['utm_source'])->toBeNull();
});

it('shapes a cta-click row from a signup event with a static label', function () {
    seedSiteEvent('signup', ['path' => '/']);

    $row = $this->getJson('/api/admin/v1/analytics/events?days=7', adminApiHeaders())
        ->assertOk()->json('data.0');

    expect($row['type'])->toBe('cta_click');
    expect($row['type_label'])->toBe('CTA click');
    expect($row['label'])->toBe('Sign-up link clicked');
});

it('filters events by type', function () {
    seedSiteEvent('call');
    seedSiteEvent('email');

    $response = $this->getJson('/api/admin/v1/analytics/events?days=7&type_filter=email_click', adminApiHeaders())
        ->assertOk();

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.type'))->toBe('email_click');
});

it('scopes events to the requested window', function () {
    seedSiteEvent('call', ['created_at' => now()->subDays(1)]);
    seedSiteEvent('call', ['created_at' => now()->subDays(20)]);

    $response = $this->getJson('/api/admin/v1/analytics/events?days=7', adminApiHeaders())->assertOk();

    expect($response->json('meta.total'))->toBe(1);
});

it('paginates events respecting per_page', function () {
    seedSiteEvent('call');
    seedSiteEvent('call');
    seedSiteEvent('call');

    $response = $this->getJson('/api/admin/v1/analytics/events?days=7&per_page=2', adminApiHeaders())->assertOk();

    expect($response->json('data'))->toHaveCount(2);
    expect($response->json('meta.total'))->toBe(3);
    expect($response->json('meta.last_page'))->toBe(2);
});

it('requires a bearer token', function () {
    $this->getJson('/api/admin/v1/analytics/summary')->assertUnauthorized();
    $this->getJson('/api/admin/v1/analytics/events')->assertUnauthorized();
});
