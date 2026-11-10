<?php

use App\Models\BlogPost;
use App\Support\MarketingPageList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config([
        'app.url' => 'https://hub.hive.contractors',
        'app.marketing_url' => 'https://hive.contractors',
        'app.noindex_hosts' => '',
        'app.physical_address' => '305 S Ridge St, PO Box 1504, Breckenridge, CO 80424',
        'mail.from.address' => 'support@hive.contractors',
    ]);
});

/**
 * /llms-full.txt (2026-11-10): the extended profile gs.construction and
 * jpeterson-design write to disk nightly, served here as a live route from
 * the same sources /llms.txt and /ai-feed.json read.
 */
it('serves llms-full.txt with the business facts, every product area, every marketing page and the FAQ, rooted on the marketing host', function () {
    $response = $this->get('/llms-full.txt')->assertOk();
    $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    $text = $response->getContent();

    expect($text)->toStartWith("# llms-full.txt for Hive Contractors\n")
        ->toContain('> Hive Contractors brings your entire business under one roof.')
        ->toContain("## Business facts\n- Name: Hive Contractors\n- What it is: The CRM built for small contractors\n- Website: https://hive.contractors\n- Email: support@hive.contractors\n- Mailing address: 305 S Ridge St, PO Box 1504, Breckenridge, CO 80424\n- Made in: Chicago area, Illinois, United States\n- Serves: United States\n- Languages: English, Polish, Spanish\n")
        ->toContain('- Pricing: Free for subcontractors under $200K in annual revenue;')
        ->toContain("## What Hive does\n")
        ->toContain("### Finances — https://hive.contractors/en/welcome/finances\nReceipts, bank transactions, payments, checks and payroll flow into one place")
        ->toContain('- Expenses (https://hive.contractors/en/welcome/finances/expenses): Track every cost by project and category with receipts attached.')
        ->toContain('### Estimates & Documents — https://hive.contractors/en/welcome/estimates')
        ->toContain("### Homeowner portal — https://hive.contractors/en/welcome/homeowners\nWhen your contractor works in Hive")
        ->toContain("## Pages\n")
        ->toContain('- [Estimates & Documents](https://hive.contractors/en/welcome/estimates): Build professional estimates with AI')
        ->toContain('](https://hive.contractors/en/welcome/faq): ')
        ->toContain("## Frequently asked questions\n")
        ->toContain("### How much does Hive cost?\nHive is free for subcontractors under $200K in annual revenue.")
        ->toContain('- [Polski](https://hive.contractors/pl/welcome): the same site in Polish.')
        ->toContain('- [Español](https://hive.contractors/es/welcome): the same site in Spanish.')
        ->toContain('- [ai-feed.json](https://hive.contractors/ai-feed.json)')
        ->toContain('- [geo/answers.json](https://hive.contractors/geo/answers.json)')
        ->not->toContain('&amp;')
        ->not->toContain('<strong')
        ->not->toContain('hub.hive.contractors');

    // Every page /llms.txt lists, and every feature card page under its area.
    foreach (MarketingPageList::english() as $page) {
        expect($text)->toContain("]({$page['url']})");
    }
    foreach (config('marketing.areas') as $areaKey => $area) {
        foreach (array_keys($area['cards']) as $cardKey) {
            expect($text)->toContain("(https://hive.contractors/en/welcome/{$areaKey}/{$cardKey})");
        }
    }

    // Every configured question with its answer, once each.
    foreach (config('geo-answers.answers') as $answer) {
        expect(substr_count($text, "### {$answer['q']}\n{$answer['a']}\n"))->toBe(1);
    }
});

it('lists every published blog post with its teaser, newest first, and leaves drafts out', function () {
    BlogPost::create(['status' => 'published', 'published_at' => now()->subDays(2), 'title' => 'Paying crews on time', 'slug' => 'paying-crews-on-time', 'excerpt' => 'How timesheets become paychecks.', 'body_html' => '<p>Body.</p>']);
    BlogPost::create(['status' => 'published', 'published_at' => now()->subDay(), 'title' => 'Lien waivers without the paper', 'slug' => 'lien-waivers-without-the-paper', 'body_html' => '<p>Sign them from the truck.</p>']);
    BlogPost::create(['status' => 'draft', 'title' => 'Unfinished thought', 'slug' => 'unfinished-thought', 'body_html' => '<p>Draft.</p>']);

    $text = $this->get('/llms-full.txt')->assertOk()->getContent();

    expect($text)->toContain("## Blog\n- [Blog](https://hive.contractors/en/blog): Notes on running a small contracting business with Hive.\n- [Lien waivers without the paper](https://hive.contractors/en/blog/lien-waivers-without-the-paper): Sign them from the truck.\n- [Paying crews on time](https://hive.contractors/en/blog/paying-crews-on-time): How timesheets become paychecks.\n")
        ->not->toContain('Unfinished thought');
});

it('is cached for an hour and never noindex', function () {
    $response = $this->get('/llms-full.txt')->assertOk();

    expect((string) $response->headers->get('Cache-Control'))->toContain('max-age=3600');
    expect((string) $response->headers->get('X-Robots-Tag'))->not->toContain('noindex');
});
