<?php

use App\Jobs\AnalyzeEstimateSubDocument;
use App\Livewire\Estimates\EstimateSubDocumentCompare;
use App\Models\Bid;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateLineItem;
use App\Models\EstimateSection;
use App\Models\EstimateSubDocument;
use App\Models\EstimateSubDocumentItem;
use App\Models\LineItem;
use App\Models\Project;
use App\Models\SubItemAlias;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Estimates\SubDocumentChangeOrderApplier;
use App\Services\Estimates\SubDocumentMatcher;
use App\Services\Estimates\SubDocumentReader;
use App\Services\Estimates\SubItemMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

/*
 * "Compare sub invoice" (2026-10-25): a sub's updated estimate/invoice is
 * read, matched to our estimate's catalog items and turned into change-order
 * additions and credits at OUR prices — built on Smartech Electric's invoice
 * #0062 against 3154 Violet's estimate.
 */

beforeEach(function () {
    config(['services.openai.api_key' => 'test-key', 'services.openai.sub_document_model' => 'gpt-4.1']);
});

/**
 * GS, its admin, a project with an unsigned estimate: the electrical lines
 * of the Violet estimate in miniature, a tile line from another trade, a
 * catalog item never estimated (the EV charger), and Smartech as a bidder.
 */
function subDocumentWorld(): array
{
    $gs = Vendor::query()->create(['business_name' => 'GS Construction & Remodeling, Inc', 'business_type' => 'Sub', 'address' => '1 Main St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601']);
    $admin = User::query()->create(['first_name' => 'Patryk', 'last_name' => 'Szady', 'email' => 'p'.Str::random(6).'@example.test', 'cell_phone' => '7'.random_int(100000000, 999999999), 'password' => bcrypt('x'), 'primary_vendor_id' => $gs->id, 'registration' => ['registered' => true]]);
    $gs->users()->attach($admin->id, ['is_employed' => true, 'role_id' => 1]);
    test()->actingAs($admin);

    $client = Client::query()->create(['business_name' => 'Brodson', 'address' => '3154 Violet Ln', 'city' => 'Northbrook', 'state' => 'IL', 'zip_code' => '60062']);
    $client->vendors()->attach($gs->id, ['source' => 'test']);
    $project = Project::query()->create(['project_name' => 'Home Renovation', 'client_id' => $client->id, 'belongs_to_vendor_id' => $gs->id, 'address' => '3154 Violet Ln', 'city' => 'Northbrook', 'state' => 'IL', 'zip_code' => '60062']);
    ProjectStatus::create(['project_id' => $project->id, 'belongs_to_vendor_id' => $gs->id, 'status_code' => 6, 'start_date' => now()->subMonth()]);

    $estimate = Estimate::withoutGlobalScopes()->create(['project_id' => $project->id, 'belongs_to_vendor_id' => $gs->id, 'options' => []]);
    $section = EstimateSection::query()->create(['estimate_id' => $estimate->id, 'name' => 'Rough Construction', 'order' => 0, 'total' => 0]);

    $catalog = [];
    foreach ([
        'recessed' => ['4" Recessed', 'Electrical', 'Rough', 'pieces', 240, 'Install new square recessed light.'],
        'gfci' => ['New GFCI Location', 'Electrical', 'Rough', 'pieces', 230, 'Install a new GFCI outlet location.'],
        'smoke' => ['Smoke Detector System', 'Electrical', 'Code Compliance', 'pieces', 145, 'Install smoke/CO detectors.'],
        'circuit' => ['Dedicated Circuit', 'Electrical', 'Rough', 'pieces', 850, 'Install dedicated circuit line.'],
        'eliminate' => ['Eliminate Electrical', 'Electrical', 'Rough', 'no_unit', 4000, 'Eliminate existing electrical.'],
        'fixture' => ['Replace Light Fixture', 'Electrical', 'Finish', 'pieces', 95, 'Install a standard fixture in an existing location.'],
        'ev' => ['EV Charger Circuit', 'Electrical', 'Rough', 'pieces', 950, 'Install a 240V line for a car charger.'],
        'tile' => ['Floor Tile', 'Tiles', 'Floor', 'sq.ft.', 23, 'Install floor tile.'],
    ] as $key => [$name, $category, $sub, $unit, $cost, $desc]) {
        $catalog[$key] = LineItem::query()->create(['name' => $name, 'category' => $category, 'sub_category' => $sub, 'unit_type' => $unit, 'cost' => $cost, 'desc' => $desc, 'belongs_to_vendor_id' => $gs->id]);
    }

    $lines = [];
    $order = 0;
    foreach ([['recessed', 61], ['recessed', 26], ['gfci', 25], ['gfci', 10], ['smoke', 7], ['circuit', 10], ['eliminate', 1], ['fixture', 4], ['tile', 30]] as [$key, $qty]) {
        $item = $catalog[$key];
        $lines[] = EstimateLineItem::query()->create([
            'estimate_id' => $estimate->id, 'line_item_id' => $item->id, 'section_id' => $section->id, 'order' => $order++,
            'name' => $item->name, 'category' => $item->category, 'sub_category' => $item->sub_category, 'unit_type' => $item->unit_type,
            'quantity' => $qty, 'cost' => $item->cost, 'total' => $qty * $item->cost, 'desc' => $item->desc,
        ]);
    }

    $smartech = Vendor::query()->create(['business_name' => 'Smartech Electric, Inc', 'business_type' => 'Sub', 'address' => '102 Brook Rd', 'city' => 'Prospect Heights', 'state' => 'IL', 'zip_code' => '60070']);
    Bid::create(['project_id' => $project->id, 'vendor_id' => $smartech->id, 'amount' => 56595, 'type' => 1]);

    return compact('gs', 'admin', 'project', 'estimate', 'section', 'catalog', 'lines', 'smartech');
}

/** Smartech's invoice as the reader returns it, in miniature. */
function smartechDocument(): array
{
    return [
        'vendor_name' => 'Smartech Electric', 'document_type' => 'invoice', 'document_number' => '0062', 'document_date' => '2026-10-22',
        'project_reference' => '3154 Violet Ln Northbrook', 'total' => 56595.0, 'payments_to_date' => 9000.0, 'model' => 'gpt-4.1',
        'items' => [
            ['index' => 1, 'description' => 'Recessed LED square light', 'quantity' => 78.0, 'unit' => null, 'unit_price' => 130.0, 'amount' => 10140.0],
            ['index' => 2, 'description' => "2'' cans", 'quantity' => 9.0, 'unit' => null, 'unit_price' => 145.0, 'amount' => 1305.0],
            ['index' => 3, 'description' => '20AMP GFCI outlet', 'quantity' => 25.0, 'unit' => null, 'unit_price' => 115.0, 'amount' => 2875.0],
            ['index' => 4, 'description' => 'GFCI exterior', 'quantity' => 12.0, 'unit' => null, 'unit_price' => 135.0, 'amount' => 1620.0],
            ['index' => 5, 'description' => 'Smoke detectors combo', 'quantity' => 3.0, 'unit' => null, 'unit_price' => 145.0, 'amount' => 435.0],
            ['index' => 6, 'description' => 'Smoke detector', 'quantity' => 6.0, 'unit' => null, 'unit_price' => 165.0, 'amount' => 990.0],
            ['index' => 7, 'description' => 'Power for range 240v', 'quantity' => 1.0, 'unit' => null, 'unit_price' => 950.0, 'amount' => 950.0],
            ['index' => 8, 'description' => 'Power for appliance', 'quantity' => 7.0, 'unit' => null, 'unit_price' => 120.0, 'amount' => 840.0],
            ['index' => 9, 'description' => 'Remove rework existing electrical', 'quantity' => 1.0, 'unit' => null, 'unit_price' => 3400.0, 'amount' => 3400.0],
            ['index' => 10, 'description' => '¾ line for car charger 240V', 'quantity' => 1.0, 'unit' => null, 'unit_price' => 950.0, 'amount' => 950.0],
            ['index' => 11, 'description' => 'Under cabinets, shelves, niches LED low voltage lights', 'quantity' => 7.0, 'unit' => null, 'unit_price' => 650.0, 'amount' => 4550.0],
        ],
    ];
}

/** The model's matching of that invoice to the world's catalog. */
function smartechAssignments(array $catalog): array
{
    $id = fn (string $key) => $catalog[$key]->id;

    return ['trades' => ['Electrical'], 'assignments' => [
        ['item_index' => 1, 'line_item_id' => $id('recessed'), 'comparable' => true, 'note' => ''],
        ['item_index' => 2, 'line_item_id' => $id('recessed'), 'comparable' => true, 'note' => ''],
        ['item_index' => 3, 'line_item_id' => $id('gfci'), 'comparable' => true, 'note' => ''],
        ['item_index' => 4, 'line_item_id' => $id('gfci'), 'comparable' => true, 'note' => ''],
        ['item_index' => 5, 'line_item_id' => $id('smoke'), 'comparable' => true, 'note' => ''],
        ['item_index' => 6, 'line_item_id' => $id('smoke'), 'comparable' => true, 'note' => ''],
        ['item_index' => 7, 'line_item_id' => $id('circuit'), 'comparable' => true, 'note' => ''],
        ['item_index' => 8, 'line_item_id' => $id('circuit'), 'comparable' => true, 'note' => ''],
        ['item_index' => 9, 'line_item_id' => $id('eliminate'), 'comparable' => true, 'note' => 'Lump sum.'],
        ['item_index' => 10, 'line_item_id' => $id('ev'), 'comparable' => true, 'note' => ''],
        ['item_index' => 11, 'line_item_id' => null, 'comparable' => false, 'note' => 'Low-voltage lighting runs; no catalog item.'],
    ]];
}

function openAiJson(array $payload): \GuzzleHttp\Promise\PromiseInterface
{
    return Http::response(['choices' => [['message' => ['content' => json_encode($payload)]]]]);
}

it('reads a spreadsheet export as text and a PDF as a file', function () {
    Storage::fake('local');
    $dir = Storage::disk('local')->path('');
    $sheet = new Spreadsheet;
    $sheet->getActiveSheet()->fromArray([['QTY', 'DESCRIPTION', 'AMOUNT'], [78, 'Recessed LED square light', 10140], [25, '20AMP GFCI outlet', 2875]]);
    (new Xlsx($sheet))->save($dir.'/invoice.xlsx');
    file_put_contents($dir.'/invoice.pdf', '%PDF-1.4 fake');

    Http::fake(['api.openai.com/*' => openAiJson(smartechDocument())]);

    $read = app(SubDocumentReader::class)->read($dir.'/invoice.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'invoice.xlsx');
    expect($read['vendor_name'])->toBe('Smartech Electric')
        ->and($read['items'])->toHaveCount(11)
        ->and($read['items'][0])->toMatchArray(['description' => 'Recessed LED square light', 'quantity' => 78.0, 'amount' => 10140.0]);
    Http::assertSent(fn ($request) => str_contains(json_encode($request->data()), '78 | Recessed LED square light | 10140'));

    app(SubDocumentReader::class)->read($dir.'/invoice.pdf', 'application/pdf', 'invoice.pdf');
    Http::assertSent(function ($request) {
        $parts = data_get($request->data(), 'messages.1.content', []);

        return collect($parts)->contains(fn ($part) => ($part['type'] ?? null) === 'file' && str_starts_with($part['file']['file_data'] ?? '', 'data:application/pdf;base64,'));
    });
});

it('proposes additions and credits at our prices from what the sub lists against what we estimated', function () {
    $w = subDocumentWorld();
    Http::fake(['api.openai.com/*' => openAiJson(smartechAssignments($w['catalog']))]);

    $matched = app(SubDocumentMatcher::class)->match($w['estimate'], smartechDocument());
    $rows = collect($matched['rows'])->keyBy('key');
    $id = fn (string $key) => $w['catalog'][$key]->id;

    expect($matched['trades'])->toBe(['Electrical'])
        // 78 + 9 recessed against 61 + 26 estimated: nothing to do.
        ->and($rows['li-'.$id('recessed')]['kind'])->toBe('same')
        // 25 + 12 GFCI against 25 + 10: two more at OUR $230, not the sub's $135.
        ->and($rows['li-'.$id('gfci')])->toMatchArray(['kind' => 'increase', 'estimated_quantity' => 35.0, 'document_quantity' => 37.0, 'delta' => 2.0, 'quantity' => 2.0, 'cost' => 230.0, 'amount' => 460.0, 'include' => true])
        ->and($rows['li-'.$id('gfci')]['estimate_numbers'])->toBe(['1.3', '1.4'])
        ->and($rows['li-'.$id('smoke')])->toMatchArray(['kind' => 'increase', 'delta' => 2.0, 'amount' => 290.0])
        // 1 + 7 circuits against 10: credit two at $850.
        ->and($rows['li-'.$id('circuit')])->toMatchArray(['kind' => 'decrease', 'delta' => -2.0, 'quantity' => 2.0, 'cost' => 850.0, 'amount' => -1700.0, 'include' => true, 'credit_source_id' => $w['lines'][5]->id])
        // A lump sum matched on both sides: nothing to do, both amounts shown.
        ->and($rows['li-'.$id('eliminate')])->toMatchArray(['kind' => 'same', 'sub_amount' => 3400.0, 'include' => false, 'note' => 'Lump sum on both sides: the sub $3,400.00, our estimate $4,000.00.'])
        // The car charger was never estimated: new, at the catalog's $950.
        ->and($rows['li-'.$id('ev')])->toMatchArray(['kind' => 'new', 'quantity' => 1.0, 'cost' => 950.0, 'amount' => 950.0, 'include' => true])
        // Low-voltage lights matched nothing: review, with the sub's line kept.
        ->and($rows['doc-11'])->toMatchArray(['kind' => 'review', 'line_item_id' => null, 'include' => false, 'document_quantity' => 7.0])
        // Estimated in this trade, not on the invoice: a possible credit, off by default.
        ->and($rows['missing-'.$id('fixture')])->toMatchArray(['kind' => 'missing', 'quantity' => 4.0, 'cost' => 95.0, 'amount' => -380.0, 'include' => false])
        // Tile is another trade: not flagged.
        ->and($rows->has('missing-'.$id('tile')))->toBeFalse()
        ->and(collect($matched['rows'])->first()['kind'])->toBe('increase');

    // The model was shown our catalog ids, our estimate lines and the sub's items.
    Http::assertSent(fn ($request) => str_contains($request->data()['messages'][1]['content'], '['.$id('recessed').'] 4" Recessed')
        && str_contains($request->data()['messages'][1]['content'], '1. Recessed LED square light — qty 78'));
});

it('does not call a trade missing on the strength of a stray match or two', function () {
    $w = subDocumentWorld();
    // A furnace line in the catalog and on the estimate, in another trade.
    $hvac = LineItem::query()->create(['name' => 'Furnace Install', 'category' => 'HVAC', 'sub_category' => 'Rough', 'unit_type' => 'pieces', 'cost' => 1200, 'desc' => 'Install furnace.', 'belongs_to_vendor_id' => $w['gs']->id]);
    EstimateLineItem::query()->create(['estimate_id' => $w['estimate']->id, 'line_item_id' => $hvac->id, 'section_id' => $w['section']->id, 'order' => 20, 'name' => $hvac->name, 'category' => 'HVAC', 'sub_category' => 'Rough', 'unit_type' => 'pieces', 'quantity' => 1, 'cost' => 1200, 'total' => 1200]);
    $makeUpAir = LineItem::query()->create(['name' => 'Make-Up Air', 'category' => 'HVAC', 'sub_category' => 'Rough', 'unit_type' => 'pieces', 'cost' => 1950, 'desc' => 'Install make-up air.', 'belongs_to_vendor_id' => $w['gs']->id]);
    EstimateLineItem::query()->create(['estimate_id' => $w['estimate']->id, 'line_item_id' => $makeUpAir->id, 'section_id' => $w['section']->id, 'order' => 21, 'name' => $makeUpAir->name, 'category' => 'HVAC', 'sub_category' => 'Rough', 'unit_type' => 'pieces', 'quantity' => 1, 'cost' => 1950, 'total' => 1950]);

    // The model matched the electrician's "Power for appliance" line to the furnace and called the invoice Electrical + HVAC.
    $assignments = smartechAssignments($w['catalog']);
    $assignments['trades'] = ['Electrical', 'HVAC'];
    $assignments['assignments'][7]['line_item_id'] = $hvac->id;
    Http::fake(['api.openai.com/*' => openAiJson($assignments)]);

    $matched = app(SubDocumentMatcher::class)->match($w['estimate'], smartechDocument());
    $kinds = collect($matched['rows'])->keyBy('key')->map(fn ($r) => $r['kind']);

    expect($matched['trades'])->toBe(['Electrical'])
        ->and($kinds->has('missing-'.$makeUpAir->id))->toBeFalse()
        ->and($kinds['missing-'.$w['catalog']['fixture']->id])->toBe('missing');
});

it('writes the ticked rows into the change order through the estimate\'s own writers', function () {
    $w = subDocumentWorld();
    Http::fake(['api.openai.com/*' => openAiJson(smartechAssignments($w['catalog']))]);
    $rows = collect(app(SubDocumentMatcher::class)->match($w['estimate'], smartechDocument())['rows'])->keyBy('key');
    $id = fn (string $key) => $w['catalog'][$key]->id;

    $document = EstimateSubDocument::factory()->create(['estimate_id' => $w['estimate']->id, 'project_id' => $w['project']->id, 'belongs_to_vendor_id' => $w['gs']->id, 'sub_vendor_id' => $w['smartech']->id, 'status' => EstimateSubDocument::STATUS_MATCHED, 'document' => smartechDocument()]);

    $ticked = [
        $rows['li-'.$id('smoke')],
        $rows['li-'.$id('circuit')],
        $rows['li-'.$id('ev')],
        ['include' => true] + $rows['missing-'.$id('fixture')],
        $rows['doc-11'], // no catalog item: skipped
    ];

    $result = app(SubDocumentChangeOrderApplier::class)->apply($document, $ticked);

    expect($result['added'])->toHaveCount(2)->and($result['credited'])->toHaveCount(2)->and($result['skipped'])->toBe(['Under cabinets, shelves, niches LED low voltage lights']);

    $changeOrder = EstimateSection::query()->where('estimate_id', $w['estimate']->id)->where('id', '!=', $w['section']->id)->sole();
    expect($changeOrder->name)->toBe('Change Order')->and((int) $changeOrder->bid->type)->toBeGreaterThanOrEqual(2);

    $smoke = EstimateLineItem::find($result['added'][0]);
    expect($smoke)->toMatchArray(['section_id' => $changeOrder->id, 'line_item_id' => $id('smoke'), 'name' => 'Smoke Detector System'])
        ->and((float) $smoke->quantity)->toBe(2.0)->and((float) $smoke->cost)->toBe(145.0)->and((float) $smoke->total)->toBe(290.0)
        // The homeowner reads the catalog text; the sub's invoice is cited internally.
        ->and($smoke->desc)->toBe('Install smoke/CO detectors.')
        ->and($smoke->notes)->toContain('Smartech Electric invoice #0062 (Oct 22, 2026) lists 9, we estimated 7.')
        ->and($smoke->notes)->toContain('Smoke detectors combo');

    $credit = EstimateLineItem::find($result['credited'][0]);
    expect($credit->credit_for_id)->toBe($w['lines'][5]->id)->and((float) $credit->cost)->toBe(-850.0)->and((float) $credit->quantity)->toBe(2.0)->and((float) $credit->total)->toBe(-1700.0)
        ->and($credit->notes)->toContain('lists 8, we estimated 10');

    $ev = EstimateLineItem::find($result['added'][1]);
    expect($ev->line_item_id)->toBe($id('ev'))->and((float) $ev->total)->toBe(950.0)->and($ev->notes)->toContain('Not in our estimate');

    $fixtureCredit = EstimateLineItem::find($result['credited'][1]);
    expect((float) $fixtureCredit->total)->toBe(-380.0)->and($fixtureCredit->notes)->toContain('Not on Smartech Electric invoice #0062');

    // 290 + 950 − 1700 − 380, rolled into the section and its change-order bid.
    expect((float) $changeOrder->fresh()->total)->toBe(-840.0)
        ->and((float) $changeOrder->bid->fresh()->amount)->toBe(-840.0)
        ->and($document->fresh()->status)->toBe(EstimateSubDocument::STATUS_APPLIED)
        ->and($document->fresh()->applied['added'])->toBe($result['added']);
});

it('reads, recognises the sub among the project\'s bidders and matches, in the background', function () {
    $w = subDocumentWorld();
    Storage::fake('local');
    Storage::disk('local')->put('estimate-sub-documents/x/invoice.pdf', '%PDF-1.4 fake');
    $document = EstimateSubDocument::factory()->create(['estimate_id' => $w['estimate']->id, 'project_id' => $w['project']->id, 'belongs_to_vendor_id' => $w['gs']->id, 'file_path' => 'estimate-sub-documents/x/invoice.pdf']);

    Http::fake(['api.openai.com/*' => Http::sequence()->push(['choices' => [['message' => ['content' => json_encode(smartechDocument())]]]])->push(['choices' => [['message' => ['content' => json_encode(smartechAssignments($w['catalog']))]]]])]);

    (new AnalyzeEstimateSubDocument($document->id))->handle(app(SubDocumentReader::class), app(SubDocumentMatcher::class));

    $document->refresh();
    expect($document->status)->toBe(EstimateSubDocument::STATUS_MATCHED)
        ->and($document->sub_vendor_id)->toBe($w['smartech']->id)
        ->and($document->label())->toBe('Smartech Electric invoice #0062 (Oct 22, 2026)')
        ->and(data_get($document->proposals, 'trades'))->toBe(['Electrical'])
        ->and(collect(data_get($document->proposals, 'rows'))->pluck('kind')->countBy()->all())->toMatchArray(['increase' => 2, 'decrease' => 1, 'new' => 1, 'review' => 1, 'missing' => 1]);
});

it('uploads from the estimate page, queues the reading, then applies what the admin ticks', function () {
    $w = subDocumentWorld();
    Storage::fake('local');
    Queue::fake();

    $page = Livewire::test(EstimateSubDocumentCompare::class, ['estimate' => $w['estimate']])
        ->call('openModal')
        ->set('upload', UploadedFile::fake()->create('Smartech 0062.pdf', 120, 'application/pdf'))
        ->call('analyze')
        ->assertHasNoErrors()
        ->assertSet('step', 'analyzing');

    $document = EstimateSubDocument::sole();
    expect($document->estimate_id)->toBe($w['estimate']->id)->and($document->original_name)->toBe('Smartech 0062.pdf')->and($document->status)->toBe(EstimateSubDocument::STATUS_EXTRACTING);
    Storage::disk('local')->assertExists($document->file_path);
    Queue::assertPushed(AnalyzeEstimateSubDocument::class, fn ($job) => $job->estimateSubDocumentId === $document->id);

    // The job finished (simulated) with the proposals; polling picks it up.
    Http::fake(['api.openai.com/*' => openAiJson(smartechAssignments($w['catalog']))]);
    $document->update(['status' => EstimateSubDocument::STATUS_MATCHED, 'sub_vendor_id' => $w['smartech']->id, 'document' => smartechDocument(),
        'proposals' => ['trades' => ['Electrical'], 'rows' => app(SubDocumentMatcher::class)->match($w['estimate'], smartechDocument())['rows']]]);

    $page->call('checkAnalysis')->assertSet('step', 'review')->assertSee('Smartech Electric invoice #0062')->assertSee('bids on file: $56,595.00 (matches)');
    $rows = collect($page->get('rows'));
    $smokeIndex = $rows->search(fn ($r) => $r['key'] === 'li-'.$w['catalog']['smoke']->id);
    $circuitIndex = $rows->search(fn ($r) => $r['key'] === 'li-'.$w['catalog']['circuit']->id);

    // Untick everything but the smoke detectors, and make it three instead of two.
    $page->call('setAll', false)
        ->set("rows.$smokeIndex.include", true)
        ->set("rows.$smokeIndex.quantity", 3)
        ->assertSet("rows.$smokeIndex.amount", 435.0)
        ->set("rows.$circuitIndex.include", true)
        ->call('apply')
        ->assertHasNoErrors()
        ->assertDispatched('refreshComponent')
        ->assertSet('step', 'upload');

    $added = EstimateLineItem::query()->where('estimate_id', $w['estimate']->id)->where('line_item_id', $w['catalog']['smoke']->id)->where('section_id', '!=', $w['section']->id)->sole();

    // The applied view says what happened to each row — and what didn't.
    $outcomes = data_get($document->fresh()->applied, 'rows');
    expect($outcomes)->toHaveKeys(['li-'.$w['catalog']['smoke']->id, 'li-'.$w['catalog']['circuit']->id])
        ->and($outcomes['li-'.$w['catalog']['smoke']->id])->toMatchArray(['outcome' => 'added', 'quantity' => 3.0, 'amount' => 435.0, 'detail' => 'Added 3 @ $145.00'])
        ->and($outcomes['li-'.$w['catalog']['circuit']->id])->toMatchArray(['outcome' => 'credited', 'amount' => -1700.0])
        ->and($outcomes)->not->toHaveKey('li-'.$w['catalog']['ev']->id)
        // The edited quantity (3, not 2) is what the document keeps.
        ->and((float) collect(data_get($document->fresh()->proposals, 'rows'))->firstWhere('key', 'li-'.$w['catalog']['smoke']->id)['quantity'])->toBe(3.0);

    $review = Livewire::test(EstimateSubDocumentCompare::class, ['estimate' => $w['estimate']])->call('openDocument', $document->id);
    $html = $review->html();
    expect(substr_count($html, 'data-row-outcome="not_applied"'))->toBeGreaterThanOrEqual(1)
        ->and(substr_count($html, 'data-row-outcome="added"'))->toBe(1)
        ->and(substr_count($html, 'data-row-outcome="credited"'))->toBe(1);
    $review->assertSee('Added 3 @ $145.00')->assertSee('Not applied')->assertSee('Applied to the change order')->assertSee('left unticked');

    // Applying also remembers the matches that needed nothing: the lump sum, the recessed lights.
    expect(SubItemAlias::query()->where('description_key', 'remove rework existing electrical')->value('line_item_id'))->toBe($w['catalog']['eliminate']->id)
        ->and(SubItemAlias::query()->where('description_key', 'recessed led square light')->value('source'))->toBe(SubItemAlias::SOURCE_CONFIRMED);
    expect((float) $added->quantity)->toBe(3.0)->and((float) $added->total)->toBe(435.0)
        ->and(EstimateLineItem::query()->where('credit_for_id', $w['lines'][5]->id)->count())->toBe(1)
        ->and(EstimateLineItem::query()->where('estimate_id', $w['estimate']->id)->where('line_item_id', $w['catalog']['ev']->id)->exists())->toBeFalse()
        ->and($document->fresh()->status)->toBe(EstimateSubDocument::STATUS_APPLIED);
});

it('turns a review row into an addition once the admin gives it a catalog item and a quantity', function () {
    $w = subDocumentWorld();
    Http::fake(['api.openai.com/*' => openAiJson(smartechAssignments($w['catalog']))]);
    $rows = app(SubDocumentMatcher::class)->match($w['estimate'], smartechDocument())['rows'];
    $document = EstimateSubDocument::factory()->create(['estimate_id' => $w['estimate']->id, 'project_id' => $w['project']->id, 'belongs_to_vendor_id' => $w['gs']->id, 'status' => EstimateSubDocument::STATUS_MATCHED, 'document' => smartechDocument(), 'proposals' => ['trades' => ['Electrical'], 'rows' => $rows]]);

    $page = Livewire::test(EstimateSubDocumentCompare::class, ['estimate' => $w['estimate']])->call('openDocument', $document->id)->assertSet('step', 'review');
    $i = collect($page->get('rows'))->search(fn ($r) => $r['key'] === 'doc-11');

    // The low-voltage lights: the admin says they're our Replace Light Fixture item, seven of them.
    $page->call('setAll', false)
        ->set("rows.$i.line_item_id", $w['catalog']['fixture']->id)
        ->assertSet("rows.$i.kind", 'new')
        ->assertSet("rows.$i.cost", 95.0)
        ->set("rows.$i.quantity", 7)
        ->assertSet("rows.$i.amount", 665.0)
        ->assertSet("rows.$i.include", true)
        ->call('apply')
        ->assertHasNoErrors();

    $added = EstimateLineItem::query()->where('estimate_id', $w['estimate']->id)->where('line_item_id', $w['catalog']['fixture']->id)->where('section_id', '!=', $w['section']->id)->sole();
    expect((float) $added->quantity)->toBe(7.0)->and((float) $added->total)->toBe(665.0)->and($added->notes)->toContain('Under cabinets, shelves, niches LED low voltage lights');
});

it('is for the estimate\'s admins only', function () {
    $w = subDocumentWorld();
    $outsider = User::query()->create(['first_name' => 'Out', 'last_name' => 'Sider', 'email' => 'o'.Str::random(6).'@example.test', 'cell_phone' => '7'.random_int(100000000, 999999999), 'password' => bcrypt('x')]);

    Livewire::actingAs($outsider)->test(EstimateSubDocumentCompare::class, ['estimate' => $w['estimate']])->call('openModal')->assertForbidden();
});

it('reads a sub\'s wording the same however it is quoted or cased', function () {
    expect(SubItemMemory::key("2’’ cans"))->toBe(SubItemMemory::key('2\'\' cans'))
        ->and(SubItemMemory::key('2" cans'))->toBe(SubItemMemory::key("2’’ cans"))
        ->and(SubItemMemory::key('Replace exisWng outlets'))->toBe('replace exiswng outlets')
        ->and(SubItemMemory::key('  Power for   range 240V '))->toBe('power for range 240v');
});

it('remembers a match picked by hand and uses it before asking the model next time', function () {
    $w = subDocumentWorld();
    Http::fake(['api.openai.com/*' => openAiJson(smartechAssignments($w['catalog']))]);
    $rows = app(SubDocumentMatcher::class)->match($w['estimate'], smartechDocument(), $w['smartech']->id)['rows'];
    $document = EstimateSubDocument::factory()->create(['estimate_id' => $w['estimate']->id, 'project_id' => $w['project']->id, 'belongs_to_vendor_id' => $w['gs']->id, 'sub_vendor_id' => $w['smartech']->id, 'status' => EstimateSubDocument::STATUS_MATCHED, 'document' => smartechDocument(), 'proposals' => ['trades' => ['Electrical'], 'rows' => $rows]]);
    EstimateSubDocumentItem::create(['estimate_sub_document_id' => $document->id, 'belongs_to_vendor_id' => $w['gs']->id, 'sub_vendor_id' => $w['smartech']->id, 'index' => 11, 'description' => 'Under cabinets, shelves, niches LED low voltage lights', 'quantity' => 7]);

    $page = Livewire::test(EstimateSubDocumentCompare::class, ['estimate' => $w['estimate']])->call('openDocument', $document->id);
    $i = collect($page->get('rows'))->search(fn ($r) => $r['key'] === 'doc-11');
    $page->set("rows.$i.line_item_id", $w['catalog']['fixture']->id)->assertSet("rows.$i.learned", true);

    $alias = SubItemAlias::sole();
    expect($alias)->toMatchArray(['sub_vendor_id' => $w['smartech']->id, 'line_item_id' => $w['catalog']['fixture']->id, 'source' => SubItemAlias::SOURCE_MANUAL, 'description_key' => 'under cabinets shelves niches led low voltage lights'])
        ->and($document->items()->where('index', 11)->sole())->toMatchArray(['line_item_id' => $w['catalog']['fixture']->id, 'match_source' => 'manual']);

    // Next invoice from Smartech, same wording; the model is unsure again.
    Http::fake(['api.openai.com/*' => openAiJson(smartechAssignments($w['catalog']))]);
    $again = app(SubDocumentMatcher::class)->match($w['estimate'], smartechDocument(), $w['smartech']->id);
    $row = collect($again['rows'])->firstWhere('key', 'li-'.$w['catalog']['fixture']->id);

    // 7 listed against the 4 we estimated: +3, remembered, no review row left.
    expect($row)->toMatchArray(['kind' => 'increase', 'document_quantity' => 7.0, 'delta' => 3.0, 'learned' => true])
        ->and(collect($again['rows'])->firstWhere('key', 'doc-11'))->toBeNull()
        ->and($again['assignments'][11])->toMatchArray(['line_item_id' => $w['catalog']['fixture']->id, 'learned' => true]);
    Http::assertSent(fn ($request) => str_contains($request->data()['messages'][1]['content'], 'Learned matches')
        && str_contains($request->data()['messages'][1]['content'], 'item 11 → catalog '.$w['catalog']['fixture']->id));

    // Another sub does not inherit Smartech's wording, but a general alias would apply to anyone.
    expect(app(SubItemMemory::class)->lookup($w['gs']->id, null, ['Under cabinets, shelves, niches LED low voltage lights']))->toBe([]);
});

it('keeps every line of the document, and confirms the matches that were applied', function () {
    $w = subDocumentWorld();
    Storage::fake('local');
    Storage::disk('local')->put('estimate-sub-documents/x/invoice.pdf', '%PDF-1.4 fake');
    $document = EstimateSubDocument::factory()->create(['estimate_id' => $w['estimate']->id, 'project_id' => $w['project']->id, 'belongs_to_vendor_id' => $w['gs']->id, 'file_path' => 'estimate-sub-documents/x/invoice.pdf']);
    Http::fake(['api.openai.com/*' => Http::sequence()->push(['choices' => [['message' => ['content' => json_encode(smartechDocument())]]]])->push(['choices' => [['message' => ['content' => json_encode(smartechAssignments($w['catalog']))]]]])]);

    (new AnalyzeEstimateSubDocument($document->id))->handle(app(SubDocumentReader::class), app(SubDocumentMatcher::class));

    $items = $document->items()->get();
    expect($items)->toHaveCount(11)
        ->and($items->firstWhere('index', 5))->toMatchArray(['description' => 'Smoke detectors combo', 'quantity' => 3.0, 'unit_price' => 145.0, 'amount' => 435.0, 'line_item_id' => $w['catalog']['smoke']->id, 'match_source' => 'model', 'sub_vendor_id' => $w['smartech']->id, 'applied' => false])
        ->and($items->firstWhere('index', 11)->line_item_id)->toBeNull()
        ->and($items->firstWhere('index', 5)->document_date->toDateString())->toBe('2026-10-22');

    $rows = collect(data_get($document->fresh()->proposals, 'rows'))->keyBy('key');
    app(SubDocumentChangeOrderApplier::class)->apply($document->fresh(), [$rows['li-'.$w['catalog']['smoke']->id]], $w['admin']->id);

    $alias = SubItemAlias::query()->where('description_key', 'smoke detectors combo')->sole();
    expect($alias)->toMatchArray(['sub_vendor_id' => $w['smartech']->id, 'line_item_id' => $w['catalog']['smoke']->id, 'source' => SubItemAlias::SOURCE_CONFIRMED, 'times_used' => 1, 'created_by_user_id' => $w['admin']->id])
        ->and(SubItemAlias::query()->where('description_key', 'smoke detector')->exists())->toBeTrue()
        ->and($document->items()->whereIn('index', [5, 6])->pluck('applied')->all())->toBe([true, true])
        ->and($document->items()->where('index', 1)->value('applied'))->toBeFalse();

    // The sub's page lists the document and its lines.
    Livewire::test(\App\Livewire\Vendors\VendorSubDocuments::class, ['vendor' => $w['smartech']])
        ->assertSee('Smartech Electric invoice #0062')
        ->assertSee('Recessed LED square light')
        ->assertSee('In change order')
        ->assertSee('Smoke Detector System');
});

it('creates a catalog item for an unmatched line, from scratch or from a template, and matches the row to it', function () {
    $w = subDocumentWorld();
    Http::fake(['api.openai.com/*' => openAiJson(smartechAssignments($w['catalog']))]);
    $rows = app(SubDocumentMatcher::class)->match($w['estimate'], smartechDocument(), $w['smartech']->id)['rows'];
    $document = EstimateSubDocument::factory()->create(['estimate_id' => $w['estimate']->id, 'project_id' => $w['project']->id, 'belongs_to_vendor_id' => $w['gs']->id, 'sub_vendor_id' => $w['smartech']->id, 'status' => EstimateSubDocument::STATUS_MATCHED, 'document' => smartechDocument(), 'proposals' => ['trades' => ['Electrical'], 'rows' => $rows]]);

    $page = Livewire::test(EstimateSubDocumentCompare::class, ['estimate' => $w['estimate']])->call('openDocument', $document->id);
    $i = collect($page->get('rows'))->search(fn ($r) => $r['key'] === 'doc-11');

    // Prefilled from the sub's line and the document's trade.
    $page->call('startNewItem', $i)
        ->assertSet('newItemRow', $i)
        ->assertSet('newItem.name', 'Under Cabinets, Shelves, Niches Led Low Voltage Lights')
        ->assertSet('newItem.category', 'Electrical')
        ->assertSet('newItem.unit_type', 'pieces')
        ->assertSee('Sub charges $4,550.00 for 7');

    // A template brings its category, unit, cost and description; the name stays.
    $page->set('newItem.template_id', $w['catalog']['fixture']->id)
        ->assertSet('newItem.sub_category', 'Finish')
        ->assertSet('newItem.cost', '95.00')
        ->assertSet('newItem.desc', 'Install a standard fixture in an existing location.')
        ->assertSet('newItem.name', 'Under Cabinets, Shelves, Niches Led Low Voltage Lights');

    // The catalog form's rules apply.
    $page->set('newItem.name', 'LV')->call('saveNewItem')->assertHasErrors(['newItem.name']);
    expect(LineItem::query()->where('name', 'LV')->exists())->toBeFalse();

    $page->set('newItem.name', 'Low Voltage Cabinet Lighting')
        ->set('newItem.cost', '650')
        ->set('newItem.desc', 'Install low voltage LED lighting under cabinets, in shelves or niches.')
        ->call('saveNewItem')
        ->assertHasNoErrors()
        ->assertSet('newItemRow', null);

    $created = LineItem::query()->where('name', 'Low Voltage Cabinet Lighting')->sole();
    expect($created)->toMatchArray(['belongs_to_vendor_id' => $w['gs']->id, 'category' => 'Electrical', 'sub_category' => 'Finish', 'unit_type' => 'pieces'])
        ->and((float) $created->cost)->toBe(650.0);

    $row = $page->get('rows')[$i];
    expect($row)->toMatchArray(['kind' => 'new', 'line_item_id' => $created->id, 'name' => 'Low Voltage Cabinet Lighting', 'cost' => 650.0, 'quantity' => 7.0, 'amount' => 4550.0, 'include' => true, 'learned' => true])
        ->and(SubItemAlias::query()->where('line_item_id', $created->id)->where('sub_vendor_id', $w['smartech']->id)->exists())->toBeTrue()
        ->and(collect($page->instance()->catalog)->contains('id', $created->id))->toBeTrue();
});

/** The world plus an open change order holding 26 more 4" recessed (the Violet estimate's 12.28). */
function withOpenChangeOrder(array $w): array
{
    $bid = Bid::create(['project_id' => $w['project']->id, 'vendor_id' => $w['gs']->id, 'amount' => 0, 'type' => 2]);
    $section = EstimateSection::query()->create(['estimate_id' => $w['estimate']->id, 'name' => 'Change Order', 'order' => 1, 'total' => 0, 'bid_id' => $bid->id]);
    $item = $w['catalog']['recessed'];
    $line = EstimateLineItem::query()->create(['estimate_id' => $w['estimate']->id, 'line_item_id' => $item->id, 'section_id' => $section->id, 'order' => 0, 'name' => $item->name, 'category' => $item->category, 'sub_category' => $item->sub_category, 'unit_type' => 'pieces', 'quantity' => 26, 'cost' => 240, 'total' => 6240, 'desc' => $item->desc]);
    // The original 61 and the change order's 26 make 87, as on the Violet estimate.
    $w['lines'][1]->delete();

    return $w + ['changeOrder' => $section, 'coLine' => $line];
}

it('adjusts the item\'s line on an open change order instead of crediting beside it', function () {
    $w = withOpenChangeOrder(subDocumentWorld());
    Http::fake(['api.openai.com/*' => openAiJson(smartechAssignments($w['catalog']))]);
    $document = smartechDocument();
    $document['items'][0]['quantity'] = 73.0; // 73 + 9 cans = 82 against 61 + 26

    $rows = collect(app(SubDocumentMatcher::class)->match($w['estimate'], $document)['rows'])->keyBy('key');
    $row = $rows['li-'.$w['catalog']['recessed']->id];

    expect($row)->toMatchArray(['kind' => 'decrease', 'estimated_quantity' => 87.0, 'document_quantity' => 82.0, 'quantity' => 5.0, 'cost' => 240.0, 'amount' => -1200.0])
        ->and($row['adjust'])->toMatchArray(['id' => $w['coLine']->id, 'number' => '2.1', 'quantity' => 26.0, 'cost' => 240.0])
        ->and($row['estimate_numbers'])->toBe(['1.1', '2.1']);

    $sub = EstimateSubDocument::factory()->create(['estimate_id' => $w['estimate']->id, 'project_id' => $w['project']->id, 'belongs_to_vendor_id' => $w['gs']->id, 'sub_vendor_id' => $w['smartech']->id, 'status' => EstimateSubDocument::STATUS_MATCHED, 'document' => $document]);
    $result = app(SubDocumentChangeOrderApplier::class)->apply($sub, [$row]);

    // 12.28 reads 21 now; no credit line was written.
    expect($result['adjusted'])->toBe([$w['coLine']->id])->and($result['credited'])->toBe([])->and($result['added'])->toBe([])
        ->and((float) $w['coLine']->fresh()->quantity)->toBe(21.0)
        ->and((float) $w['coLine']->fresh()->total)->toBe(5040.0)
        ->and($w['coLine']->fresh()->notes)->toContain('Adjusted 26 → 21: Smartech Electric invoice #0062 (Oct 22, 2026) lists 82, we estimated 87.')
        ->and(EstimateLineItem::query()->where('estimate_id', $w['estimate']->id)->whereNotNull('credit_for_id')->exists())->toBeFalse()
        ->and((float) $w['changeOrder']->fresh()->total)->toBe(5040.0)
        ->and((float) $w['changeOrder']->bid->fresh()->amount)->toBe(5040.0);

    // Shown as an adjustment in the modal.
    Livewire::test(EstimateSubDocumentCompare::class, ['estimate' => $w['estimate']])
        ->call('openDocument', EstimateSubDocument::factory()->create(['estimate_id' => $w['estimate']->id, 'belongs_to_vendor_id' => $w['gs']->id, 'status' => EstimateSubDocument::STATUS_MATCHED, 'document' => $document, 'proposals' => ['trades' => ['Electrical'], 'rows' => $rows->values()->all()]])->id)
        ->assertSee('line 2.1: 26 → 21')
        ->assertSee('Adjust');
});

it('bumps the open change-order line for an increase, credits only what it cannot absorb, and leaves it alone when the price changed', function () {
    $w = withOpenChangeOrder(subDocumentWorld());
    Http::fake(['api.openai.com/*' => openAiJson(smartechAssignments($w['catalog']))]);
    $sub = EstimateSubDocument::factory()->create(['estimate_id' => $w['estimate']->id, 'project_id' => $w['project']->id, 'belongs_to_vendor_id' => $w['gs']->id, 'sub_vendor_id' => $w['smartech']->id, 'status' => EstimateSubDocument::STATUS_MATCHED, 'document' => smartechDocument()]);
    $key = 'li-'.$w['catalog']['recessed']->id;

    // 81 + 9 = 90: three more go onto the change-order line.
    $document = smartechDocument();
    $document['items'][0]['quantity'] = 81.0;
    $row = collect(app(SubDocumentMatcher::class)->match($w['estimate'], $document)['rows'])->keyBy('key')[$key];
    expect($row['kind'])->toBe('increase');
    app(SubDocumentChangeOrderApplier::class)->apply($sub, [$row]);
    expect((float) $w['coLine']->fresh()->quantity)->toBe(29.0)->and((float) $w['coLine']->fresh()->total)->toBe(6960.0);

    // 41 + 9 = 50 against 61 + 29: the change-order line is used up and goes; the other 11 are credited against the original.
    $document['items'][0]['quantity'] = 41.0;
    $row = collect(app(SubDocumentMatcher::class)->match($w['estimate'], $document)['rows'])->keyBy('key')[$key];
    expect($row)->toMatchArray(['kind' => 'decrease', 'quantity' => 40.0])->and($row['adjust']['quantity'])->toBe(29.0);
    $result = app(SubDocumentChangeOrderApplier::class)->apply($sub->fresh(), [$row]);
    $credit = EstimateLineItem::find($result['credited'][0]);
    expect(EstimateLineItem::find($w['coLine']->id))->toBeNull()
        ->and(EstimateLineItem::withTrashed()->find($w['coLine']->id)->trashed())->toBeTrue()
        // The credit for the other 11 lands in that same open change order.
        ->and((float) $w['changeOrder']->fresh()->total)->toBe(-2640.0)
        ->and($credit->credit_for_id)->toBe($w['lines'][0]->id)
        ->and((float) $credit->quantity)->toBe(11.0)
        ->and((float) $credit->total)->toBe(-2640.0);

});

it('leaves the open change-order line alone when the unit cost was changed on the row', function () {
    // A changed unit cost can't be folded into an existing line: it goes beside it.
    $w = withOpenChangeOrder(subDocumentWorld());
    Http::fake(['api.openai.com/*' => openAiJson(smartechAssignments($w['catalog']))]);
    $sub = EstimateSubDocument::factory()->create(['estimate_id' => $w['estimate']->id, 'project_id' => $w['project']->id, 'belongs_to_vendor_id' => $w['gs']->id, 'status' => EstimateSubDocument::STATUS_MATCHED, 'document' => smartechDocument()]);
    $document = smartechDocument();
    $document['items'][0]['quantity'] = 81.0;
    $row = collect(app(SubDocumentMatcher::class)->match($w['estimate'], $document)['rows'])->keyBy('key')['li-'.$w['catalog']['recessed']->id];
    $row['cost'] = 260.0;

    $result = app(SubDocumentChangeOrderApplier::class)->apply($sub, [$row]);

    expect($result['adjusted'])->toBe([])->and($result['added'])->toHaveCount(1)
        ->and((float) $w['coLine']->fresh()->quantity)->toBe(26.0)
        ->and((float) EstimateLineItem::find($result['added'][0])->cost)->toBe(260.0)
        ->and((float) EstimateLineItem::find($result['added'][0])->quantity)->toBe(3.0);
});

it('rebuilds the per-row record for a document applied before it was kept', function () {
    $w = withOpenChangeOrder(subDocumentWorld());
    Http::fake(['api.openai.com/*' => openAiJson(smartechAssignments($w['catalog']))]);
    $document = smartechDocument();
    $document['items'][0]['quantity'] = 73.0; // recessed 82 vs 87: adjust 12.28 by -5
    $rows = app(SubDocumentMatcher::class)->match($w['estimate'], $document)['rows'];
    $byKey = collect($rows)->keyBy('key');
    $sub = EstimateSubDocument::factory()->create(['estimate_id' => $w['estimate']->id, 'project_id' => $w['project']->id, 'belongs_to_vendor_id' => $w['gs']->id, 'sub_vendor_id' => $w['smartech']->id, 'status' => EstimateSubDocument::STATUS_MATCHED, 'document' => $document, 'proposals' => ['trades' => ['Electrical'], 'rows' => $rows]]);

    // Applied the way the first version did: ids kept, nothing per row.
    $result = app(SubDocumentChangeOrderApplier::class)->apply($sub, [$byKey['li-'.$w['catalog']['smoke']->id], $byKey['li-'.$w['catalog']['recessed']->id], $byKey['li-'.$w['catalog']['circuit']->id]]);
    $sub->update(['applied' => ['added' => $result['added'], 'adjusted' => $result['adjusted'], 'credited' => $result['credited'], 'skipped' => []]]);

    $outcomes = app(SubDocumentChangeOrderApplier::class)->outcomes($sub->fresh());

    expect($outcomes['li-'.$w['catalog']['smoke']->id])->toMatchArray(['outcome' => 'added', 'quantity' => 2.0, 'amount' => 290.0, 'detail' => 'Added 2 @ $145.00'])
        ->and($outcomes['li-'.$w['catalog']['recessed']->id])->toMatchArray(['outcome' => 'adjusted', 'quantity' => 5.0, 'amount' => -1200.0, 'detail' => 'Line 2.1 adjusted 26 → 21'])
        ->and($outcomes['li-'.$w['catalog']['circuit']->id])->toMatchArray(['outcome' => 'credited', 'quantity' => 2.0, 'amount' => -1700.0])
        ->and($outcomes)->not->toHaveKey('li-'.$w['catalog']['ev']->id)
        // Saved, so it is not rebuilt again.
        ->and(data_get($sub->fresh()->applied, 'rows'))->toEqual($outcomes);

    Livewire::test(EstimateSubDocumentCompare::class, ['estimate' => $w['estimate']])
        ->call('openDocument', $sub->id)
        ->assertSee('Line 2.1 adjusted 26 → 21')
        ->assertSee('Added 2 @ $145.00')
        ->assertSee('Not applied')
        ->assertSeeHtml('data-row-outcome="not_applied"');
});
