<?php

use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateLineItem;
use App\Models\EstimateSection;
use App\Models\LineItem;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use App\Services\EstimateAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/estimate-ai-fixtures.php';

it('sends Claude a redacted enquiry, the offered catalog as an id enum, and the company\'s own past section as the example', function () {
    $fx = claudeEstimateFixture();
    $service = new FakeClaudeEstimateService();

    $service->generateEstimate(
        "Hall bath rip and replace for Debby Hill, 1463 W Winnetka St, Palatine IL 60067, call (224) 532-1090 or debbyhill1463@gmail.com. New tile floor and shower.",
        null,
        $fx['vendor']->id,
    );

    expect($service->sentRequests)->toHaveCount(1);
    $req = $service->sentRequests[0];

    expect($req['model'])->toBe('claude-opus-5')
        ->and($req['thinking'])->toBe(['type' => 'adaptive'])
        ->and($req['fallbacks'])->toBe('default')
        ->and($req['betas'])->toBe([EstimateAIService::FALLBACK_BETA])
        ->and($req['outputConfig']['effort'])->toBe('high')
        ->and($req['outputConfig']['format']['type'])->toBe('json_schema');

    $userText = $req['messages'][0]['content'];
    // Contact details never leave the building.
    expect($userText)->not->toContain('1463 W Winnetka')
        ->and($userText)->not->toContain('60067')
        ->and($userText)->not->toContain('532-1090')
        ->and($userText)->not->toContain('debbyhill1463')
        ->and($userText)->toContain('[address]')->toContain('[phone]')->toContain('[email]')
        // ...while the scope survives.
        ->and($userText)->toContain('rip and replace')->toContain('New tile floor and shower');

    // The whole catalog is offered — the model, not a keyword list, decides what the job
    // needs — and the schema's enum is exactly what was offered.
    $enum = $req['outputConfig']['format']['schema']['properties']['line_items']['items']['properties']['line_item_id']['enum'];
    expect($enum)->toContain($fx['catalog']['Floor Tile']->id)
        ->and($enum)->toContain($fx['catalog']['Shower Rough Plumbing']->id)
        ->and($enum)->toContain($fx['catalog']['Roof Shingles']->id)
        ->and(count($enum))->toBe(count($fx['catalog']))
        ->and($userText)->toContain('Roof Shingles');

    // The example is the real past Hall Bath, not a hard-coded one.
    expect($userText)->toContain('RECENT SECTIONS')->toContain('Hall Bath')->toContain('Floor Tile × 45 sq.ft.');

    // No cache marker: the system prompt is under Opus 5's 512-token minimum, a marker would only mislead.
    expect($req['system'][0])->not->toHaveKey('cacheControl');
});

it('returns catalog-priced items from the draft, drops anything outside the offered catalog, and pins lump sums to 1', function () {
    $fx = claudeEstimateFixture();
    $c = $fx['catalog'];
    $service = new FakeClaudeEstimateService([
        'text' => json_encode(['reasoning' => 'Typical hall bath.', 'line_items' => [
            ['line_item_id' => $c['Demo Bathroom']->id, 'quantity' => 3, 'desc' => '', 'notes' => ''],
            ['line_item_id' => $c['Floor Tile']->id, 'quantity' => 48.5, 'desc' => 'Porcelain 12x24 on the floor', 'notes' => 'confirm sq ft'],
            ['line_item_id' => $c['Roof Shingles']->id, 'quantity' => 100, 'desc' => '', 'notes' => ''],
            ['line_item_id' => 999999, 'quantity' => 1, 'desc' => '', 'notes' => ''],
        ]]),
        'stop_reason' => 'end_turn',
    ]);

    $result = $service->generateEstimate('Hall bath: new tile floor.', null, $fx['vendor']->id);

    expect($result['success'])->toBeTrue()
        ->and($result['reasoning'])->toBe('Typical hall bath.')
        // Roofing is in the catalog, so it stays; 999999 is nobody's item.
        ->and(collect($result['line_items'])->pluck('name')->all())->toBe(['Demo Bathroom', 'Floor Tile', 'Roof Shingles'])
        // Lump sum → quantity 1 whatever the model said.
        ->and($result['line_items'][0]['quantity'])->toBe(1.0)
        ->and($result['line_items'][0]['cost'])->toBe(850.0)
        ->and($result['line_items'][1]['quantity'])->toBe(48.5)
        ->and($result['line_items'][1]['cost'])->toBe(23.85)
        // The catalog's text, never the model's rewording.
        ->and($result['line_items'][1]['desc'])->toBe('Catalog description of Floor Tile')
        ->and($result['line_items'][1]['notes'])->toBe($c['Floor Tile']->notes);
});

it('turns a refusal or a cut-off draft into a plain message, never an empty apply', function () {
    $fx = claudeEstimateFixture();

    $refused = (new FakeClaudeEstimateService(['text' => '', 'stop_reason' => 'refusal']))->generateEstimate('Hall bath remodel', null, $fx['vendor']->id);
    expect($refused['success'])->toBeFalse()->and($refused['error'])->toContain('declined')->and($refused['line_items'])->toBe([]);

    $cut = (new FakeClaudeEstimateService(['text' => '{"reasoning":"', 'stop_reason' => 'max_tokens']))->generateEstimate('Hall bath remodel', null, $fx['vendor']->id);
    expect($cut['success'])->toBeFalse()->and($cut['error'])->toContain('cut off');
});

it('explains a missing API key instead of throwing at the estimator', function () {
    $fx = claudeEstimateFixture();
    config(['services.anthropic.api_key' => null]);

    $result = (new EstimateAIService())->generateEstimate('Hall bath remodel', null, $fx['vendor']->id);

    expect($result['success'])->toBeFalse()->and($result['error'])->toContain('ANTHROPIC_API_KEY');
});

it('redacts the details that identify a client and nothing else', function () {
    $redacted = EstimateAIService::redact("Kohler K-728 valve for the Karmazins at 912 W Oxford Ct, Palatine, IL 60067. Reach Doug at 847-343-8944 / doug.k@example.com. Budget $18,500, 6\" recessed lights x8.");

    expect($redacted)->not->toContain('912 W Oxford')
        ->and($redacted)->not->toContain('60067')
        ->and($redacted)->not->toContain('847-343-8944')
        ->and($redacted)->not->toContain('doug.k@example.com')
        ->and($redacted)->toContain('[address]')->toContain('[zip]')->toContain('[phone]')->toContain('[email]')
        // Product codes, prices and quantities survive.
        ->and($redacted)->toContain('Kohler K-728')->toContain('$18,500')->toContain('recessed lights x8')->toContain('Palatine');
});

it('applies a draft to a section at catalog prices', function () {
    $fx = claudeEstimateFixture();
    $c = $fx['catalog'];
    $section = EstimateSection::create(['estimate_id' => $fx['estimate']->id, 'name' => 'Powder Room', 'total' => 0]);

    $created = (new EstimateAIService())->applyToEstimate($fx['estimate'], $section, [
        ['line_item_id' => $c['Full Drywall']->id, 'quantity' => 4, 'cost' => 1, 'desc' => 'Ceiling and one wall'],
        ['line_item_id' => 424242, 'quantity' => 1],
    ]);

    expect($created)->toHaveCount(1)
        ->and((float) $created[0]->cost)->toBe(205.0)
        ->and((float) $created[0]->total)->toBe(820.0)
        // The description and notes on file for the item, not the model's.
        ->and($created[0]->desc)->toBe('Catalog description of Full Drywall')
        ->and($created[0]->notes)->toBe($c['Full Drywall']->notes)
        ->and((float) $section->fresh()->total)->toBe(820.0);
});

it('puts the catalog text back on drafted lines nobody has edited, and leaves edited lines alone', function () {
    $fx = claudeEstimateFixture();
    $c = $fx['catalog'];
    $c['Full Drywall']->update(['notes' => 'Two coats, sanded between.']);
    $section = EstimateSection::create(['estimate_id' => $fx['estimate']->id, 'name' => 'Powder Room', 'total' => 0]);

    $line = fn (LineItem $item, array $extra) => EstimateLineItem::create(array_merge([
        'estimate_id' => $fx['estimate']->id, 'line_item_id' => $item->id, 'section_id' => $section->id,
        'name' => $item->name, 'category' => $item->category, 'sub_category' => $item->sub_category, 'unit_type' => $item->unit_type, 'cost' => $item->cost,
    ], $extra));
    $drafted = $line($c['Full Drywall'], ['order' => 0, 'quantity' => 4, 'total' => 820, 'desc' => 'Ceiling and one wall', 'notes' => 'Allowance']);
    $edited = $line($c['Floor Tile'], ['order' => 1, 'quantity' => 10, 'total' => 238.5, 'desc' => 'Porcelain, herringbone', 'notes' => 'Client supplies']);
    \Illuminate\Support\Facades\DB::table('estimate_line_item')->where('id', $edited->id)->update(['updated_at' => now()->addMinute()]);

    $this->artisan('estimates:restore-catalog-text', ['estimate' => $fx['estimate']->id, '--dry-run' => true])
        ->expectsOutputToContain('would be')
        ->assertSuccessful();
    expect($drafted->fresh()->desc)->toBe('Ceiling and one wall');

    // The fixture's own sample lines are untouched too, so more than one restores.
    $this->artisan('estimates:restore-catalog-text', ['estimate' => $fx['estimate']->id])
        ->expectsOutputToContain('were restored to the catalog text')
        ->assertSuccessful();

    expect($drafted->fresh()->desc)->toBe('Catalog description of Full Drywall')
        ->and($drafted->fresh()->notes)->toBe('Two coats, sanded between.')
        ->and($edited->fresh()->desc)->toBe('Porcelain, herringbone')
        ->and($edited->fresh()->notes)->toBe('Client supplies');
});

it('sends only the floorplan numbers — never the uploaded file\'s name', function () {
    $fx = claudeEstimateFixture();
    $service = new FakeClaudeEstimateService();

    $service->generateEstimate('Hall bath remodel', [
        'filename' => 'Debby_Hill_1463_Winnetka_Floorplan.pdf', 'type' => 'pdf', 'note' => 'from the client email',
        'floor_sqft' => 48, 'wall_sqft' => 160, 'cement_board_sqft' => 120,
    ], $fx['vendor']->id);

    $userText = $service->sentRequests[0]['messages'][0]['content'];
    expect($userText)->toContain('FLOORPLAN DATA')->toContain('"floor_sqft": 48')
        ->and($userText)->not->toContain('Debby_Hill')->not->toContain('Winnetka')->not->toContain('client email');
});

it('sizes cement board in pieces from square feet, and tile in square feet', function () {
    $fx = claudeEstimateFixture();
    $c = $fx['catalog'];
    $service = new FakeClaudeEstimateService([
        'text' => json_encode(['reasoning' => 'ok', 'line_items' => [
            ['line_item_id' => $c['Floor Tile']->id, 'quantity' => 1, 'desc' => '', 'notes' => ''],
            ['line_item_id' => $c['Cement Boards']->id, 'quantity' => 1, 'desc' => '', 'notes' => ''],
        ]]),
        'stop_reason' => 'end_turn',
    ]);

    $result = $service->generateEstimate('Hall bath: tile floor and shower walls.', ['floor_sqft' => 120, 'cement_board_sqft' => 120], $fx['vendor']->id);
    $byName = collect($result['line_items'])->keyBy('name');

    expect($byName['Floor Tile']['quantity'])->toBe(120.0)
        // 120 sq.ft. of board is 4 sheets, not 120 sheets.
        ->and($byName['Cement Boards']['quantity'])->toBe(4.0);
});

it('hands each drafted line item to the caller as it streams in, priced and named from the catalog', function () {
    $fx = claudeEstimateFixture();
    $c = $fx['catalog'];
    $service = new FakeClaudeEstimateService([
        'text' => json_encode(['reasoning' => 'Hall bath: floor and shower.', 'line_items' => [
            ['line_item_id' => $c['Demo Bathroom']->id, 'quantity' => 1],
            ['line_item_id' => $c['Floor Tile']->id, 'quantity' => 1],
            ['line_item_id' => 999999, 'quantity' => 100],
            ['line_item_id' => $c['Cement Boards']->id, 'quantity' => 1],
        ]]),
        'stop_reason' => 'end_turn',
    ]);

    $streamed = [];
    $result = $service->generateEstimate(
        inquiry: 'Hall bath: tile floor and shower walls.',
        floorplanData: ['floor_sqft' => 120, 'cement_board_sqft' => 120],
        vendorId: $fx['vendor']->id,
        onLineItem: function (array $item, int $index) use (&$streamed) {
            $streamed[$index] = $item;
        },
    );

    // The reply was streamed, and the rows came out one by one in the model's order.
    expect(count($service->streamedChunks))->toBeGreaterThan(10)
        ->and(array_keys($streamed))->toBe([0, 1, 2])
        ->and(collect($streamed)->pluck('name')->all())->toBe(['Demo Bathroom', 'Floor Tile', 'Cement Boards'])
        // Catalog name, category and price on every streamed row; 999999 is nobody's item and never streams.
        ->and($streamed[1]['category'])->toBe('Tiles')
        ->and($streamed[1]['cost'])->toBe(23.85)
        ->and($streamed[1]['desc'])->toBe('Catalog description of Floor Tile')
        // The floorplan sizes streamed rows exactly as it sizes the finished draft.
        ->and($streamed[1]['quantity'])->toBe(120.0)
        ->and($streamed[2]['quantity'])->toBe(4.0)
        // What streamed is what the estimator then reviews.
        ->and($result['success'])->toBeTrue()
        ->and($result['line_items'])->toBe(array_values($streamed));
});

it('does not stream when nobody is listening', function () {
    $fx = claudeEstimateFixture();
    $service = new FakeClaudeEstimateService();

    $service->generateEstimate('Hall bath: new tile floor.', null, $fx['vendor']->id);

    expect($service->streamedChunks)->toBe([]);
});

it('carries the catalog\'s notes onto the estimate line, never the model\'s', function () {
    // The notes on file for an item are part of what the estimate says
    // (2026-09-16: a drafted kitchen showed the model's rewordings instead).
    $fx = claudeEstimateFixture();
    $c = $fx['catalog'];
    $c['Full Drywall']->forceFill(['notes' => 'Crew of two, order 10% extra'])->save();
    $section = EstimateSection::create(['estimate_id' => $fx['estimate']->id, 'name' => 'Powder Room', 'total' => 0]);

    $created = (new EstimateAIService())->applyToEstimate($fx['estimate'], $section, [
        ['line_item_id' => $c['Full Drywall']->id, 'quantity' => 2, 'notes' => null],
        ['line_item_id' => $c['Full Drywall']->id, 'quantity' => 1, 'notes' => 'Ceiling only', 'desc' => 'Model rewording'],
    ]);

    expect($created[0]->notes)->toBe('Crew of two, order 10% extra')
        ->and($created[1]->notes)->toBe('Crew of two, order 10% extra')
        ->and($created[1]->desc)->toBe('Catalog description of Full Drywall');
});

it('redacts lowercase, all-caps and hyphenated-number addresses too', function () {
    expect(EstimateAIService::redact('meet at 1463 w winnetka st tomorrow'))->toBe('meet at [address] tomorrow')
        ->and(EstimateAIService::redact('1463-65 W WINNETKA ST, two flat'))->toBe('[address], two flat')
        ->and(EstimateAIService::redact('300-4 pieces of drywall needed'))->toBe('300-4 pieces of drywall needed');
});

