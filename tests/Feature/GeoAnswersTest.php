<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config([
        'app.url' => 'https://hub.hive.contractors',
        'app.marketing_url' => 'https://hive.contractors',
        'app.noindex_hosts' => '',
        'mail.from.address' => 'support@hive.contractors',
    ]);
});

/**
 * /geo/answers.json (2026-11-10): the kit's BuildsFaqPageFeed envelope over
 * config/geo-answers.php — the FAQPage feed gs.construction and
 * jpeterson-design already serve.
 */
it('serves geo/answers.json as FAQPage JSON-LD with every configured question, rooted on the marketing host', function () {
    $response = $this->getJson('/geo/answers.json')->assertOk();

    expect((string) $response->headers->get('Content-Type'))->toContain('application/json');

    $data = $response->json();

    expect($data['@context'])->toBe('https://schema.org')
        ->and($data['@type'])->toBe('FAQPage')
        ->and($data['name'])->toBe('Hive Contractors — Q&A for AI engines')
        ->and($data['url'])->toBe('https://hive.contractors/geo/answers.json');
    expect(fn () => Carbon::parse($data['dateModified']))->not->toThrow(\Exception::class);
    expect($data['publisher'])->toBe([
        '@type' => 'Organization',
        'name' => 'Hive Contractors',
        'url' => 'https://hive.contractors/',
        'telephone' => null,
        'email' => 'support@hive.contractors',
        'areaServed' => 'United States',
        'knowsLanguage' => ['English', 'Polish', 'Spanish'],
    ]);

    $configured = array_column(config('geo-answers.answers'), 'q');
    expect(count($configured))->toBeGreaterThanOrEqual(8)->toBeLessThanOrEqual(15);
    expect(array_column($data['mainEntity'], 'name'))->toBe($configured)->toContain('How much does Hive cost?');

    foreach ($data['mainEntity'] as $question) {
        expect($question['@type'])->toBe('Question')
            ->and($question['acceptedAnswer']['@type'])->toBe('Answer')
            ->and(mb_strlen($question['acceptedAnswer']['text']))->toBeGreaterThan(40)->toBeLessThanOrEqual(320)
            ->and($question['topics'])->not->toBe([]);
    }

    expect($response->getContent())->not->toContain('hub.hive.contractors');
});

it('answers in the public FAQ page\'s own words', function () {
    // A feed that says more than the pages do is the one thing never to
    // ship: these answers are quoted from /en/welcome/faq verbatim.
    $faq = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($this->get('/en/welcome/faq')->assertOk()->getContent()), ENT_QUOTES | ENT_HTML5));

    foreach (['Who is Hive Contractors for?', 'How much does Hive cost?', 'Is there a mobile app?', 'Does Hive work with QuickBooks and my bank?', 'Can homeowners see their project?', 'Is my data secure?'] as $question) {
        $answer = collect(config('geo-answers.answers'))->firstWhere('q', $question);

        expect($answer)->not->toBeNull("\"{$question}\" is no longer configured");
        expect($faq)->toContain($question)->toContain($answer['a']);
    }
});

it('is cached for an hour with the public headers and never noindex', function () {
    $response = $this->get('/geo/answers.json')->assertOk();

    expect((string) $response->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=3600');
    expect($response->headers->get('X-Robots-Tag'))->toBe('all');
});
