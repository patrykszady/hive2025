<?php

use App\Models\BlogPost;
use App\Models\Testimonial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config([
        // APP_URL deliberately differs from the marketing host: no URL in the
        // feed may follow it (the app and the marketing site are split across
        // hosts).
        'app.url' => 'https://hub.hive.contractors',
        'app.marketing_url' => 'https://hive.contractors',
        'app.noindex_hosts' => '',
        'app.physical_address' => '305 S Ridge St, PO Box 1504, Breckenridge, CO 80424',
        'mail.from.address' => 'support@hive.contractors',
    ]);
});

/**
 * /ai-feed.json (2026-11-10): the kit's AiFeedBuilder envelope over this
 * site's own six adapters — the feed gs.construction and jpeterson-design
 * already serve, carrying a software product's facts here.
 */
it('serves ai-feed.json with the business block, every product area and the feed links, rooted on the marketing host', function () {
    $response = $this->getJson('/ai-feed.json')->assertOk();

    expect((string) $response->headers->get('Content-Type'))->toContain('application/json');

    $data = $response->json();

    expect($data)->toHaveKeys(['generated_at', 'business', 'services', 'service_area_matrix', 'projects', 'stories', 'reviews', 'links', 'service_area', 'translations']);

    expect($data['business'])->toMatchArray([
        'name' => 'Hive Contractors',
        'url' => 'https://hive.contractors',
        'email' => 'support@hive.contractors',
        'languages' => ['English', 'Polish', 'Spanish'],
        'address' => ['locality' => 'Breckenridge', 'region' => 'CO', 'country' => 'US'],
        'headline' => 'The CRM built for small contractors',
        'team_base' => 'Chicago area, Illinois, United States',
        'mailing_address' => '305 S Ridge St, PO Box 1504, Breckenridge, CO 80424',
    ]);
    expect($data['business']['description'])->toStartWith('Hive Contractors brings your entire business under one roof.');

    // Nothing the site does not publish: no phone (config/brand.php keeps it
    // null on purpose), no legal name, no founding year, no invented rating.
    foreach (['phone', 'legal_name', 'founded', 'rating', 'hours'] as $absent) {
        expect($data['business'])->not->toHaveKey($absent);
    }

    // Every area in config/marketing.php, in its order, then the homeowner portal.
    expect(array_column($data['services'], 'slug'))->toBe([...array_keys(config('marketing.areas')), 'homeowners']);

    $finances = collect($data['services'])->firstWhere('slug', 'finances');
    expect($finances['name'])->toBe('Finances')
        ->and($finances['url'])->toBe('https://hive.contractors/en/welcome/finances')
        ->and($finances['description'])->toStartWith('Receipts, bank transactions, payments, checks and payroll flow into one place')
        ->and($finances['features'])->toHaveCount(count(config('marketing.areas.finances.cards')));
    expect(collect($finances['features'])->firstWhere('slug', 'expenses'))->toBe([
        'slug' => 'expenses',
        'name' => 'Expenses',
        'url' => 'https://hive.contractors/en/welcome/finances/expenses',
        'description' => 'Track every cost by project and category with receipts attached.',
    ]);
    expect(collect($data['services'])->firstWhere('slug', 'estimates')['name'])->toBe('Estimates & Documents');

    $homeowners = collect($data['services'])->firstWhere('slug', 'homeowners');
    expect($homeowners['name'])->toBe('Homeowner portal')
        ->and($homeowners['url'])->toBe('https://hive.contractors/en/welcome/homeowners')
        ->and($homeowners['description'])->toStartWith('When your contractor works in Hive, you get a private window into your whole project')
        ->and($homeowners['features'])->toHaveCount(9)
        ->and(array_column($homeowners['features'], 'url'))->toContain('https://hive.contractors/en/welcome/homeowners/status', 'https://hive.contractors/en/welcome/homeowners/payments');

    // One nationwide row with every product area's English page under it.
    expect($data['service_area_matrix'])->toHaveCount(1);
    expect($data['service_area_matrix'][0])->toMatchArray([
        'name' => 'United States', 'country' => 'US', 'coverage' => 'nationwide',
        'team_base' => 'Chicago area, Illinois, United States', 'url' => 'https://hive.contractors/en/welcome',
    ]);
    expect($data['service_area_matrix'][0]['services']['planning'])->toBe('https://hive.contractors/en/welcome/planning');
    expect($data['service_area'])->toBe(['United States']);

    // No case studies on the site — never a page list dressed up as projects.
    expect($data['projects'])->toBe([]);

    expect($data['links'])->toBe([
        'sitemap' => 'https://hive.contractors/sitemap.xml',
        'llms_txt' => 'https://hive.contractors/llms.txt',
        'llms_full' => 'https://hive.contractors/llms-full.txt',
        'geo_answers' => 'https://hive.contractors/geo/answers.json',
        'robots' => 'https://hive.contractors/robots.txt',
    ]);
    expect($data['translations'])->toBe([
        'pl' => 'https://hive.contractors/pl/welcome',
        'es' => 'https://hive.contractors/es/welcome',
    ]);

    expect($response->getContent())->not->toContain('hub.hive.contractors')->not->toContain('&amp;');
});

it('lists the marketing pages and the published posts as stories, and the published reviews as reviews — never a draft or a hidden review', function () {
    BlogPost::create(['status' => 'published', 'published_at' => '2026-10-15 09:00:00', 'title' => 'Paying crews on time', 'slug' => 'paying-crews-on-time', 'excerpt' => 'How timesheets become paychecks.', 'body_html' => '<p>Body.</p>']);
    BlogPost::create(['status' => 'draft', 'title' => 'Unfinished thought', 'slug' => 'unfinished-thought', 'body_html' => '<p>Draft.</p>']);
    Testimonial::create(['name' => 'Jane Doe', 'role' => 'Owner, Doe Roofing', 'body' => 'Hive keeps my receipts straight.', 'rating' => 5, 'platform' => 'site', 'review_date' => '2026-10-01', 'is_published' => true]);
    Testimonial::create(['name' => 'Hidden Reviewer', 'body' => 'Not yet.', 'is_published' => false]);

    $data = $this->getJson('/ai-feed.json')->assertOk()->json();
    $stories = collect($data['stories']);

    expect($stories->where('type', 'page')->pluck('url')->all())
        ->toContain('https://hive.contractors/en/welcome', 'https://hive.contractors/en/welcome/finances', 'https://hive.contractors/en/welcome/homeowners/status', 'https://hive.contractors/en/welcome/faq')
        ->not->toContain('https://hive.contractors/en/welcome/finances/expenses');
    expect($stories->firstWhere('url', 'https://hive.contractors/en/welcome/estimates'))
        ->toMatchArray(['type' => 'page', 'title' => 'Estimates & Documents'])
        ->not->toHaveKey('date');
    expect($stories->where('type', 'page')->first()['summary'])->toStartWith('Hive Contractors brings your entire business under one roof.');

    expect($stories->where('type', 'post')->values()->all())->toHaveCount(1);
    expect($stories->firstWhere('type', 'post'))->toMatchArray([
        'title' => 'Paying crews on time',
        'url' => 'https://hive.contractors/en/blog/paying-crews-on-time',
        'summary' => 'How timesheets become paychecks.',
        'date' => '2026-10-15',
    ]);
    expect($stories->pluck('title')->all())->not->toContain('Unfinished thought');

    // The home page's "What contractors say" rows, public display name only.
    expect($data['reviews'])->toHaveCount(1);
    expect($data['reviews'][0])->toMatchArray([
        'author' => 'Jane D', 'role' => 'Owner, Doe Roofing', 'rating' => 5, 'review_date' => '2026-10-01',
        'body' => 'Hive keeps my receipts straight.', 'platform' => 'site',
    ]);
    expect(json_encode($data['reviews']))->not->toContain('Jane Doe')->not->toContain('Hidden Reviewer');
});

it('is cached for fifteen minutes with the public headers and never noindex', function () {
    $response = $this->get('/ai-feed.json')->assertOk();

    expect((string) $response->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=900');
    expect($response->headers->get('X-Robots-Tag'))->toBe('all');
});
