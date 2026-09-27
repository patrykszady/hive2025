<?php

use App\Models\OAuthToken;
use App\Models\PlatformSetting;
use App\Models\Testimonial;
use App\Services\GoogleBusinessProfileService;
use App\Support\GoogleBusinessListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.admin_api.token' => ADMIN_API_TEST_TOKEN]);
});

it('refuses listings, reviews, and media lookups before a grant exists', function () {
    $this->getJson('/api/admin/v1/platforms/gbp/listings', adminApiHeaders())->assertStatus(422);
    $this->getJson('/api/admin/v1/platforms/gbp/reviews?account_id=1&location_id=2', adminApiHeaders())->assertStatus(422);
    $this->getJson('/api/admin/v1/platforms/gbp/media?account_id=1&location_id=2', adminApiHeaders())->assertStatus(422);
});

it('reports business scope missing when the grant only covers sign-in', function () {
    OAuthToken::storeTokens('google_business_profile', 'refresh-token', 'access-token', 3600, null, ['openid', 'email']);

    $this->getJson('/api/admin/v1/platforms/gbp/listings', adminApiHeaders())
        ->assertStatus(422)
        ->assertJsonPath('data.business_scope_granted', false);
});

it('lists accounts and locations discovered through the grant', function () {
    OAuthToken::storeTokens('google_business_profile', 'refresh-token', 'access-token', 3600, null, [GoogleBusinessProfileService::BUSINESS_SCOPE]);

    Http::fake([
        'mybusinessaccountmanagement.googleapis.com/*' => Http::response(['accounts' => [
            ['name' => 'accounts/123', 'accountName' => 'Hive Contractors'],
        ]], 200),
        'mybusinessbusinessinformation.googleapis.com/*' => Http::response(['locations' => [
            ['name' => 'accounts/123/locations/456', 'title' => 'Hive HQ'],
        ]], 200),
    ]);

    $data = $this->getJson('/api/admin/v1/platforms/gbp/listings', adminApiHeaders())->assertOk()->json('data');

    expect($data['accounts'])->toHaveCount(1);
    expect($data['accounts'][0]['account_id'])->toBe('123');
    expect($data['accounts'][0]['locations'][0]['location_id'])->toBe('456');
});

it('saves the chosen listing and reflects it in status', function () {
    OAuthToken::storeTokens('google_business_profile', 'refresh-token');

    $data = $this->postJson('/api/admin/v1/platforms/gbp/listing', [
        'account_id' => 'accounts/123',
        'location_id' => 'locations/456',
    ], adminApiHeaders())->assertOk()->json('data');

    expect($data['account_id_configured'])->toBeTrue();
    expect($data['location_id_configured'])->toBeTrue();
    expect(PlatformSetting::get(GoogleBusinessListing::SETTING_ACCOUNT_ID))->toBe('123');
    expect(PlatformSetting::get(GoogleBusinessListing::SETTING_LOCATION_ID))->toBe('456');
});

it('marks a review imported when a testimonial already carries its external_id', function () {
    Testimonial::create([
        'name' => 'Jane', 'body' => 'Great', 'platform' => 'google',
        'external_id' => 'review-1', 'is_published' => true,
    ]);

    OAuthToken::storeTokens('google_business_profile', 'refresh-token', 'access-token', 3600);

    Http::fake([
        'mybusiness.googleapis.com/*' => Http::response([
            'reviews' => [
                ['name' => 'accounts/1/locations/2/reviews/review-1', 'reviewer' => ['displayName' => 'Jane'], 'starRating' => 'FIVE', 'comment' => 'Great', 'createTime' => '2026-01-01T00:00:00Z'],
                ['name' => 'accounts/1/locations/2/reviews/review-2', 'reviewer' => ['displayName' => 'Bob'], 'starRating' => 'FOUR', 'comment' => 'Good', 'createTime' => '2026-01-02T00:00:00Z'],
            ],
            'totalReviewCount' => 2,
            'averageRating' => 4.5,
        ], 200),
    ]);

    $data = $this->getJson('/api/admin/v1/platforms/gbp/reviews?account_id=1&location_id=2', adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['reviews'])->toHaveCount(2);
    expect(collect($data['reviews'])->firstWhere('id', 'review-1')['imported'])->toBeTrue();
    expect(collect($data['reviews'])->firstWhere('id', 'review-2')['imported'])->toBeFalse();
    expect($data['total_review_count'])->toBe(2);
});

it('lists google media as a read-only pass-through', function () {
    OAuthToken::storeTokens('google_business_profile', 'refresh-token', 'access-token', 3600);

    Http::fake([
        'mybusiness.googleapis.com/*' => Http::response([
            'mediaItems' => [
                ['name' => 'accounts/1/locations/2/media/abc', 'sourceUrl' => 'https://example.com/a.jpg', 'locationAssociation' => ['category' => 'ADDITIONAL']],
            ],
        ], 200),
    ]);

    $data = $this->getJson('/api/admin/v1/platforms/gbp/media?account_id=1&location_id=2', adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['count'])->toBe(1);
    expect($data['items'][0]['name'])->toBe('accounts/1/locations/2/media/abc');
});

it('refuses to upload, delete, or reconcile a media ledger — this site has no project photos', function () {
    OAuthToken::storeTokens('google_business_profile', 'refresh-token', 'access-token', 3600);

    $this->postJson('/api/admin/v1/platforms/gbp/media', [], adminApiHeaders())->assertStatus(405);
    $this->deleteJson('/api/admin/v1/platforms/gbp/media', [], adminApiHeaders())->assertStatus(405);
    $this->putJson('/api/admin/v1/platforms/gbp/media/ledger', ['uploads' => []], adminApiHeaders())->assertStatus(405);
});
