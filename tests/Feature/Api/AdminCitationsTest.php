<?php

use App\Models\Citation;
use App\Models\PlatformSetting;
use App\Services\Citations\CitationSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * /api/admin/v1/citations — the directory board ss-systems' shared
 * Citations screen (App\Livewire\Admin\Citations) reads and edits. This
 * app has no remote-browser pipeline at all (see
 * CitationSessionService's docblock), so start/poll/resume/stop/batch are
 * exercised for their honest "not available" answers, never a faked
 * success — there is nothing to fake.
 */
beforeEach(function () {
    config(['services.admin_api.token' => ADMIN_API_TEST_TOKEN]);
});

it('lists every configured directory, syncing on first load', function () {
    $data = $this->getJson('/api/admin/v1/citations', adminApiHeaders())->assertOk()->json('data');

    expect($data['citations'])->toHaveCount(count(config('citations.directories')));
    expect($data['session']['running'])->toBeFalse();
    expect($data)->toHaveKey('inbox_configured');
    expect($data['inbox_configured'])->toBeFalse();
    expect($data['batch'])->toBe(['active' => false, 'remaining' => [], 'done' => 0, 'current' => null]);

    $row = collect($data['citations'])->firstWhere('slug', 'google_business_profile');
    expect($row['status'])->toBe('planned');
    expect($row['tier'])->toBe(0);
    expect($row['needs'])->toBe(['account', 'phone']);
});

it('requires a bearer token', function () {
    $this->getJson('/api/admin/v1/citations')->assertUnauthorized();
});

it('reports plainly what browser automation is missing on this host', function () {
    $requirements = app(CitationSessionService::class)->checkRequirements(headless: true);

    expect($requirements['ok'])->toBeFalse();
    foreach (['puppeteer', 'puppeteer-extra', 'puppeteer-extra-plugin-stealth'] as $package) {
        if (! is_dir(base_path('node_modules/'.$package))) {
            expect($requirements['missing'])->toContain('node package '.$package);
        }
    }

    $data = $this->getJson('/api/admin/v1/citations', adminApiHeaders())->assertOk()->json('data');
    expect($data['requirements']['ok'])->toBeFalse();
    expect($data['requirements']['missing'])->not->toBeEmpty();
});

it('returns the canonical listing payload', function () {
    config(['app.name' => 'Hive Contractors', 'app.physical_address' => '305 S Ridge St, PO Box 1504, Breckenridge, CO 80424']);

    $data = $this->getJson('/api/admin/v1/citations/payload', adminApiHeaders())->assertOk()->json('data');

    expect($data['name'])->toBe('Hive Contractors');
    expect($data['address']['city'])->toBe('Breckenridge');
    expect($data['address']['state'])->toBe('CO');
    expect($data['address']['zip'])->toBe('80424');
    expect($data['categories'])->toContain('Construction management software');
    expect($data['services'])->not->toBeEmpty();
    expect($data)->toHaveKey('description');
    expect($data)->toHaveKey('photos');
});

it('refuses to start a session and says plainly why', function () {
    $this->artisan('citations:sync');

    $response = $this->postJson('/api/admin/v1/citations/g2/start', [], adminApiHeaders())->assertOk();

    expect($response->json('data.ok'))->toBeFalse();
    expect($response->json('data.error'))->toContain('not set up on this host');
    expect(Citation::where('slug', 'g2')->value('status'))->toBe('planned', 'a refused start never touches the row status');
});

it('reports a start against an unknown directory as not found', function () {
    $this->postJson('/api/admin/v1/citations/not-a-real-directory/start', [], adminApiHeaders())->assertNotFound();
});

it('refuses to run the automatic batch and says plainly why', function () {
    $data = $this->postJson('/api/admin/v1/citations/batch', [], adminApiHeaders())->assertOk()->json('data');

    expect($data['ok'])->toBeFalse();
    expect($data['error'])->toContain('not set up on this host');
});

it('poll and resume and stop all answer that there is no session, never 500', function () {
    $this->artisan('citations:sync');

    $this->postJson('/api/admin/v1/citations/session/poll', [], adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.session.running', false);

    $this->postJson('/api/admin/v1/citations/g2/resume', [], adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.ok', false);

    $this->postJson('/api/admin/v1/citations/session/stop', [], adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.ok', true);
});

it('the manual edit form sets status, listing url and note, and rejects an unknown status', function () {
    $this->artisan('citations:sync');

    $updated = $this->patchJson('/api/admin/v1/citations/g2', [
        'status' => 'live',
        'listing_url' => 'https://www.g2.com/products/hive-contractors/reviews',
        'note' => 'Verified with support@hive.contractors',
    ], adminApiHeaders())->assertOk()->json('data');

    expect($updated['citation']['status'])->toBe('live');
    expect($updated['citation']['listing_url'])->toBe('https://www.g2.com/products/hive-contractors/reviews');
    expect(Citation::where('slug', 'g2')->value('live_at'))->not->toBeNull();

    $this->patchJson('/api/admin/v1/citations/g2', ['status' => 'bogus'], adminApiHeaders())->assertStatus(422);
});

it('an unknown directory is refused on the manual edit form too', function () {
    $this->patchJson('/api/admin/v1/citations/not-a-real-directory', ['note' => 'x'], adminApiHeaders())->assertNotFound();
});

it('the screenshot route guards path traversal and missing files', function () {
    $this->artisan('citations:sync');

    $this->getJson('/api/admin/v1/citations/g2/screenshots/..%2F..%2Fetc%2Fpasswd', adminApiHeaders())->assertNotFound();
    $this->getJson('/api/admin/v1/citations/g2/screenshots/nope.png', adminApiHeaders())->assertNotFound();
});

it('a linkedin url already saved on Social Media reads as live on the board', function () {
    PlatformSetting::put('socials.url.linkedin', 'https://www.linkedin.com/company/hive-contractors-test');
    $this->artisan('citations:sync')->assertExitCode(0);
    Citation::where('slug', 'linkedin')->update(['status' => 'needs_human', 'human_reason' => 'Create the page.', 'listing_url' => null]);

    $rows = collect($this->getJson('/api/admin/v1/citations', adminApiHeaders())->assertOk()->json('data.citations'));

    $linkedin = $rows->firstWhere('slug', 'linkedin');
    expect($linkedin['status'])->toBe('live');
    expect($linkedin['listing_url'])->toBe('https://www.linkedin.com/company/hive-contractors-test');
    expect($linkedin['human_reason'])->toBeNull();
    expect($linkedin['note'])->toContain('Listed already');
});

it('citations:check-links records a link result against the row', function () {
    $this->artisan('citations:sync');
    Citation::where('slug', 'g2')->update(['listing_url' => 'https://www.g2.com/products/hive-contractors']);

    Illuminate\Support\Facades\Http::fake([
        'www.g2.com/*' => Illuminate\Support\Facades\Http::response('<html>no link here</html>', 200),
    ]);

    $this->artisan('citations:check-links')->assertExitCode(0);

    $g2 = Citation::where('slug', 'g2')->first();
    expect($g2->last_checked_at)->not->toBeNull();
    expect($g2->links_to_us)->not->toBeTrue();
});
