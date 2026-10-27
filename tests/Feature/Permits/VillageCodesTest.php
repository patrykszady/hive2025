<?php

use App\Jobs\Permits\FindVillageCodes;
use App\Models\VillageCodes;
use App\Services\Permits\VillageCodeFinder;
use App\Support\Permits\BuildingCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 * Village building codes, read off each village's own website
 * (2026-10-26, Patryk: "we need to do a better job finding village codes …
 * lakezurich.org/202/Residential-Codes … how often do we query the village
 * websites for updates?"). Found when a permit packet first needs the town,
 * re-read on the first of every month.
 */

const LZ_PAGE = 'https://lakezurich.org/202/Residential-Codes';

const LZ_CODES = [
    'International Building Code, 2018 Edition',
    'International Residential Code, 2018 Edition',
    'International Mechanical Code, 2018 Edition',
    'International Property Maintenance Code, 2018 Edition',
    'International Fire Code, 2018 Edition',
    'Illinois Plumbing Code, 2014 Edition',
    'National Electrical Code, 2017 Edition',
    'International Fuel Gas Code, 2018 Edition',
    'International Energy Conservation Code, 2024 Edition',
    'Illinois Accessibility Code, 2018 Edition',
];

/** What lakezurich.org actually serves (trimmed): CivicPlus chrome around a plain list. */
function lakeZurichHtml(): string
{
    return '<html><head><title>Residential Codes | Lake Zurich, IL</title><script>var x=1;</script><style>.a{}</style></head><body>'
        .'<nav><a href="/">Home</a><a href="/Services">Services</a></nav>'
        .'<h1>Residential Codes</h1><h2>Codes</h2><ul>'
        .'<li>International Building Code 2018</li><li>International Residential Code 2018</li><li>International Mechanical Code 2018</li>'
        .'<li>International Property Maintenance Code 2018</li><li>International Fire Code 2018</li><li>Illinois Plumbing Code 2014</li>'
        .'<li>National Electric Code 2017</li><li>International Fuel Gas Code 2018</li><li>International Energy Conservation Code 2024</li>'
        .'<li>Illinois Accessibility Code - 2018</li></ul>'
        .'<p>Local amendments to the above listed codes can be found in Title 8 of the Lake Zurich Municipal Code.</p>'
        .'<footer>Government Websites by CivicPlus&reg;</footer></body></html>';
}

/** @param  list<string>  $urls */
function fakeLocate(array $urls): array
{
    return ['output' => [
        ['type' => 'web_search_call', 'status' => 'completed'],
        ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Here you go: '.json_encode(['urls' => $urls])]]],
    ]];
}

/** @param  list<string>  $codes */
function fakeExtract(bool $found, array $codes, ?string $note = null): array
{
    return ['choices' => [['message' => ['content' => json_encode(['found' => $found, 'town' => 'Lake Zurich', 'codes' => $codes, 'note' => $note])]]]];
}

beforeEach(function () {
    config(['services.openai.api_key' => 'test-key']);
});

it('finds Lake Zurich\'s codes on lakezurich.org, grounded in the page it fetched, and the cover letter uses them', function () {
    Http::fake([
        'api.openai.com/v1/responses' => Http::response(fakeLocate([LZ_PAGE])),
        'lakezurich.org/*' => Http::response(lakeZurichHtml(), 200, ['Content-Type' => 'text/html; charset=utf-8']),
        'api.openai.com/v1/chat/completions' => Http::response(fakeExtract(true, LZ_CODES)),
    ]);

    $row = app(VillageCodeFinder::class)->find('Village of Lake Zurich');

    expect($row->town)->toBe('Lake Zurich')
        ->and($row->status)->toBe(VillageCodes::STATUS_FOUND)
        ->and($row->found_by)->toBe(VillageCodes::FOUND_BY_AI)
        ->and($row->source_url)->toBe(LZ_PAGE)
        ->and($row->codes)->toBe(LZ_CODES)
        ->and($row->checked_at)->not->toBeNull()
        ->and($row->changed_at)->toBeNull();

    // The model read the page's own text — menus and scripts stripped — not its memory.
    Http::assertSent(function (Request $request) {
        if (! str_contains($request->url(), 'chat/completions')) {
            return false;
        }
        $user = (string) data_get($request->data(), 'messages.1.content');

        return str_contains($user, 'Town: Lake Zurich')
            && str_contains($user, 'National Electric Code 2017')
            && ! str_contains($user, 'var x=1')
            && ! str_contains($user, 'CivicPlus');
    });

    expect(BuildingCodes::isKnown('lake zurich'))->toBeTrue()
        ->and(BuildingCodes::for('Lake Zurich'))->toBe(LZ_CODES)
        ->and(BuildingCodes::sourceFor('Lake Zurich'))->toBe(LZ_PAGE)
        ->and(BuildingCodes::match('village of lake zurich'))->toBe('Lake Zurich');
});

it('records a town whose website lists nothing, and the usual list stands in', function () {
    Http::fake([
        'api.openai.com/v1/responses' => Http::response(fakeLocate([])),
    ]);

    $row = app(VillageCodeFinder::class)->find('Long Grove');

    expect($row->status)->toBe(VillageCodes::STATUS_NOT_FOUND)
        ->and($row->codes)->toBeNull()
        ->and(BuildingCodes::isKnown('Long Grove'))->toBeFalse()
        ->and(BuildingCodes::for('Long Grove'))->toBe(BuildingCodes::DEFAULT_CODES);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'chat/completions'));
});

it('puts a residential list ahead of a commercial one when the town keeps two', function () {
    Http::fake([
        'api.openai.com/v1/responses' => Http::response(fakeLocate(['https://lakezurich.org/201/Commercial-Codes', LZ_PAGE, 'https://lakezurich.org/200/Permits'])),
    ]);

    expect(app(VillageCodeFinder::class)->locate('Lake Zurich'))->toBe([LZ_PAGE, 'https://lakezurich.org/201/Commercial-Codes', 'https://lakezurich.org/200/Permits']);
});

it('follows the site\'s own "Residential Codes" link when the search lands on the commercial list', function () {
    $commercial = '<html><body><nav><ul><li><a href="/201/Commercial-Codes">Commercial Codes</a></li><li><a href="/202/Residential-Codes">Residential Codes</a></li></ul></nav>'
        .'<h1>Commercial Codes</h1><ul><li>International Building Code 2018</li><li>International Energy Conservation Code 2021</li><li>National Electric Code 2017</li></ul>'
        .'<p>Local amendments to the above listed codes can be found in Title 8 of the Lake Zurich Municipal Code and this sentence makes the page long enough.</p></body></html>';
    Http::fake([
        'api.openai.com/v1/responses' => Http::response(fakeLocate(['https://lakezurich.org/201/Commercial-Codes'])),
        'lakezurich.org/201/*' => Http::response($commercial),
        'lakezurich.org/202/*' => Http::response(lakeZurichHtml()),
        'api.openai.com/v1/chat/completions' => Http::sequence()
            ->push(fakeExtract(true, ['International Building Code, 2018 Edition', 'International Energy Conservation Code, 2021 Edition', 'National Electrical Code, 2017 Edition']))
            ->push(fakeExtract(true, LZ_CODES)),
    ]);

    $row = app(VillageCodeFinder::class)->find('Lake Zurich');

    expect($row->source_url)->toBe(LZ_PAGE)->and($row->codes)->toBe(LZ_CODES);

    // Relative, root and absolute links all resolve on the same site; other sites and the page itself do not count.
    $finder = app(VillageCodeFinder::class);
    expect($finder->residentialSibling('https://lakezurich.org/201/Commercial-Codes', '<a href="202/Residential-Codes">Residential codes</a>'))->toBe('https://lakezurich.org/201/202/Residential-Codes')
        ->and($finder->residentialSibling('https://lakezurich.org/201/Commercial-Codes', '<a href="https://iccsafe.org/residential-codes">Residential codes</a>'))->toBeNull()
        ->and($finder->residentialSibling(LZ_PAGE, '<a href="/201/Commercial-Codes">Commercial Codes</a>'))->toBeNull();
});

it('skips a page that turns out to be about somewhere else and tries the next candidate', function () {
    Http::fake([
        'api.openai.com/v1/responses' => Http::response(fakeLocate(['https://lakecountyil.gov/residential-codes', LZ_PAGE])),
        'lakecountyil.gov/*' => Http::response('<html><body><p>Lake County adopted codes for unincorporated areas: International Residential Code 2021 and more text here to pass the length check.</p></body></html>'),
        'lakezurich.org/*' => Http::response(lakeZurichHtml()),
        'api.openai.com/v1/chat/completions' => Http::sequence()
            ->push(fakeExtract(false, []))
            ->push(fakeExtract(true, LZ_CODES)),
    ]);

    $row = app(VillageCodeFinder::class)->find('Lake Zurich');

    expect($row->status)->toBe(VillageCodes::STATUS_FOUND)->and($row->source_url)->toBe(LZ_PAGE);
});

it('re-reads the page on file: notes a changed list, keeps the old one when the page breaks', function () {
    $this->freezeSecond();
    $row = VillageCodes::factory()->create(['town' => 'Lake Zurich', 'source_url' => LZ_PAGE, 'codes' => LZ_CODES, 'checked_at' => now()->subMonth()]);

    // One fake for the whole test — a later Http::fake() never overrides an earlier stub — steered by these.
    $pageHtml = lakeZurichHtml();
    $pageStatus = 200;
    $extracted = fakeExtract(true, LZ_CODES);
    $located = fakeLocate([]);
    Http::fake([
        'lakezurich.org/*' => function () use (&$pageHtml, &$pageStatus) {
            return Http::response($pageHtml, $pageStatus);
        },
        'api.openai.com/v1/chat/completions' => function () use (&$extracted) {
            return Http::response($extracted);
        },
        'api.openai.com/v1/responses' => function () use (&$located) {
            return Http::response($located);
        },
    ]);

    // Unchanged.
    $row = app(VillageCodeFinder::class)->refresh($row);
    expect($row->changed_at)->toBeNull()->and($row->checked_at->equalTo(now()))->toBeTrue()->and($row->last_error)->toBeNull();

    // The village moves to the 2021 cycle.
    $newer = array_map(fn ($c) => str_replace('2018 Edition', '2021 Edition', $c), LZ_CODES);
    $pageHtml = str_replace('2018', '2021', lakeZurichHtml());
    $extracted = fakeExtract(true, $newer, 'Effective January 1, 2027');
    $row = app(VillageCodeFinder::class)->refresh($row);
    expect($row->codes)->toBe($newer)
        ->and($row->previous_codes)->toBe(LZ_CODES)
        ->and($row->changed_at?->equalTo(now()))->toBeTrue()
        ->and($row->note)->toBe('Effective January 1, 2027')
        ->and(BuildingCodes::for('Lake Zurich'))->toBe($newer);

    // The page 404s and the search finds nothing: the list stands, with a note of the failure.
    $pageStatus = 404;
    $pageHtml = 'Not found';
    $row = app(VillageCodeFinder::class)->refresh($row);
    expect($row->status)->toBe(VillageCodes::STATUS_FOUND)
        ->and($row->codes)->toBe($newer)
        ->and($row->last_error)->toContain('no longer lists the codes');
});

it('reads a PDF handout and a blocked page through Chrome only when the plain fetch is not enough', function () {
    $finder = Mockery::mock(VillageCodeFinder::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $finder->shouldReceive('chromeHtml')->once()->with('https://blocked.example/codes')->andReturn(lakeZurichHtml());
    $finder->shouldReceive('chromeHtml')->never()->with(LZ_PAGE);

    Http::fake([
        'blocked.example/*' => Http::response('Access denied', 403),
        'lakezurich.org/*' => Http::response(lakeZurichHtml()),
        'api.openai.com/v1/chat/completions' => Http::response(fakeExtract(true, LZ_CODES)),
    ]);

    expect($finder->read('Lake Zurich', 'https://blocked.example/codes')['codes'])->toBe(LZ_CODES)
        ->and($finder->read('Lake Zurich', LZ_PAGE)['codes'])->toBe(LZ_CODES);
});

it('turns a page into readable text: no scripts, menus or footers, one line per block', function () {
    $text = VillageCodeFinder::htmlToText(lakeZurichHtml());

    expect($text)->toContain("Residential Codes\nCodes\nInternational Building Code 2018\nInternational Residential Code 2018")
        ->and($text)->not->toContain('var x=1')
        ->and($text)->not->toContain('CivicPlus')
        ->and($text)->not->toContain('Home');
});

it('marks the row failed when the background lookup dies, so the dialog stops waiting', function () {
    $row = VillageCodes::factory()->pending()->create(['town' => 'Lake Zurich']);

    (new FindVillageCodes('Lake Zurich'))->failed(new RuntimeException('village_codes_locate_http_500'));

    expect($row->fresh()->status)->toBe(VillageCodes::STATUS_FAILED)
        ->and($row->fresh()->last_error)->toBe('village_codes_locate_http_500');
});

it('refreshes every town on the first of the month: seeds the hand-typed towns, re-reads pages, looks up the rest', function () {
    Http::fake([
        'cityrm.org/*' => Http::response('<html><body><h1>Building Codes</h1><p>For permits issued after 1/1/2026 the following codes apply: 2024 International Residential Code and more text so the page is long enough to read.</p></body></html>'),
        'palatine.il.us/*' => Http::response('<html><body><h1>Building Code Information</h1><p>The Village of Palatine has adopted the 2021 International Residential Code and more text so the page is long enough to read.</p></body></html>'),
        'lakezurich.org/*' => Http::response(lakeZurichHtml()),
        'api.openai.com/v1/responses' => Http::response(fakeLocate([LZ_PAGE])),
        'api.openai.com/v1/chat/completions' => function (Request $request) {
            $page = (string) data_get($request->data(), 'messages.1.content');

            return Http::response(match (true) {
                str_contains($page, 'cityrm.org') => fakeExtract(true, BuildingCodes::JURISDICTIONS['Rolling Meadows']['codes'], 'For permits issued after 1/1/2026'),
                str_contains($page, 'palatine.il.us') => fakeExtract(true, array_merge(BuildingCodes::JURISDICTIONS['Palatine']['codes'], ['International Property Maintenance Code, 2021 Edition'])),
                default => fakeExtract(true, LZ_CODES),
            });
        },
    ]);
    VillageCodes::factory()->pending()->create(['town' => 'Lake Zurich']);

    $this->artisan('permits:refresh-village-codes --find')
        ->expectsOutputToContain('Lake Zurich: 10 codes, found')
        ->expectsOutputToContain('Palatine: 11 codes, changed (+1 −0)')
        ->expectsOutputToContain('Rolling Meadows: 10 codes, unchanged')
        ->assertSuccessful();

    $rm = VillageCodes::query()->named('Rolling Meadows')->sole();
    $pal = VillageCodes::query()->named('Palatine')->sole();
    expect($rm->found_by)->toBe(VillageCodes::FOUND_BY_HAND)
        ->and($rm->note)->toBe('For permits issued after 1/1/2026')
        ->and($rm->changed_at)->toBeNull()
        ->and($pal->found_by)->toBe(VillageCodes::FOUND_BY_AI)
        ->and($pal->previous_codes)->toBe(BuildingCodes::JURISDICTIONS['Palatine']['codes'])
        ->and($pal->changed_at)->not->toBeNull()
        ->and(BuildingCodes::for('Palatine'))->toContain('International Property Maintenance Code, 2021 Edition')
        ->and(VillageCodes::query()->named('Lake Zurich')->sole()->isFound())->toBeTrue();

    // One town only: nobody else's page is fetched again.
    $palatineFetches = Http::recorded(fn (Request $r) => str_contains($r->url(), 'palatine.il.us'))->count();
    $this->artisan('permits:refresh-village-codes --town="lake zurich"')
        ->expectsOutputToContain('Lake Zurich: 10 codes, unchanged')
        ->assertSuccessful();
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'palatine.il.us'))->count())->toBe($palatineFetches);
});
