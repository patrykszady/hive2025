<?php

use App\Models\BlogPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config(['app.url' => 'https://hub.hive.contractors', 'app.marketing_url' => 'https://hive.contractors']);
});

/**
 * /llms.txt (2026-10-29): the marketing site for AI search — every public
 * page with the same description its own meta tag carries, rooted on the
 * marketing host, plus the blog and the other languages.
 */
it('serves llms.txt with every marketing page, its own description, and the other languages', function () {
    $response = $this->get('/llms.txt')->assertOk();
    $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    $text = $response->getContent();

    expect($text)->toStartWith("# Hive Contractors\n")
        ->and($text)->toContain('> Hive Contractors brings your entire business under one roof.')
        ->and($text)->toContain('- [Estimates & Documents](https://hive.contractors/en/welcome/estimates): Build professional estimates with AI')
        ->and($text)->toContain('](https://hive.contractors/en/welcome/finances): ')
        ->and($text)->toContain('](https://hive.contractors/en/welcome/faq): ')
        ->and($text)->toContain('- [Polski](https://hive.contractors/pl/welcome)')
        ->and($text)->not->toContain('&amp;')
        ->and($text)->not->toContain('<strong')
        ->and($text)->not->toContain('hub.hive.contractors');
});

it('lists published blog posts, newest first, and leaves drafts out', function () {
    BlogPost::create(['status' => 'published', 'published_at' => now()->subDay(), 'title' => 'Paying crews on time', 'slug' => 'paying-crews-on-time', 'excerpt' => 'How timesheets become paychecks.', 'body_html' => '<p>Body.</p>']);
    BlogPost::create(['status' => 'draft', 'title' => 'Unfinished thought', 'slug' => 'unfinished-thought', 'body_html' => '<p>Draft.</p>']);

    $text = $this->get('/llms.txt')->assertOk()->getContent();

    expect($text)->toContain('## Blog')
        ->and($text)->toContain('- [Paying crews on time](https://hive.contractors/en/blog/paying-crews-on-time): How timesheets become paychecks.')
        ->and($text)->not->toContain('Unfinished thought');
});
