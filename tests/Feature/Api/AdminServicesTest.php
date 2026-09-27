<?php

use App\Models\PageSeoOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.admin_api.token' => ADMIN_API_TEST_TOKEN]);
});

/**
 * Hive's "services" are its 9 top-level marketing feature areas
 * (config('marketing.areas')) — a fixed list, same shape as
 * dawnsellshomes.com's sell/buy/property-management (tests/Feature/
 * ServiceListFixedListSiteTest.php on ss-systems, the reference this
 * mirrors): declares 'services' alone, is_landing_page always true,
 * projects_count always 0.
 */
it('lists the 9 feature areas as services, ordered and described from config', function () {
    $data = $this->getJson('/api/admin/v1/services', adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data)->toHaveCount(9);

    $finances = collect($data)->firstWhere('slug', 'finances');
    expect($finances['name'])->toBe('Finances');
    expect($finances['blurb'])->toBe('Finances & bookkeeping — Everything in the money toolkit');
    expect($finances['is_landing_page'])->toBeTrue();
    expect($finances['projects_count'])->toBe(0);
    expect($finances['sort_order'])->toBe(1);
    expect($finances['public_url'])->toBe('https://hive.contractors/en/welcome/finances');

    $sortOrders = collect($data)->pluck('sort_order')->all();
    expect($sortOrders)->toBe(range(1, 9));
});

it('shows a single service by its stable id, the same shape as the list row', function () {
    $list = $this->getJson('/api/admin/v1/services', adminApiHeaders())->assertOk()->json('data');
    $finances = collect($list)->firstWhere('slug', 'finances');

    $data = $this->getJson("/api/admin/v1/services/{$finances['id']}", adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data)->toBe($finances);
});

it('404s a service id that is not one of the 9 areas', function () {
    // A real override row, but for a page this screen doesn't own.
    $override = PageSeoOverride::findOrCreateFor('welcome.faq', []);

    $this->getJson("/api/admin/v1/services/{$override->id}", adminApiHeaders())->assertNotFound();
    $this->getJson('/api/admin/v1/services/999999', adminApiHeaders())->assertNotFound();
});

/**
 * name/blurb write the SAME App\Models\PageSeoOverride row the Pages screen
 * owns for that area's own top-level page — no second copy of the override
 * logic, exactly like dawnsellshomes' ServiceController forwarding onto its
 * PageController@update.
 */
it('updating name/blurb writes the same override row the Pages screen reads for that area', function () {
    $list = $this->getJson('/api/admin/v1/services', adminApiHeaders())->assertOk()->json('data');
    $finances = collect($list)->firstWhere('slug', 'finances');

    $updated = $this->putJson("/api/admin/v1/services/{$finances['id']}", [
        'name' => 'Money Stuff',
        'blurb' => 'A new blurb for the finances area.',
    ], adminApiHeaders())->assertOk()->json('data');

    expect($updated['name'])->toBe('Money Stuff');
    expect($updated['blurb'])->toBe('A new blurb for the finances area.');
    expect($updated['id'])->toBe($finances['id']);

    // The Pages screen's own row for welcome.finances now reads the same title.
    $pageOverride = PageSeoOverride::findFor('welcome.finances', []);
    expect($pageOverride->id)->toBe($finances['id']);
    expect($pageOverride->title)->toBe('Money Stuff');
    expect($pageOverride->meta_description)->toBe('A new blurb for the finances area.');

    $pageRow = $this->getJson("/api/admin/v1/pages/{$finances['id']}", adminApiHeaders())
        ->assertOk()->json('data');
    expect($pageRow['title'])->toBe('Money Stuff');
});

it('is_landing_page and projects_count are ignored on write and always read true/0', function () {
    $list = $this->getJson('/api/admin/v1/services', adminApiHeaders())->assertOk()->json('data');
    $finances = collect($list)->firstWhere('slug', 'finances');

    $updated = $this->putJson("/api/admin/v1/services/{$finances['id']}", [
        'name' => 'Finances',
        'is_landing_page' => false,
        'projects_count' => 5,
    ], adminApiHeaders())->assertOk()->json('data');

    expect($updated['is_landing_page'])->toBeTrue();
    expect($updated['projects_count'])->toBe(0);
});

it('refuses to create a service — a message AND a field error, so the screen actually shows something', function () {
    $response = $this->postJson('/api/admin/v1/services', ['name' => 'A Brand New Service'], adminApiHeaders())
        ->assertStatus(422);

    expect($response->json('message'))->not->toBeEmpty();
    expect($response->json('errors.name.0'))->not->toBeEmpty();
});

it('refuses to delete a service the same way', function () {
    $list = $this->getJson('/api/admin/v1/services', adminApiHeaders())->assertOk()->json('data');
    $finances = collect($list)->firstWhere('slug', 'finances');

    $response = $this->deleteJson("/api/admin/v1/services/{$finances['id']}", [], adminApiHeaders())
        ->assertStatus(422);

    expect($response->json('message'))->not->toBeEmpty();
    expect($response->json('errors.service.0'))->not->toBeEmpty();
});
