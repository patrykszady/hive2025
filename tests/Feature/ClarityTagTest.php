<?php

use App\Models\PlatformSetting;
use App\Support\Seo\ClaritySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

// Every test starts from an uncached welcome page — CachePublicPage's
// hour-long cache would otherwise carry whichever test ran first's markup
// (with or without the tag) into every other test in this file.
beforeEach(fn () => Cache::flush());

/**
 * Microsoft Clarity's tag on the public /{locale}/welcome marketing pages
 * — App\Support\Seo\ClaritySettings::projectId(), pushed into
 * head.blade.php's 'head-appended' stack from guest.blade.php. See
 * App\Http\Controllers\Api\Admin\V1\PlatformsController::
 * forgetWelcomePages() for the cache-bust half.
 */
it('renders no tag at all when no project id is configured', function () {
    $html = $this->get('/en/welcome')->assertOk()->getContent();

    expect($html)->not->toContain('clarity.ms');
});

it('renders the tag in Microsoft\'s documented form when a project id is saved', function () {
    PlatformSetting::put(ClaritySettings::SETTING_PROJECT_ID, 'abc123test');

    $html = $this->get('/en/welcome')->assertOk()->getContent();

    // Exactly the documented snippet — Clarity's own installation check
    // looks for this exact form (double quotes, type="text/javascript",
    // not deferred) — see ss-systems' "Clarity in the head, in Microsoft's
    // exact form" commit.
    expect($html)->toContain('t.src="https://www.clarity.ms/tag/"+i;')
        ->and($html)->toContain('"clarity", "script", "abc123test"')
        ->and($html)->toContain('type="text/javascript"')
        ->and($html)->toContain('data-navigate-once');

    expect(strpos($html, 'clarity.ms/tag'))->toBeLessThan(strpos($html, '</head>'));
});

it('does not require an API token for the tag to render, only a project id', function () {
    PlatformSetting::put(ClaritySettings::SETTING_PROJECT_ID, 'proj-only');

    $html = $this->get('/en/welcome')->assertOk()->getContent();

    expect($html)->toContain('"clarity", "script", "proj-only"');
});

it('forgets every locale\'s cached welcome page when the project id is saved, so the tag shows at once', function () {
    config(['services.admin_api.token' => 'test-admin-api-token']);

    // Warm the cache for every locale with no id configured yet.
    foreach (array_keys(config('locales.supported')) as $locale) {
        $this->get("/{$locale}/welcome")->assertOk()->assertDontSee('clarity.ms', false);
    }

    // Saving through the real admin endpoint is what busts the cache in
    // production — see PlatformsController::forgetWelcomePages().
    $this->postJson('/api/admin/v1/platforms/clarity/credentials', [
        'project_id' => 'fresh-project',
        'api_token' => 'fresh-token',
    ], adminApiHeaders())->assertOk();

    foreach (array_keys(config('locales.supported')) as $locale) {
        $this->get("/{$locale}/welcome")->assertOk()->assertSee('fresh-project', false);
    }
});
