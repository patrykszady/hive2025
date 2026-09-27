<?php

use App\Models\PlatformSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * GET/PUT /api/admin/v1/social-media* — ss-systems' shared Social Media
 * screen's contract for a site that posts nothing itself. See
 * SocialMediaController's docblock for the subject/automation choices.
 */
beforeEach(function () {
    config(['services.admin_api.token' => 'test-admin-api-token']);
});

it('sends the full roster with automation present but empty and no posting configured', function () {
    $data = $this->getJson('/api/admin/v1/social-media', adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['subject'])->toBe('none');
    expect($data['note'])->toContain('does not post to social media automatically');
    expect($data['configured'])->toBe(['instagram' => false, 'facebook' => false, 'google_business' => false, 'any' => false]);
    expect($data['publishing_off'])->toBe(['instagram' => false, 'facebook' => false, 'google_business' => false]);
    expect($data)->toHaveKey('automation');
    expect($data['automation']['items'])->toBe([]);
    expect($data['note'])->toBeString()->not->toBeEmpty();
    expect($data['uploaded_posts'])->toBe([]);
    expect($data['remaining_images'])->toBe([]);
    expect($data['gbp_images'])->toBe([]);

    $keys = collect($data['platforms'])->pluck('key')->all();
    expect($keys)->toBe(['linkedin', 'facebook', 'instagram', 'x', 'youtube', 'tiktok']);
    foreach ($data['platforms'] as $platform) {
        expect($platform['posts'])->toBeFalse();
        expect($platform['url'])->toBe('');
    }
});

it('requires a bearer token', function () {
    $this->getJson('/api/admin/v1/social-media')->assertUnauthorized();
});

it('saves urls and reads them back, and a blank clears one', function () {
    $this->putJson('/api/admin/v1/social-media/urls', [
        'urls' => ['linkedin' => 'https://www.linkedin.com/company/hive-contractors', 'x' => 'https://x.com/hivecontractors'],
    ], adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.platforms.0.url', 'https://www.linkedin.com/company/hive-contractors');

    expect(PlatformSetting::get('socials.url.linkedin'))->toBe('https://www.linkedin.com/company/hive-contractors');
    expect(PlatformSetting::get('socials.url.x'))->toBe('https://x.com/hivecontractors');

    $read = $this->getJson('/api/admin/v1/social-media', adminApiHeaders())->json('data.platforms');
    expect(collect($read)->firstWhere('key', 'linkedin')['url'])->toBe('https://www.linkedin.com/company/hive-contractors');

    $this->putJson('/api/admin/v1/social-media/urls', ['urls' => ['linkedin' => '']], adminApiHeaders())->assertOk();
    expect(PlatformSetting::get('socials.url.linkedin'))->toBeNull();
});

it('rejects a malformed url', function () {
    $this->putJson('/api/admin/v1/social-media/urls', ['urls' => ['linkedin' => 'not a url']], adminApiHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['linkedin']);
});

it('refuses an unknown platform instead of silently ignoring it', function () {
    $this->putJson('/api/admin/v1/social-media/urls', [
        'urls' => ['myspace' => 'https://myspace.com/hive'],
    ], adminApiHeaders())->assertStatus(422)->assertJsonValidationErrors(['urls']);

    expect(PlatformSetting::get('socials.url.myspace'))->toBeNull();
});
