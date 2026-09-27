<?php

use App\Models\LandingPage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Public /lp/{slug} — only published pages resolve; anything else 404s.
 * No draft-preview mode (this app has no session-backed admin of its own
 * for a ?preview=1 check to gate on — see routes/web.php's docblock).
 *
 * Every page is ALWAYS noindex regardless of publish state — see
 * App\Models\LandingPage::shouldIndex() — a campaign page exists to
 * receive paid traffic, never to rank. There is no lead form here (Hive
 * has none on its marketing site at all): every call to action is the
 * same route('registration') link every other marketing page uses.
 */
function makeShowableLandingPage(array $overrides = []): LandingPage
{
    return LandingPage::create(array_merge([
        'slug' => 'free-trial-chicago',
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

it('renders a published page with its own content', function () {
    makeShowableLandingPage(['status' => LandingPage::STATUS_PUBLISHED, 'published_at' => now()]);

    $response = $this->get('/lp/free-trial-chicago');

    $response->assertOk();
    $response->assertSee('Free Trial for Chicago Contractors');
    $response->assertSee('What it includes');
    $response->assertSee('Do you work with Chicago contractors?');
});

it('carries the same registration call to action every marketing page uses', function () {
    makeShowableLandingPage(['status' => LandingPage::STATUS_PUBLISHED, 'published_at' => now()]);

    $html = $this->get('/lp/free-trial-chicago')->assertOk()->getContent();

    expect($html)->toContain('href="'.route('registration').'"')
        ->and($html)->toContain('Create your Hive');
});

it('404s a draft page for the public', function () {
    makeShowableLandingPage(['status' => LandingPage::STATUS_DRAFT]);

    $this->get('/lp/free-trial-chicago')->assertNotFound();
});

it('404s an unknown slug', function () {
    $this->get('/lp/does-not-exist')->assertNotFound();
});

it('is always noindex, published or not', function () {
    config(['app.noindex_hosts' => '']);

    makeShowableLandingPage(['status' => LandingPage::STATUS_PUBLISHED, 'published_at' => now()]);

    $response = $this->get('/lp/free-trial-chicago');

    $response->assertOk();
    expect($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow, noarchive, nosnippet');
});

it('does not shadow an existing marketing route', function () {
    // /lp/{slug} must not be reachable through any other route — this
    // pins that the dedicated route wins and renders the right view.
    makeShowableLandingPage(['slug' => 'switch-from-spreadsheets-chicago', 'status' => LandingPage::STATUS_PUBLISHED, 'published_at' => now()]);

    $response = $this->get('/lp/switch-from-spreadsheets-chicago');

    $response->assertOk();
    $response->assertViewIs('landing-page');
});
