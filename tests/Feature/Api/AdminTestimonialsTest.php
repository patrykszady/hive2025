<?php

use App\Models\Testimonial;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.admin_api.token' => 'test-admin-api-token']);
});

function makeTestimonial(array $overrides = []): Testimonial
{
    return Testimonial::create(array_merge([
        'name' => 'Jane Doe',
        'role' => 'Owner, Doe Roofing',
        'body' => 'Great experience running my business on Hive.',
        'is_published' => true,
    ], $overrides));
}

it('answers the paginated envelope with every row key', function () {
    makeTestimonial();
    makeTestimonial(['name' => 'John Smith']);

    $response = $this->getJson('/api/admin/v1/testimonials', adminApiHeaders())->assertOk();

    $response->assertJsonStructure([
        'data' => [[
            'id', 'reviewer_name', 'project_location', 'project_type', 'review_description',
            'review_date', 'review_url', 'external_id', 'star_rating', 'is_hidden',
            'review_urls', 'project_ids', 'public_url',
        ]],
        'meta' => ['current_page', 'per_page', 'total', 'last_page'],
    ]);
    expect($response->json('meta.total'))->toBe(2);
});

it('carries no project links or review_urls pivot in the response', function () {
    $t = makeTestimonial();

    $data = $this->getJson("/api/admin/v1/testimonials/{$t->id}", adminApiHeaders())->assertOk()->json('data');

    expect($data['review_urls'])->toBe([]);
    expect($data['project_ids'])->toBe([]);
    expect($data['public_url'])->toBeNull();
    expect($data['project_type'])->toBeNull();
});

it('searches by reviewer name', function () {
    makeTestimonial(['name' => 'Findme Smith']);
    makeTestimonial(['name' => 'Other Person']);

    $data = $this->getJson('/api/admin/v1/testimonials?search=Findme', adminApiHeaders())->json('data');

    expect($data)->toHaveCount(1);
    expect($data[0]['reviewer_name'])->toBe('Findme Smith');
});

it('filters on hidden', function () {
    makeTestimonial(['name' => 'Shown', 'is_published' => true]);
    makeTestimonial(['name' => 'Hidden', 'is_published' => false]);

    $shown = $this->getJson('/api/admin/v1/testimonials?hidden=0', adminApiHeaders())->json('data');
    $hidden = $this->getJson('/api/admin/v1/testimonials?hidden=1', adminApiHeaders())->json('data');

    expect($shown)->toHaveCount(1);
    expect($shown[0]['reviewer_name'])->toBe('Shown');
    expect($shown[0]['is_hidden'])->toBeFalse();

    expect($hidden)->toHaveCount(1);
    expect($hidden[0]['reviewer_name'])->toBe('Hidden');
    expect($hidden[0]['is_hidden'])->toBeTrue();
});

it('filters on star rating', function () {
    makeTestimonial(['name' => 'Five', 'rating' => 5]);
    makeTestimonial(['name' => 'Three', 'rating' => 3]);

    $data = $this->getJson('/api/admin/v1/testimonials?star_rating=5', adminApiHeaders())->json('data');

    expect($data)->toHaveCount(1);
    expect($data[0]['reviewer_name'])->toBe('Five');
});

it('filters on platform', function () {
    makeTestimonial(['name' => 'Site', 'platform' => 'site']);
    makeTestimonial(['name' => 'Google', 'platform' => 'google']);

    $data = $this->getJson('/api/admin/v1/testimonials?platform=google', adminApiHeaders())->json('data');

    expect($data)->toHaveCount(1);
    expect($data[0]['reviewer_name'])->toBe('Google');
});

it('returns distinct platforms and no project types from filters', function () {
    makeTestimonial(['platform' => 'site']);
    makeTestimonial(['platform' => 'google']);
    makeTestimonial(['platform' => 'google']);

    $data = $this->getJson('/api/admin/v1/testimonials/filters', adminApiHeaders())->assertOk()->json('data');

    expect($data['project_types'])->toBe([]);
    expect(collect($data['platforms'])->pluck('value')->sort()->values()->all())->toBe(['google', 'site']);
    expect(collect($data['platforms'])->firstWhere('value', 'site')['icon'])->toBeNull();
});

it('404s for a missing testimonial', function () {
    $this->getJson('/api/admin/v1/testimonials/999999', adminApiHeaders())->assertNotFound();
});

it('creates a testimonial', function () {
    $response = $this->postJson('/api/admin/v1/testimonials', [
        'reviewer_name' => 'New Reviewer',
        'project_location' => 'Owner, New Roofing',
        'project_type' => 'ignored-type-no-column-here',
        'review_description' => 'Fantastic experience.',
        'review_date' => '2026-05-01',
        'star_rating' => 5,
    ], adminApiHeaders());

    $response->assertCreated();
    $data = $response->json('data');
    expect($data['reviewer_name'])->toBe('New Reviewer');
    expect($data['project_location'])->toBe('Owner, New Roofing');
    expect($data['project_type'])->toBeNull();
    expect($data['review_date'])->toBe('2026-05-01');
    expect($data['star_rating'])->toBe(5);
    expect($data['is_hidden'])->toBeFalse();
    $this->assertDatabaseCount('testimonials', 1);
    $this->assertDatabaseHas('testimonials', ['name' => 'New Reviewer', 'role' => 'Owner, New Roofing']);
});

it('validates required fields on store', function () {
    $response = $this->postJson('/api/admin/v1/testimonials', [], adminApiHeaders());

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['reviewer_name', 'review_description']);
});

it('rejects an out-of-range star rating', function () {
    $response = $this->postJson('/api/admin/v1/testimonials', [
        'reviewer_name' => 'X', 'review_description' => 'Y', 'star_rating' => 9,
    ], adminApiHeaders());

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['star_rating']);
});

it('updates fields', function () {
    $t = makeTestimonial(['role' => 'Old']);

    $response = $this->putJson("/api/admin/v1/testimonials/{$t->id}", [
        'reviewer_name' => $t->name,
        'project_location' => 'New',
        'review_description' => $t->body,
        'is_hidden' => true,
    ], adminApiHeaders());

    $response->assertOk();
    $data = $response->json('data');
    expect($data['project_location'])->toBe('New');
    expect($data['is_hidden'])->toBeTrue();
});

it('leaves publish state alone when is_hidden is not sent', function () {
    $t = makeTestimonial(['is_published' => false]);

    $response = $this->putJson("/api/admin/v1/testimonials/{$t->id}", [
        'reviewer_name' => 'Renamed',
        'review_description' => $t->body,
    ], adminApiHeaders());

    $response->assertOk();
    expect($response->json('data.is_hidden'))->toBeTrue();
    expect($response->json('data.reviewer_name'))->toBe('Renamed');
});

it('publishes a hidden testimonial through update, the same route the edit form uses', function () {
    $t = makeTestimonial(['is_published' => false]);

    $response = $this->putJson("/api/admin/v1/testimonials/{$t->id}", [
        'reviewer_name' => $t->name,
        'review_description' => $t->body,
        'is_hidden' => false,
    ], adminApiHeaders());

    $response->assertOk();
    expect($response->json('data.is_hidden'))->toBeFalse();
    expect($t->fresh()->is_published)->toBeTrue();
});

it('removes the testimonial on destroy', function () {
    $t = makeTestimonial();

    $this->deleteJson("/api/admin/v1/testimonials/{$t->id}", [], adminApiHeaders())->assertNoContent();

    $this->assertDatabaseCount('testimonials', 0);
});

it('sorts by the requested column and direction', function () {
    makeTestimonial(['name' => 'Older', 'review_date' => '2026-01-01']);
    makeTestimonial(['name' => 'Newer', 'review_date' => '2026-06-01']);

    $desc = $this->getJson('/api/admin/v1/testimonials?sort=-review_date', adminApiHeaders())->json('data');
    $asc = $this->getJson('/api/admin/v1/testimonials?sort=review_date', adminApiHeaders())->json('data');

    expect($desc[0]['reviewer_name'])->toBe('Newer');
    expect($asc[0]['reviewer_name'])->toBe('Older');
});

it('requires the admin api bearer token', function () {
    $this->getJson('/api/admin/v1/testimonials')->assertUnauthorized();
});

it('maps the gbp review importer review_urls shape onto the flat platform/review_url/external_id columns', function () {
    $response = $this->postJson('/api/admin/v1/testimonials', [
        'reviewer_name' => 'Jane Doe',
        'review_description' => 'Left a 5-star review.',
        'review_urls' => [['platform' => 'google', 'url' => 'https://www.google.com/maps/reviews?reviewid=abc', 'external_id' => 'abc']],
    ], adminApiHeaders())->assertCreated();

    expect($response->json('data.review_url'))->toBe('https://www.google.com/maps/reviews?reviewid=abc');
    expect($response->json('data.external_id'))->toBe('abc');

    $stored = Testimonial::first();
    expect($stored->platform)->toBe('google');
    expect($stored->review_url)->toBe('https://www.google.com/maps/reviews?reviewid=abc');
    expect($stored->external_id)->toBe('abc');
});
