<?php

use App\Models\BlogPost;
use App\Support\MarketingPageList;
use App\Support\MarketingPages;
use App\Support\Seo\FaqBank\HiveFaqBankSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use SsSystems\Platform\Seo\Faq\Bank\Contracts\FaqBankSource;
use SsSystems\Platform\Seo\Faq\Bank\Contracts\TextGenerator;
use SsSystems\Platform\Seo\Faq\Bank\FaqBankStore;
use SsSystems\Platform\Seo\Faq\Bank\Generators\FirstConfiguredTextGenerator;
use SsSystems\Platform\Seo\Faq\Bank\Generators\OpenAiTextGenerator;

/**
 * The kit's FAQ bank (0.24.0, 2026-11-10) wired on this site: which pages
 * App\Support\Seo\FaqBank\HiveFaqBankSource hands seo:faq-bank, the
 * container bindings, and one run end to end with a fake TextGenerator
 * over a REAL marketing page rendered in-process — this app is split
 * across hosts (APP_URL is the hub), so the run proves the render at the
 * marketing URL is the marketing page's own words, not a hub redirect —
 * through to /geo/answers.json, /llms-full.txt and the SEO snapshot.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config([
        'app.url' => 'https://hub.hive.contractors',
        'app.marketing_url' => 'https://hive.contractors',
        'app.noindex_hosts' => '',
        'mail.from.address' => 'support@hive.contractors',
        'services.admin_api.token' => ADMIN_API_TEST_TOKEN,
        'services.openai.api_key' => '',
        'services.anthropic.api_key' => '',
    ]);
});

/**
 * A generator that records every prompt and answers each with the same
 * JSON items — the shape the builder's prompt asks for.
 *
 * @param  list<array{q: string, a: string}>  $items
 */
function faqBankFakeGenerator(array $items): TextGenerator
{
    return new class($items) implements TextGenerator
    {
        /** @var list<string> */
        public array $prompts = [];

        /** @param  list<array{q: string, a: string}>  $items */
        public function __construct(private readonly array $items) {}

        public function isConfigured(): bool
        {
            return true;
        }

        public function generate(string $prompt, int $maxOutputTokens = 2000): ?string
        {
            $this->prompts[] = $prompt;

            return (string) json_encode(['items' => $this->items]);
        }

        public function lastError(): ?string
        {
            return null;
        }

        public function label(): string
        {
            return 'Fake generator';
        }
    };
}

it('lists the English marketing pages and the published blog posts on the marketing host by kind, without the FAQ page', function () {
    BlogPost::create(['status' => 'published', 'published_at' => now()->subDays(2), 'title' => 'Paying crews on time', 'slug' => 'paying-crews-on-time', 'body_html' => '<p>Body.</p>']);
    BlogPost::create(['status' => 'published', 'published_at' => now()->subDay(), 'title' => 'Lien waivers without the paper', 'slug' => 'lien-waivers-without-the-paper', 'body_html' => '<p>Body.</p>']);
    BlogPost::create(['status' => 'draft', 'title' => 'Unfinished thought', 'slug' => 'unfinished-thought', 'body_html' => '<p>Draft.</p>']);

    $pages = app(FaqBankSource::class)->pages();
    $urls = array_column($pages, 'url');
    $kindByUrl = array_column($pages, 'kind', 'url');

    expect($urls)->toBe(array_values(array_unique($urls)))->each->toStartWith('https://hive.contractors/en/');
    expect($pages[0])->toBe(['url' => 'https://hive.contractors/en/welcome', 'kind' => 'page']);
    expect($kindByUrl)->toMatchArray([
        'https://hive.contractors/en/welcome/finances' => 'feature',
        'https://hive.contractors/en/welcome/finances/expenses' => 'feature',
        'https://hive.contractors/en/welcome/homeowners' => 'page',
        'https://hive.contractors/en/blog/lien-waivers-without-the-paper' => 'blog',
        'https://hive.contractors/en/blog/paying-crews-on-time' => 'blog',
    ]);

    // Newest post first; the draft, the FAQ page, the blog index and the
    // legal pages are not mined; nothing points at the hub host.
    expect(array_search('https://hive.contractors/en/blog/lien-waivers-without-the-paper', $urls, true))
        ->toBeLessThan(array_search('https://hive.contractors/en/blog/paying-crews-on-time', $urls, true));
    expect($urls)->not->toContain('https://hive.contractors/en/welcome/faq', 'https://hive.contractors/en/blog/unfinished-thought', 'https://hive.contractors/en/blog');
    foreach (MarketingPages::all() as $page) {
        if ($page['type'] === 'legal') {
            expect($urls)->not->toContain(marketing_url(MarketingPages::path($page['route_name'], $page['params'])));
        }
    }
    expect(implode("\n", $urls))->not->toContain('hub.hive.contractors')->not->toContain('/login')->not->toContain('/registration');

    // Every page /llms.txt lists but the FAQ, every feature card page, every published post.
    foreach (MarketingPageList::english() as $page) {
        if ($page['type'] !== 'faq') {
            expect($urls)->toContain($page['url']);
        }
    }
    $areas = config('marketing.areas');
    $cards = array_sum(array_map(fn (array $area): int => count($area['cards'] ?? []), $areas));
    $kinds = array_count_values(array_column($pages, 'kind'));
    expect($kinds)->toBe([
        'page' => count(array_filter(MarketingPageList::english(), fn (array $page): bool => in_array($page['type'], ['other', 'homeowners'], true))),
        'feature' => count($areas) + $cards,
        'blog' => 2,
    ]);
    expect($kinds['feature'])->toBeGreaterThan(60);
});

it('binds the source, the store on the default connection and an OpenAI-then-Anthropic generator', function () {
    $source = app(FaqBankSource::class);
    expect($source)->toBeInstanceOf(HiveFaqBankSource::class);
    expect($source->businessName())->toBe('Hive Contractors')
        ->and($source->language())->toBe('US English')
        ->and($source->towns())->toBe([])
        ->and($source->bannedTerms())->toContain('Procore', 'Buildertrend', 'Jobber', 'CoConstruct', 'Contractor Foreman', 'Houzz Pro', 'JobNimbus', 'ServiceTitan', 'Knowify', 'Foundation Software')
        ->and($source->textFor('https://hive.contractors/en/welcome'))->toBeNull('every page is rendered in-process');

    $store = app(FaqBankStore::class);
    expect($store)->toBeInstanceOf(FaqBankStore::class);
    expect($store->exists())->toBeTrue('both bank tables come from this app\'s own migration');
    expect($store->summary())->toBe(['answers' => 0, 'pages' => 0, 'last_generated_at' => null]);

    $generator = app(TextGenerator::class);
    expect($generator)->toBeInstanceOf(FirstConfiguredTextGenerator::class);
    expect($generator->isConfigured())->toBeFalse()->and($generator->label())->toBe('no provider');

    config(['services.anthropic.api_key' => 'sk-ant-test']);
    expect(app(TextGenerator::class)->label())->toBe('Anthropic claude-haiku-4-5');

    config(['services.openai.api_key' => 'sk-openai-test']);
    $generator = app(TextGenerator::class);
    expect($generator->isConfigured())->toBeTrue()->and($generator->label())->toBe('OpenAI gpt-4.1-mini');

    Http::fake([OpenAiTextGenerator::ENDPOINT => Http::response(['choices' => [['message' => ['content' => '{"items": []}']]]])]);
    expect($generator->generate('prompt'))->toBe('{"items": []}');
    Http::assertSent(fn ($request) => $request->url() === OpenAiTextGenerator::ENDPOINT
        && $request->hasHeader('Authorization', 'Bearer sk-openai-test')
        && $request['model'] === 'gpt-4.1-mini');
});

it('registers and schedules seo:faq-bank nightly', function () {
    expect(array_keys(Artisan::all()))->toContain('seo:faq-bank');

    $this->artisan('schedule:list')->expectsOutputToContain('seo:faq-bank --limit=150');
});

it('skips the run quietly when neither provider key is configured', function () {
    $this->artisan('seo:faq-bank', ['--limit' => 1, '--pause' => 0])
        ->expectsOutputToContain('No AI provider is configured for Hive Contractors — skipping.')
        ->assertExitCode(0);

    expect(DB::table('geo_faq_bank_pages')->count())->toBe(0);
});

it('mines the home page rendered in-process and publishes the answers in the feed, llms-full.txt and the snapshot', function () {
    Storage::fake('local');

    $kept = [
        ['q' => 'What does Hive Contractors automate for a small contractor?', 'a' => 'Hive brings your finances, communication and project planning under one roof, plus vendor collaboration, homeowner updates, timesheets, COIs, receipts and audits, so you can focus on building.'],
        ['q' => 'Why do contractors switch to Hive?', 'a' => 'Hive is built to solve the headaches small contractors actually have: it takes the office work off your plate so you can run more jobs with the same crew.'],
        ['q' => 'Can Hive help me work with homeowners and other jobsite contractors?', 'a' => 'Yes. Hive lets you collaborate with homeowners and the other contractors on a jobsite instead of doing data entry at midnight.'],
        ['q' => 'Does Hive keep receipts and audits in one place?', 'a' => 'Yes. Receipts, COIs, timesheets and audits live in Hive alongside your finances and your project planning.'],
        ['q' => 'Is Hive made for subcontractors as well as general contractors?', 'a' => 'Hive is the CRM built for small contractors, including subcontractors and growing trades businesses, with the whole business under one roof.'],
    ];
    $generator = faqBankFakeGenerator([
        // Dropped by the kit's guard, each for its own reason — listed
        // first because the builder stops reading once --per-page are kept.
        ['q' => 'How does Hive compare with Procore?', 'a' => 'Hive is built for small contractors rather than enterprise teams, so it stays simpler than Procore.'],
        ['q' => 'How many contractors use Hive?', 'a' => 'More than 987654 contractors run their whole business on Hive every single day.'],
        ['q' => 'Hive is great for crews', 'a' => 'Crews love Hive because the office work disappears from their evenings.'],
        ...$kept,
    ]);
    app()->instance(TextGenerator::class, $generator);

    // --limit=1 visits the first never-seen page: the home page.
    $this->artisan('seo:faq-bank', ['--limit' => 1, '--pause' => 0])
        ->expectsOutputToContain('FAQ bank for Hive Contractors via Fake generator: 1 pages written, 5 answers kept, 0 duplicates dropped, 0 unchanged, 0 too thin, 0 failed.')
        ->expectsOutputToContain('Rejected: banned_term 1, number_not_on_page 1, not_a_question 1')
        ->expectsOutputToContain('The bank holds 5 answers from 1 pages.')
        ->assertExitCode(0);

    // The prompt carried the marketing page's own words — the URL on the
    // marketing host rendered in-process (no hub redirect), the page's
    // content as text, with the footer dropped as chrome.
    expect($generator->prompts)->toHaveCount(1);
    expect($generator->prompts[0])->toContain('page of their website (https://hive.contractors/en/welcome)')
        ->toContain('The CRM built for small contractors')
        ->toContain('Why contractors switch to Hive')
        ->toContain('Built to solve the headaches small contractors actually have')
        ->not->toContain('All rights reserved')
        ->not->toContain('<p')
        ->not->toContain('hub.hive.contractors');

    $page = DB::table('geo_faq_bank_pages')->sole();
    expect($page->url)->toBe('https://hive.contractors/en/welcome')
        ->and($page->kind)->toBe('page')
        ->and((int) $page->answers)->toBe(5)
        ->and($page->site_id)->toBeNull()
        ->and($page->last_error)->toBeNull();
    expect(DB::table('geo_faq_bank')->where('source_url', 'https://hive.contractors/en/welcome')->where('kind', 'page')->count())->toBe(5);
    expect(DB::table('geo_faq_bank')->orderBy('position')->pluck('question')->all())->toBe(array_column($kept, 'q'));

    // /geo/answers.json: the hand-written list first and unchanged, then
    // the bank, each with the page it was derived from on its Question.
    $entity = $this->getJson('/geo/answers.json')->assertOk()->json('mainEntity');
    $handWritten = array_column(config('geo-answers.answers'), 'q');
    expect(array_column($entity, 'name'))->toBe([...$handWritten, ...array_column($kept, 'q')]);
    expect($entity[0])->not->toHaveKey('url');
    $first = $entity[count($handWritten)];
    expect($first['url'])->toBe('https://hive.contractors/en/welcome')
        ->and($first['topics'])->toBe(['page'])
        ->and($first['acceptedAnswer']['text'])->toBe($kept[0]['a']);

    // /llms-full.txt: the section at the end, grouped under the page.
    $text = $this->get('/llms-full.txt')->assertOk()->getContent();
    expect($text)->toContain("## Questions and answers\n\n5 more questions and answers, each derived from one page of this site and grouped under the page it restates. They follow the questions above in https://hive.contractors/geo/answers.json.\n")
        ->toContain(" — https://hive.contractors/en/welcome\n\n#### {$kept[0]['q']}\n{$kept[0]['a']}\n\n#### {$kept[1]['q']}\n{$kept[1]['a']}\n")
        ->toEndWith("#### {$kept[4]['q']}\n{$kept[4]['a']}\n")
        ->not->toContain('Procore');
    expect(strpos($text, '## Questions and answers'))->toBeGreaterThan(strpos($text, '## Machine-readable feeds'));
    foreach (config('geo-answers.answers') as $answer) {
        expect(substr_count($text, "### {$answer['q']}\n{$answer['a']}\n"))->toBe(1);
    }

    // The SEO snapshot's geo section counts the bank.
    $geo = $this->getJson('/api/admin/v1/seo/snapshot', adminApiHeaders())->assertOk()->json('data.geo');
    expect($geo['faq_bank']['answers'])->toBe(5)
        ->and($geo['faq_bank']['pages'])->toBe(1)
        ->and($geo['faq_bank']['last_generated_at'])->not->toBeNull();

    // The next nightly run moves on to a page it has never seen — the
    // home page is only rebuilt once its text changes.
    $this->artisan('seo:faq-bank', ['--limit' => 1, '--pause' => 0, '--dry-run' => true])
        ->expectsOutputToContain('1 pages would be built:')
        ->expectsOutputToContain('  https://hive.contractors/en/welcome/automation')
        ->assertExitCode(0);
    expect($generator->prompts)->toHaveCount(1);
});

it('mines a published blog post rendered in-process and labels it blog', function () {
    BlogPost::create([
        'status' => 'published',
        'published_at' => now()->subDay(),
        'title' => 'Lien waivers without the paper',
        'slug' => 'lien-waivers-without-the-paper',
        'excerpt' => 'Sign them from the truck.',
        'body_html' => '<p>'.str_repeat('A lien waiver signed from the truck keeps the draw moving and the paper off the dashboard. ', 8).'</p>',
    ]);
    $generator = faqBankFakeGenerator([
        ['q' => 'Can a lien waiver be signed from the truck?', 'a' => 'Yes. A lien waiver signed from the truck keeps the draw moving and the paper off the dashboard.'],
    ]);
    app()->instance(TextGenerator::class, $generator);

    $this->artisan('seo:faq-bank', ['--only' => '/en/blog/', '--pause' => 0])
        ->expectsOutputToContain('1 pages written, 1 answers kept')
        ->assertExitCode(0);

    expect($generator->prompts)->toHaveCount(1);
    expect($generator->prompts[0])->toContain('one blog page of their website (https://hive.contractors/en/blog/lien-waivers-without-the-paper)')
        ->toContain('Lien waivers without the paper')
        ->toContain('keeps the draw moving and the paper off the dashboard');

    $row = DB::table('geo_faq_bank')->sole();
    expect($row->kind)->toBe('blog')
        ->and($row->source_url)->toBe('https://hive.contractors/en/blog/lien-waivers-without-the-paper')
        ->and($row->question)->toBe('Can a lien waiver be signed from the truck?');

    // The same post, unchanged, costs nothing on the next run.
    $this->artisan('seo:faq-bank', ['--only' => '/en/blog/', '--pause' => 0])
        ->expectsOutputToContain('0 pages written, 0 answers kept, 0 duplicates dropped, 1 unchanged, 0 too thin, 0 failed.')
        ->assertExitCode(0);
    expect($generator->prompts)->toHaveCount(1);
    expect(DB::table('geo_faq_bank')->count())->toBe(1);
});
