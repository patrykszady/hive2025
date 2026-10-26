<?php

use App\Jobs\ReadSupplierQuote;
use App\Livewire\Estimates\EstimateShow;
use App\Livewire\Estimates\EstimateSubDocumentCompare;
use App\Livewire\Estimates\EstimateSupplierQuote;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateLineItem;
use App\Models\EstimateSection;
use App\Models\EstimateSubDocument;
use App\Models\LineItem;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Estimates\SubVendorRecogniser;
use App\Services\Estimates\SupplierQuoteReader;
use App\Services\Estimates\SupplierQuoteSectionWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * "Add Supplier Quote" (2026-10-26): a window and door supplier's quote is
 * read into a schedule, every unit drawn, and added to the estimate as one
 * section at the supplier's LIST price plus our markup, the supplier's tax
 * folded into each line — built on Advanced Window Corp's estimate 10316499
 * (36% off, never shown) and Climate Solutions' order 894357 (15% markup).
 */

beforeEach(function () {
    config(['services.openai.api_key' => 'test-key', 'services.openai.sub_document_model' => 'gpt-4.1']);
});

/** GS, its admin, a project with an unsigned estimate and the two suppliers in the directory. */
function supplierQuoteWorld(): array
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

    $install = LineItem::query()->create(['name' => 'Window Install', 'category' => 'Windows', 'sub_category' => 'Labor', 'unit_type' => 'pieces', 'cost' => 650, 'desc' => 'Install a window.', 'belongs_to_vendor_id' => $gs->id]);

    $advanced = Vendor::query()->create(['business_name' => 'Advanced Window Corp.', 'business_type' => 'Supplier', 'address' => '4040 W Lake St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60624']);
    $climate = Vendor::query()->create(['business_name' => 'Climate Solutions Windows & Doors', 'business_type' => 'Supplier', 'address' => '1 Window Way', 'city' => 'Elk Grove Village', 'state' => 'IL', 'zip_code' => '60007']);

    return compact('gs', 'admin', 'project', 'estimate', 'section', 'install', 'advanced', 'climate');
}

/** Advanced Window Corp. estimate 10316499 as the reader returns it, in miniature: list $2,775.18, 36% off, 10.5% tax on the discounted price. */
function advancedWindowQuote(): array
{
    return [
        'vendor_name' => 'Advanced Window Corp.', 'document_type' => 'estimate', 'document_number' => '10316499', 'document_date' => '2026-10-20', 'customer_po' => 'GS Construction - Violet',
        'subtotal' => 2775.18, 'discount_percent' => 36.0, 'discount_amount' => 999.06, 'tax_rate' => 10.5, 'tax_amount' => 186.49, 'total' => 1962.61,
        'items' => [
            ['index' => 1, 'product_code' => 'PW63', 'name' => 'Picture window', 'quantity' => 1.0, 'width_in' => 24.0, 'height_in' => 48.0, 'color_exterior' => 'White', 'color_interior' => 'White', 'glass' => 'Low-E, argon', 'screen' => null, 'grid' => null,
                'opening' => ['type' => 'picture', 'hinge' => null, 'tilt' => false, 'active_panel' => null, 'slide_direction' => null, 'panels' => []], 'unit_price' => 264.59, 'amount' => 264.59, 'notes' => null],
            ['index' => 2, 'product_code' => 'SD800', 'name' => 'Sliding patio door', 'quantity' => 1.0, 'width_in' => 59.25, 'height_in' => 79.375, 'color_exterior' => 'White', 'color_interior' => 'White', 'glass' => 'Low-E, argon, tempered', 'screen' => 'Full screen', 'grid' => null,
                'opening' => ['type' => 'patio_door', 'hinge' => null, 'tilt' => false, 'active_panel' => 'right', 'slide_direction' => 'left', 'panels' => []], 'unit_price' => 2510.59, 'amount' => 2510.59, 'notes' => null],
        ],
    ];
}

/** Climate Solutions order 894357 in miniature: $6,423.54 at list, $658.41 tax, no discount. */
function climateSolutionsOrder(): array
{
    return [
        'vendor_name' => 'Climate Solutions', 'document_type' => 'acknowledgement', 'document_number' => '894357', 'document_date' => '2026-10-15', 'customer_po' => null,
        'subtotal' => 6423.54, 'discount_percent' => null, 'discount_amount' => null, 'tax_rate' => 10.25, 'tax_amount' => 658.41, 'total' => 7081.95,
        'items' => [
            ['index' => 1, 'product_code' => '6000 TT French In Door', 'name' => 'Tilt and turn french door', 'quantity' => 1.0, 'width_in' => 80.0, 'height_in' => 84.0, 'color_exterior' => 'Black', 'color_interior' => 'White', 'glass' => 'Triple pane, Low-E', 'screen' => null, 'grid' => null,
                'opening' => ['type' => 'tilt_turn', 'hinge' => null, 'tilt' => false, 'active_panel' => null, 'slide_direction' => null, 'panels' => [['hinge' => 'left', 'tilt' => false], ['hinge' => 'right', 'tilt' => true]]], 'unit_price' => 3595.74, 'amount' => 3595.74, 'notes' => null],
            ['index' => 2, 'product_code' => '8100 2-Lite Slider', 'name' => 'Two-lite slider', 'quantity' => 1.0, 'width_in' => 59.25, 'height_in' => 47.5, 'color_exterior' => 'Black', 'color_interior' => 'White', 'glass' => 'Double pane, Low-E', 'screen' => 'Half screen', 'grid' => null,
                'opening' => ['type' => 'slider', 'hinge' => null, 'tilt' => false, 'active_panel' => 'right', 'slide_direction' => 'left', 'panels' => []], 'unit_price' => 1900.0, 'amount' => 1900.0, 'notes' => null],
            ['index' => 3, 'product_code' => '8100 Casement', 'name' => 'Casement', 'quantity' => 1.0, 'width_in' => 30.75, 'height_in' => 36.125, 'color_exterior' => 'Black', 'color_interior' => 'White', 'glass' => 'Double pane, Low-E', 'screen' => 'Full screen', 'grid' => null,
                'opening' => ['type' => 'casement', 'hinge' => 'right', 'tilt' => false, 'active_panel' => null, 'slide_direction' => null, 'panels' => []], 'unit_price' => 479.14, 'amount' => 479.14, 'notes' => 'Egress not met'],
            ['index' => 4, 'product_code' => '8100 Hopper', 'name' => 'Hopper', 'quantity' => 1.0, 'width_in' => 30.75, 'height_in' => 20.0, 'color_exterior' => 'Black', 'color_interior' => 'White', 'glass' => 'Double pane, Low-E', 'screen' => 'Full screen', 'grid' => null,
                'opening' => ['type' => 'hopper', 'hinge' => 'bottom', 'tilt' => false, 'active_panel' => null, 'slide_direction' => null, 'panels' => []], 'unit_price' => 448.66, 'amount' => 448.66, 'notes' => null],
        ],
    ];
}

function quoteModelJson(array $payload): \GuzzleHttp\Promise\PromiseInterface
{
    return Http::response(['choices' => [['message' => ['content' => json_encode($payload)]]]]);
}

/** A read quote on the estimate, as ReadSupplierQuote leaves it. */
function readQuoteDocument(array $w, array $read, ?Vendor $supplier, string $status = EstimateSubDocument::STATUS_MATCHED): EstimateSubDocument
{
    return EstimateSubDocument::factory()->create([
        'estimate_id' => $w['estimate']->id, 'project_id' => $w['project']->id, 'belongs_to_vendor_id' => $w['gs']->id, 'uploaded_by_user_id' => $w['admin']->id,
        'sub_vendor_id' => $supplier?->id, 'status' => $status, 'kind' => EstimateSubDocument::KIND_QUOTE, 'document' => $read + ['model' => 'gpt-4.1'],
        'original_name' => 'quote.pdf',
    ]);
}

it('reads a supplier quote into a window schedule at list prices, with how every unit opens', function () {
    Storage::fake('local');
    Storage::disk('local')->put('quotes/awc.pdf', '%PDF-1.4 test');
    Http::fake(['api.openai.com/*' => quoteModelJson(array_merge(advancedWindowQuote(), ['items' => array_map(fn ($i) => $i + ['width_in' => (string) $i['width_in']], advancedWindowQuote()['items'])]))]);

    $read = (new SupplierQuoteReader)->readQuote(Storage::disk('local')->path('quotes/awc.pdf'), 'application/pdf', 'awc.pdf');

    expect($read['vendor_name'])->toBe('Advanced Window Corp.')
        ->and($read['document_type'])->toBe('estimate')
        ->and($read['document_number'])->toBe('10316499')
        ->and($read['discount_percent'])->toBe(36.0)
        ->and($read['tax_amount'])->toBe(186.49)
        ->and($read['items'])->toHaveCount(2)
        ->and($read['items'][0]['width_in'])->toBe(24.0)
        ->and($read['items'][0]['opening']['type'])->toBe('picture')
        ->and($read['items'][1]['opening'])->toMatchArray(['type' => 'patio_door', 'active_panel' => 'right', 'slide_direction' => 'left'])
        ->and($read['items'][1]['unit_price'])->toBe(2510.59, 'the LIST price, before the 36%');

    Http::assertSent(fn ($request) => data_get($request->data(), 'response_format.json_schema.name') === 'supplier_quote'
        && str_contains(data_get($request->data(), 'messages.0.content'), 'LIST unit price'));
});

it('reads the quote in the background, recognises the supplier in the directory and lists every unit', function () {
    $w = supplierQuoteWorld();
    Storage::fake('local');
    Storage::disk('local')->put('estimate-sub-documents/'.$w['estimate']->id.'/q.pdf', '%PDF-1.4 test');
    Http::fake(['api.openai.com/*' => quoteModelJson(advancedWindowQuote())]);

    $document = EstimateSubDocument::factory()->create(['estimate_id' => $w['estimate']->id, 'project_id' => $w['project']->id, 'belongs_to_vendor_id' => $w['gs']->id, 'uploaded_by_user_id' => $w['admin']->id,
        'kind' => EstimateSubDocument::KIND_QUOTE, 'file_path' => 'estimate-sub-documents/'.$w['estimate']->id.'/q.pdf']);

    (new ReadSupplierQuote($document->id))->handle(new SupplierQuoteReader, new SubVendorRecogniser);

    $document->refresh();
    expect($document->status)->toBe(EstimateSubDocument::STATUS_MATCHED)
        ->and($document->sub_vendor_id)->toBe($w['advanced']->id)
        ->and(data_get($document->document, 'tax_amount'))->toBe(186.49)
        ->and($document->items()->count())->toBe(2)
        ->and($document->items()->first()->description)->toBe('Picture Window 24 × 48')
        ->and((float) $document->items()->first()->unit_price)->toBe(264.59)
        ->and($document->label())->toBe('Advanced Window estimate #10316499 (Oct 20, 2026)', 'the vendor by its short name');
});

it('turns a casement read wider than tall upright, at read time and for quotes read before the rule', function () {
    $wide = ['index' => 1, 'product_code' => 'C51', 'name' => 'Casement', 'quantity' => 1.0, 'width_in' => 51.5, 'height_in' => 18.0, 'color_exterior' => null, 'color_interior' => null, 'glass' => null, 'screen' => null, 'grid' => null,
        'opening' => ['type' => 'casement', 'hinge' => null, 'tilt' => false, 'active_panel' => null, 'slide_direction' => null, 'panels' => []], 'unit_price' => 276.04, 'amount' => 276.04, 'notes' => null];

    Storage::fake('local');
    Storage::disk('local')->put('quotes/awc.pdf', '%PDF-1.4 test');
    Http::fake(['api.openai.com/*' => quoteModelJson(array_merge(advancedWindowQuote(), ['items' => [$wide]]))]);
    $read = (new SupplierQuoteReader)->readQuote(Storage::disk('local')->path('quotes/awc.pdf'), 'application/pdf', 'awc.pdf');
    expect($read['items'][0])->toMatchArray(['width_in' => 18.0, 'height_in' => 51.5]);

    // An item stored before the rule is used upright too: name, description and drawing.
    expect(SupplierQuoteSectionWriter::lineName($wide))->toBe('Casement Window 18 × 51½')
        ->and(SupplierQuoteSectionWriter::description($wide))->toBe('18 × 51½ in. Casement.')
        ->and(SupplierQuoteSectionWriter::spec($wide))->toMatchArray(['type' => 'casement', 'width_in' => 18.0, 'height_in' => 51.5]);

    // A picture window that wide stays as read: only casements stand up.
    $picture = array_merge($wide, ['opening' => array_merge($wide['opening'], ['type' => 'picture'])]);
    expect(SupplierQuoteSectionWriter::lineName($picture))->toBe('Picture Window 51½ × 18');
});

it('fails plainly when the model finds no units', function () {
    $w = supplierQuoteWorld();
    Storage::fake('local');
    Storage::disk('local')->put('estimate-sub-documents/x/q.pdf', '%PDF-1.4 test');
    Http::fake(['api.openai.com/*' => quoteModelJson(array_merge(advancedWindowQuote(), ['items' => []]))]);
    $document = EstimateSubDocument::factory()->create(['estimate_id' => $w['estimate']->id, 'project_id' => $w['project']->id, 'belongs_to_vendor_id' => $w['gs']->id, 'uploaded_by_user_id' => $w['admin']->id, 'kind' => EstimateSubDocument::KIND_QUOTE, 'file_path' => 'estimate-sub-documents/x/q.pdf']);

    (new ReadSupplierQuote($document->id))->handle(new SupplierQuoteReader, new SubVendorRecogniser);

    expect($document->refresh()->status)->toBe(EstimateSubDocument::STATUS_FAILED)
        ->and($document->failure_reason)->toContain('No window or door lines');
});

it('adds the quote as a section at list price plus markup, the tax folded into each line, a drawing on every line and no trace of the supplier', function () {
    $w = supplierQuoteWorld();
    $document = readQuoteDocument($w, climateSolutionsOrder(), $w['climate']);

    $section = app(SupplierQuoteSectionWriter::class)->write($document, 15.0, null);

    expect($section->name)->toBe('Windows & Doors')
        ->and($section->estimate_id)->toBe($w['estimate']->id)
        ->and((float) $section->total)->toEqualWithDelta(8045.48, 0.001, '6,423.54 list + 963.53 markup + 658.41 tax');

    $lines = $section->estimate_line_items()->orderBy('order')->get();
    expect($lines)->toHaveCount(4)
        ->and($lines[0]->name)->toBe('Tilt & Turn Door 80 × 84')
        ->and((float) $lines[0]->cost)->toBe(4503.66, '3,595.74 + 539.36 markup + 368.56 of the tax')
        ->and((float) $lines[0]->total)->toBe(4503.66)
        ->and($lines[0]->window_spec)->toMatchArray(['type' => 'tilt_turn', 'width_in' => 80.0, 'height_in' => 84.0])
        ->and($lines[0]->window_spec['panels'][1])->toMatchArray(['hinge' => 'right', 'tilt' => true])
        ->and($lines[0]->desc)->toBe('80 × 84 in. Tilt & turn door: left panel hinged left; right panel hinged right, tilts in. Exterior Black, interior White. Glass: Triple pane, Low-E.')
        ->and($lines[1]->name)->toBe('Sliding Window 59¼ × 47½')
        ->and((float) $lines[1]->cost)->toBe(2379.75, '1,900 + 285 markup + 194.75 of the tax')
        ->and($lines[2]->name)->toBe('Casement Window 30¾ × 36⅛')
        ->and($lines[2]->window_spec['hinge'])->toBe('right')
        ->and($lines[2]->notes)->toBe('Egress not met')
        ->and($lines[3]->name)->toBe('Hopper Window 30¾ × 20')
        ->and((float) $lines[3]->cost)->toBe(561.95, 'the tax remainder lands on the last line')
        ->and($lines->every(fn ($line) => $line->unit_type === 'pieces' && $line->category === 'Windows' && $line->sub_category === 'Material'))->toBeTrue();

    $text = $lines->map(fn ($l) => $l->name.' '.$l->desc.' '.$l->notes)->implode(' ');
    expect(strtolower($text))->not->toContain('climate')->not->toContain('894357')->not->toContain('discount')->not->toContain('tax');

    // The catalog grew one item per opening type, owned by GS, and reuses them on the next quote.
    $catalog = LineItem::query()->where('belongs_to_vendor_id', $w['gs']->id)->where('category', 'Windows')->where('sub_category', 'Material')->pluck('name')->sort()->values()->all();
    expect($catalog)->toBe(['Casement Window', 'Hopper Window', 'Sliding Window', 'Tilt & Turn Door']);
    app(SupplierQuoteSectionWriter::class)->write(readQuoteDocument($w, climateSolutionsOrder(), $w['climate']), 15.0, 'Second floor windows');
    expect(LineItem::query()->where('belongs_to_vendor_id', $w['gs']->id)->where('category', 'Windows')->count())->toBe(5, 'Window Install plus the four');

    $document->refresh();
    expect($document->status)->toBe(EstimateSubDocument::STATUS_APPLIED)
        ->and($document->estimate_section_id)->toBe($section->id)
        ->and((float) $document->markup_percent)->toBe(15.0)
        ->and(data_get($document->applied, 'totals.client_total'))->toBe(8045.48)
        ->and(data_get($document->applied, 'lines.0.estimate_line_item_id'))->toBe($lines[0]->id)
        ->and(SupplierQuoteSectionWriter::rememberedMarkup($w['climate']->id))->toBe(15.0, 'the markup is remembered for Climate Solutions');
});

it('prices advanced window at list with no markup: the 36% stays ours', function () {
    $w = supplierQuoteWorld();
    $section = app(SupplierQuoteSectionWriter::class)->write(readQuoteDocument($w, advancedWindowQuote(), $w['advanced']), 0.0, null);

    $lines = $section->estimate_line_items()->orderBy('order')->get();
    expect((float) $section->total)->toEqualWithDelta(2961.67, 0.001, '2,775.18 list + 186.49 tax; the discount is never shown')
        ->and($lines[0]->name)->toBe('Picture Window 24 × 48')
        ->and((float) $lines[0]->cost)->toBe(282.37)
        ->and($lines[1]->name)->toBe('Sliding Patio Door 59¼ × 79⅜')
        ->and($lines[1]->desc)->toContain('Sliding patio door, right panel slides left')
        ->and((float) $lines[1]->cost)->toBe(2679.30);
});

it('uploads, reads, previews each unit with its drawing and price, and adds the section from the modal', function () {
    $w = supplierQuoteWorld();
    Storage::fake('local');
    Queue::fake();

    $page = Livewire::test(EstimateSupplierQuote::class, ['estimate' => $w['estimate']])
        ->call('openModal')
        ->set('upload', UploadedFile::fake()->create('Climate 894357.pdf', 120, 'application/pdf'))
        ->call('read')
        ->assertHasNoErrors()
        ->assertSet('step', 'reading');

    $document = EstimateSubDocument::query()->where('estimate_id', $w['estimate']->id)->firstOrFail();
    expect($document->kind)->toBe(EstimateSubDocument::KIND_QUOTE)->and($document->status)->toBe(EstimateSubDocument::STATUS_EXTRACTING);
    Queue::assertPushed(ReadSupplierQuote::class, fn ($job) => $job->estimateSubDocumentId === $document->id);
    Storage::disk('local')->assertExists($document->file_path);

    Http::fake(['api.openai.com/*' => quoteModelJson(climateSolutionsOrder())]);
    (new ReadSupplierQuote($document->id))->handle(new SupplierQuoteReader, new SubVendorRecogniser);

    $page->call('checkReading')
        ->assertSet('step', 'review')
        ->assertSet('supplierVendorId', $w['climate']->id)
        ->assertSet('markupPercent', '0')
        ->assertSee('Tilt & Turn Door 80 × 84')
        ->assertSee('Casement Window 30¾ × 36⅛')
        ->assertSeeHtml('data-window-diagram="tilt_turn"')
        ->assertSeeHtml('data-window-diagram="hopper"')
        ->assertSee('$6,423.54')
        ->assertSee('$7,081.95');

    $page->set('markupPercent', '15')
        ->assertSee('$8,045.48')
        ->assertSee('$4,503.66')
        ->set('sectionName', 'Windows & Doors')
        ->call('addToEstimate')
        ->assertHasNoErrors()
        ->assertSet('step', 'upload')
        ->assertSee('Added to the estimate — 4 lines');

    $section = EstimateSection::query()->where('estimate_id', $w['estimate']->id)->where('name', 'Windows & Doors')->firstOrFail();
    expect($section->estimate_line_items()->count())->toBe(4)
        ->and((float) $section->total)->toEqualWithDelta(8045.48, 0.001);

    // Reopened later, the quote shows exactly what was written, at the markup used.
    Livewire::test(EstimateSupplierQuote::class, ['estimate' => $w['estimate']])->call('openDocument', $document->id)
        ->assertSet('step', 'review')->assertSet('markupPercent', '15')->assertSee('Added on')->assertSee('$8,045.48')->assertDontSee('Add to the estimate');
});

it('brings up the markup remembered for the supplier', function () {
    $w = supplierQuoteWorld();
    SupplierQuoteSectionWriter::rememberMarkup($w['climate']->id, 12.5);
    $document = readQuoteDocument($w, climateSolutionsOrder(), null);

    Livewire::test(EstimateSupplierQuote::class, ['estimate' => $w['estimate']])
        ->call('openDocument', $document->id)
        ->assertSet('markupPercent', '0')
        ->set('supplierVendorId', $w['climate']->id)
        ->assertSet('markupPercent', '12.5');

    expect($document->refresh()->sub_vendor_id)->toBe($w['climate']->id);
});

it('keeps supplier quotes out of the sub invoice comparisons and vice versa', function () {
    $w = supplierQuoteWorld();
    readQuoteDocument($w, climateSolutionsOrder(), $w['climate']);
    EstimateSubDocument::factory()->create(['estimate_id' => $w['estimate']->id, 'project_id' => $w['project']->id, 'belongs_to_vendor_id' => $w['gs']->id, 'uploaded_by_user_id' => $w['admin']->id, 'status' => EstimateSubDocument::STATUS_MATCHED, 'original_name' => 'sub invoice.pdf', 'document' => ['vendor_name' => 'Smartech', 'document_type' => 'invoice', 'items' => []]]);

    Livewire::test(EstimateSupplierQuote::class, ['estimate' => $w['estimate']])->call('openModal')->assertSee('Climate Solutions')->assertDontSee('Smartech');
    Livewire::test(EstimateSubDocumentCompare::class, ['estimate' => $w['estimate']])->call('openModal')->assertSee('Smartech')->assertDontSee('Climate Solutions');
});

it('draws the window beside the line on the estimate page', function () {
    $w = supplierQuoteWorld();
    app(SupplierQuoteSectionWriter::class)->write(readQuoteDocument($w, advancedWindowQuote(), $w['advanced']), 0.0, null);

    Livewire::test(EstimateShow::class, ['estimate' => $w['estimate']->fresh()])
        ->assertSee('Picture Window 24 × 48')
        ->assertSeeHtml('data-window-diagram="picture"')
        ->assertSeeHtml('data-window-diagram="patio_door"')
        ->assertSee('Add Supplier Quote');
});

it('renders the drawing component only for a line that has a spec', function () {
    expect(Blade::render('<x-window-diagram :spec="$spec" :width="40" />', ['spec' => ['type' => 'casement', 'width_in' => 30, 'height_in' => 40, 'hinge' => 'left']]))
        ->toContain('data-window-diagram="casement"')->toContain('<svg')->toContain('width="40"')
        ->and(trim(Blade::render('<x-window-diagram :spec="$spec" />', ['spec' => null])))->toBe('');
});

it('forbids anyone who cannot edit the estimate', function () {
    $w = supplierQuoteWorld();
    $outsider = User::query()->create(['first_name' => 'Out', 'last_name' => 'Sider', 'email' => 'o'.Str::random(6).'@example.test', 'cell_phone' => '7'.random_int(100000000, 999999999), 'password' => bcrypt('x'), 'registration' => ['registered' => true]]);

    Livewire::actingAs($outsider)->test(EstimateSupplierQuote::class, ['estimate' => $w['estimate']])->call('openModal')->assertForbidden();
});
