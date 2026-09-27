<?php

use App\Models\LandingPage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Covers the same index/services/store/publish/unpublish/destroy contract
 * as gs.construction's/dawnsellshomes' LandingPageControllerTest, minus
 * anything that would exercise a proof gate this app doesn't have at all
 * for its own marketing site (no home-improvement Projects/proof domain
 * of that kind) — see App\Http\Controllers\Api\Admin\V1\
 * LandingPageController's docblock.
 */
function makeLandingPage(array $overrides = []): LandingPage
{
    static $n = 0;
    $n++;

    return LandingPage::create(array_merge([
        'slug' => "free-trial-chicago-{$n}",
        'service' => 'free-trial',
        'city' => 'Chicago',
        'title' => 'Free Trial — Hive Contractors',
        'h1' => 'Free Trial for Chicago Contractors',
        'meta_description' => 'Placeholder description.',
        'intro' => 'Placeholder intro paragraph.',
        'sections' => [['heading' => 'What it includes', 'body' => 'Placeholder body copy.']],
        'faq' => [['q' => 'Do you work with Chicago contractors?', 'a' => 'Placeholder answer.']],
        'status' => LandingPage::STATUS_DRAFT,
        'source' => 'manual',
    ], $overrides));
}

beforeEach(function () {
    config(['services.admin_api.token' => 'test-admin-api-token']);
});

it('reports the landing-pages domain on ping', function () {
    $domains = $this->getJson('/api/admin/v1/ping', adminApiHeaders())->assertOk()->json('data.domains');

    expect($domains)->toContain('landing-pages');
});

it('returns the contractor-crm campaign catalogue', function () {
    $data = $this->getJson('/api/admin/v1/landing-pages/services', adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data)->toHaveKey('free-trial')
        ->and($data['free-trial'])->toBe('Free Trial')
        ->and($data)->toHaveKey('switch-from-spreadsheets')
        // No real-estate/remodeling campaigns ever leak into this catalogue.
        ->and($data)->not->toHaveKey('free-home-valuation')
        ->and($data)->not->toHaveKey('kitchen-remodeling');
});

it('creates a draft manual page with generated content', function () {
    $data = $this->postJson('/api/admin/v1/landing-pages', [
        'service' => 'free-trial',
        'city' => 'Chicago',
    ], adminApiHeaders())->assertCreated()->json('data');

    expect($data['status'])->toBe('draft')
        ->and($data['source'])->toBe('manual')
        ->and($data['service'])->toBe('free-trial')
        ->and($data['city'])->toBe('Chicago')
        ->and($data['slug'])->toContain('chicago')
        ->and($data['h1'])->toContain('Chicago')
        ->and($data['url'])->toBe('/lp/'.$data['slug'])
        ->and($data['should_index'])->toBeFalse() // draft — never indexed
        ->and($data['has_proof'])->toBeFalse() // no Projects/proof domain here at all
        ->and($data['proof_count'])->toBe(0);

    $this->assertDatabaseHas('landing_pages', ['slug' => $data['slug'], 'status' => 'draft']);
});

it('accepts an optional free-text modifier', function () {
    $data = $this->postJson('/api/admin/v1/landing-pages', [
        'service' => 'free-trial',
        'city' => 'Chicago',
        'modifier' => 'Beta',
    ], adminApiHeaders())->assertCreated()->json('data');

    expect($data['modifier'])->toBe('Beta')
        ->and($data['h1'])->toContain('Beta');
});

it('dedupes the slug instead of erroring on a repeat campaign', function () {
    $first = $this->postJson('/api/admin/v1/landing-pages', [
        'service' => 'free-trial',
        'city' => 'Chicago',
    ], adminApiHeaders())->assertCreated()->json('data');

    $second = $this->postJson('/api/admin/v1/landing-pages', [
        'service' => 'free-trial',
        'city' => 'Chicago',
    ], adminApiHeaders())->assertCreated()->json('data');

    expect($second['slug'])->not->toBe($first['slug']);
});

it('requires service and city to create a page', function () {
    $this->postJson('/api/admin/v1/landing-pages', [], adminApiHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['service', 'city']);
});

it('lists pages newest first', function () {
    $older = makeLandingPage(['slug' => 'older-page']);
    $older->forceFill(['created_at' => now()->subDay()])->save();
    $newer = makeLandingPage(['slug' => 'newer-page']);
    $newer->forceFill(['created_at' => now()])->save();

    $data = $this->getJson('/api/admin/v1/landing-pages', adminApiHeaders())->assertOk()->json('data');

    expect($data[0]['slug'])->toBe('newer-page')
        ->and($data[1]['slug'])->toBe('older-page');
});

it('publishes a page with no proof required at all', function () {
    $page = makeLandingPage();

    $data = $this->patchJson("/api/admin/v1/landing-pages/{$page->id}/publish", [], adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['status'])->toBe('published')
        ->and($data['published_at'])->not->toBeNull()
        // Published, but STILL never indexed — see LandingPage::shouldIndex().
        ->and($data['should_index'])->toBeFalse();

    $this->assertDatabaseHas('landing_pages', ['id' => $page->id, 'status' => 'published']);
});

it('unpublishes a page back to draft', function () {
    $page = makeLandingPage(['status' => LandingPage::STATUS_PUBLISHED, 'published_at' => now()]);

    $data = $this->patchJson("/api/admin/v1/landing-pages/{$page->id}/unpublish", [], adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['status'])->toBe('draft')
        ->and($data['published_at'])->toBeNull();
});

it('deletes a page', function () {
    $page = makeLandingPage();

    $this->deleteJson("/api/admin/v1/landing-pages/{$page->id}", [], adminApiHeaders())->assertNoContent();

    $this->assertDatabaseMissing('landing_pages', ['id' => $page->id]);
});

it('requires the admin bearer token on every landing-pages endpoint', function () {
    $this->getJson('/api/admin/v1/landing-pages')->assertUnauthorized();
    $this->getJson('/api/admin/v1/landing-pages/services')->assertUnauthorized();
    $this->postJson('/api/admin/v1/landing-pages', [])->assertUnauthorized();
});
