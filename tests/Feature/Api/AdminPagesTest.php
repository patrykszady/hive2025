<?php

use App\Models\PageSeoOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.admin_api.token' => ADMIN_API_TEST_TOKEN]);
});

/**
 * 87 localized pages (App\Support\MarketingPages mirrors MarketingSitemap's
 * own count — see hive-wt-pages/CLAUDE.md) plus the 2 un-localized legal
 * pages MarketingSitemap doesn't carry: 9 area + 66 feature + 10 homeowners
 * + 1 faq + 1 root ('other') + 2 legal = 89.
 */
it('lists every marketing/legal page exactly once, in English, never a hand-typed list', function () {
    $data = $this->getJson('/api/admin/v1/pages?per_page=100', adminApiHeaders())
        ->assertOk()
        ->json();

    expect($data['meta']['total'])->toBe(89);

    $finances = collect($data['data'])->firstWhere('path', 'en/welcome/finances');
    expect($finances)->not->toBeNull();
    expect($finances['type'])->toBe('area');
    expect($finances['title'])->toBe('Finances — Hive Contractors');
    expect($finances['status'])->toBe('published');
    expect($finances['in_sitemap'])->toBeNull(); // nothing to switch: the admin hides the sitemap toggle
    expect($finances['url'])->toBe('https://hive.contractors/en/welcome/finances');
    expect($finances['meta_title'])->toBeNull();
    expect($finances['meta_description'])->toBeNull();

    $expenses = collect($data['data'])->firstWhere('path', 'en/welcome/finances/expenses');
    expect($expenses)->not->toBeNull();
    expect($expenses['type'])->toBe('feature');
    expect($expenses['title'])->toBe('Expenses — Hive Contractors');

    $faq = collect($data['data'])->firstWhere('path', 'en/welcome/faq');
    expect($faq['type'])->toBe('faq');
    expect($faq['title'])->toBe('FAQ — Hive Contractors');

    $home = collect($data['data'])->firstWhere('path', 'en/welcome');
    expect($home['type'])->toBe('other');

    // Legal pages are outside the {locale} group entirely — no locale
    // segment, and MarketingSitemap never carries them, but the Pages
    // screen still manages them.
    $privacy = collect($data['data'])->firstWhere('path', 'welcome/legal/privacy');
    expect($privacy)->not->toBeNull();
    expect($privacy['type'])->toBe('legal');
    // No @section('title', ...) on this view — falls back to its <h1>.
    expect($privacy['title'])->toBe('Privacy Policy');

    $terms = collect($data['data'])->firstWhere('path', 'welcome/legal/terms');
    expect($terms['title'])->toBe('Terms of Service');
});

it('groups pages/types the same way, counts summing to the full list', function () {
    $types = $this->getJson('/api/admin/v1/pages/types', adminApiHeaders())
        ->assertOk()
        ->json('data');

    $byType = collect($types)->pluck('count', 'type');

    expect($byType['area'])->toBe(9);
    expect($byType['feature'])->toBe(66);
    expect($byType['homeowners'])->toBe(10);
    expect($byType['legal'])->toBe(2);
    expect($byType['faq'])->toBe(1);
    expect($byType['other'])->toBe(1);
    expect($byType->sum())->toBe(89);
});

it('filters by search and by type', function () {
    $byType = $this->getJson('/api/admin/v1/pages?type=legal&per_page=50', adminApiHeaders())
        ->assertOk()->json('data');
    expect($byType)->toHaveCount(2);
    expect(collect($byType)->pluck('type')->unique()->all())->toBe(['legal']);

    $bySearch = $this->getJson('/api/admin/v1/pages?search=vendor&per_page=50', adminApiHeaders())
        ->assertOk()->json('data');
    expect(collect($bySearch))->not->toBeEmpty();
    expect(collect($bySearch)->every(fn ($r) => str_contains(strtolower($r['title']), 'vendor') || str_contains(strtolower($r['path']), 'vendor')))->toBeTrue();
});

it('shows a single page by its stable id, the same shape as the list row', function () {
    $override = PageSeoOverride::findOrCreateFor('welcome.finances', []);

    $data = $this->getJson("/api/admin/v1/pages/{$override->id}", adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data['id'])->toBe($override->id);
    expect($data['path'])->toBe('en/welcome/finances');
    expect($data['title'])->toBe('Finances — Hive Contractors');
});

it('404s a page id that was never listed', function () {
    $this->getJson('/api/admin/v1/pages/999999', adminApiHeaders())->assertNotFound();
});

/**
 * The core promise: an admin edit here actually shows on the site, and
 * doesn't wait up to 60 minutes for CachePublicPage's TTL — the PUT forgets
 * that page's cache key so the very next guest request re-renders fresh.
 */
it('an update overrides the title and meta description, and shows on the site immediately', function () {
    $override = PageSeoOverride::findOrCreateFor('welcome.finances', []);

    // Warm the guest cache with the page's ORIGINAL rendering.
    $before = $this->get('/en/welcome/finances')->assertOk();
    expect($before->getContent())->not->toContain('Custom SEO Title');

    $updated = $this->putJson("/api/admin/v1/pages/{$override->id}", [
        'title' => 'Custom SEO Title',
        'meta_description' => 'Custom description text.',
    ], adminApiHeaders())->assertOk()->json('data');

    expect($updated['title'])->toBe('Custom SEO Title');
    expect($updated['meta_description'])->toBe('Custom description text.');

    $after = $this->get('/en/welcome/finances')->assertOk();
    expect($after->getContent())->toContain('<title>Custom SEO Title | Hive Contractors</title>');
    expect($after->getContent())->toContain('<meta name="description" content="Custom description text.">');

    // Every locale shares the one override row — each locale's own cached
    // copy must have been forgotten too.
    $pl = $this->get('/pl/welcome/finances')->assertOk();
    expect($pl->getContent())->toContain('<title>Custom SEO Title | Hive Contractors</title>');
});

it('a meta_title override wins over a title override in the rendered <title>', function () {
    $override = PageSeoOverride::findOrCreateFor('welcome.faq', []);

    $this->putJson("/api/admin/v1/pages/{$override->id}", [
        'title' => 'Title Override',
        'meta_title' => 'Meta Title Override',
    ], adminApiHeaders())->assertOk();

    $response = $this->get('/en/welcome/faq')->assertOk();
    expect($response->getContent())->toContain('<title>Meta Title Override | Hive Contractors</title>');
});

it('refuses to create a page — a message and no field errors, so the screen shows a calm notice', function () {
    $response = $this->postJson('/api/admin/v1/pages', ['title' => 'New page'], adminApiHeaders())
        ->assertStatus(422);

    expect($response->json('message'))->toBe("This site's pages are built into it; ask us to add one.");
    expect($response->json())->not->toHaveKey('errors');
});

it('refuses to delete a page the same way', function () {
    $override = PageSeoOverride::findOrCreateFor('welcome.finances', []);

    $response = $this->deleteJson("/api/admin/v1/pages/{$override->id}", [], adminApiHeaders())
        ->assertStatus(422);

    expect($response->json('message'))->toBe("This site's pages are built into it; ask us to add one.");
    expect($response->json())->not->toHaveKey('errors');
});
