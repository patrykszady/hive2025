<?php

use App\Livewire\Estimates\EstimateCombineLineItems;
use App\Models\EstimateAiDraft;
use App\Models\EstimateLineItem;
use App\Models\EstimateSection;
use App\Models\EstimateSignature;
use App\Models\LineItem;
use App\Services\EstimateAI\DraftCorrections;
use App\Services\Estimates\EstimateLineItemCombiner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/estimate-ai-fixtures.php';

/**
 * "Combine Items" on an estimate (2026-11-13): the same catalog item priced
 * in several sections — an estimate drafted room by room has the flooring in
 * every room — becomes one line in a section of its own. The fixture
 * estimate starts with a Hall Bath: Demo Bathroom (a lump sum, $850), 45
 * sq.ft. of Floor Tile at $23.85 and two GFCI locations at $230.
 */
function combineLine(array $fx, EstimateSection $section, string $item, float $quantity, array $overrides = []): EstimateLineItem
{
    $catalog = $fx['catalog'][$item];
    $cost = (float) ($overrides['cost'] ?? $catalog->cost);

    return EstimateLineItem::create($overrides + [
        'estimate_id' => $fx['estimate']->id, 'section_id' => $section->id, 'line_item_id' => $catalog->id, 'name' => $item,
        'category' => $catalog->category, 'sub_category' => $catalog->sub_category, 'unit_type' => $catalog->unit_type,
        'quantity' => $quantity, 'cost' => $cost, 'total' => round($quantity * $cost, 2), 'desc' => $catalog->desc,
    ]);
}

/** A kitchen and a laundry beside the fixture's Hall Bath, with tile in all three and a demo in two. */
function roomsWithSharedItems(): array
{
    $fx = estimateAdmin(claudeEstimateFixture());
    $kitchen = EstimateSection::create(['estimate_id' => $fx['estimate']->id, 'name' => 'Kitchen', 'total' => 0]);
    $laundry = EstimateSection::create(['estimate_id' => $fx['estimate']->id, 'name' => 'Laundry', 'total' => 0]);

    combineLine($fx, $kitchen, 'Floor Tile', 120);
    combineLine($fx, $kitchen, 'Full Drywall', 4);
    combineLine($fx, $kitchen, 'Demo Bathroom', 1);
    combineLine($fx, $laundry, 'Floor Tile', 40);
    combineLine($fx, $laundry, 'Roof Shingles', 10);

    return $fx + compact('kitchen', 'laundry');
}

function sectionTotals(array $fx): array
{
    return EstimateSection::query()->where('estimate_id', $fx['estimate']->id)->orderBy('id')->get()
        ->mapWithKeys(fn (EstimateSection $section) => [$section->name => (float) $section->total])->all();
}

it('offers the items priced in more than one section, with where each quantity is', function () {
    $fx = roomsWithSharedItems();

    $candidates = collect(app(EstimateLineItemCombiner::class)->candidates($fx['estimate']))->keyBy('name');

    // The GFCIs, the drywall and the shingles are each in one section only.
    expect($candidates->keys()->all())->toBe(['Demo Bathroom', 'Floor Tile'])
        ->and($candidates['Floor Tile'])->toMatchArray(['quantity' => 205.0, 'total' => 4889.25, 'unit_type' => 'sq.ft.'])
        ->and(collect($candidates['Floor Tile']['lines'])->pluck('quantity', 'section')->all())->toBe(['Hall Bath' => 45.0, 'Kitchen' => 120.0, 'Laundry' => 40.0])
        // A lump sum stays one lump: its money adds up, its quantity does not.
        ->and($candidates['Demo Bathroom'])->toMatchArray(['quantity' => 1.0, 'total' => 1700.0]);

    // A credit, the line it credits and a supplier's window are never combined away.
    $kitchenTile = EstimateLineItem::query()->where('section_id', $fx['kitchen']->id)->where('name', 'Floor Tile')->sole();
    combineLine($fx, $fx['laundry'], 'Floor Tile', -5, ['credit_for_id' => $kitchenTile->id]);
    combineLine($fx, $fx['laundry'], 'Demo Bathroom', 1, ['window_spec' => ['type' => 'casement']]);

    $candidates = collect(app(EstimateLineItemCombiner::class)->candidates($fx['estimate']))->keyBy('name');
    expect(collect($candidates['Floor Tile']['lines'])->pluck('quantity', 'section')->all())->toBe(['Hall Bath' => 45.0, 'Laundry' => 40.0])
        ->and(collect($candidates['Demo Bathroom']['lines'])->pluck('section')->all())->toBe(['Hall Bath', 'Kitchen']);
});

it('makes one line of each in a new section, and leaves the estimate total where it was', function () {
    $fx = roomsWithSharedItems();
    $c = $fx['catalog'];
    $before = sectionTotals($fx);
    $originals = EstimateLineItem::query()->where('estimate_id', $fx['estimate']->id)->whereIn('line_item_id', [$c['Floor Tile']->id, $c['Demo Bathroom']->id])->pluck('id');

    $result = app(EstimateLineItemCombiner::class)->combine($fx['estimate'], [$c['Floor Tile']->id, $c['Demo Bathroom']->id, $c['Full Drywall']->id], ' Flooring ');

    // Drywall is in one section only: asking for it changes nothing.
    expect($result['removed'])->toBe(5)->and($result['combined'])->toHaveCount(2)->and($result['emptied'])->toBe([]);

    $flooring = EstimateSection::query()->where('estimate_id', $fx['estimate']->id)->where('name', 'Flooring')->sole();
    $lines = EstimateLineItem::query()->where('section_id', $flooring->id)->get()->keyBy('name');

    expect($flooring->order)->toBe(EstimateSection::query()->where('estimate_id', $fx['estimate']->id)->max('order'))
        ->and($lines['Floor Tile'])->toMatchArray(['line_item_id' => $c['Floor Tile']->id, 'unit_type' => 'sq.ft.', 'category' => 'Tiles', 'desc' => 'Catalog description of Floor Tile'])
        ->and((float) $lines['Floor Tile']->quantity)->toBe(205.0)
        ->and((float) $lines['Floor Tile']->cost)->toBe(23.85)
        ->and((float) $lines['Floor Tile']->total)->toBe(4889.25)
        // The sections no longer say where the tile goes, so the line does.
        ->and($lines['Floor Tile']->notes)->toBe('Hall Bath 45 sq.ft. · Kitchen 120 sq.ft. · Laundry 40 sq.ft.')
        // Two $850 demos: one lump of $1,700.
        ->and((float) $lines['Demo Bathroom']->quantity)->toBe(1.0)
        ->and((float) $lines['Demo Bathroom']->cost)->toBe(1700.0)
        ->and((float) $lines['Demo Bathroom']->total)->toBe(1700.0)
        ->and($lines['Demo Bathroom']->notes)->toBe('Hall Bath · Kitchen');

    // The lines they replace are out of their sections, the restorable way.
    expect(EstimateLineItem::query()->whereIn('id', $originals)->count())->toBe(0)
        ->and(EstimateLineItem::onlyTrashed()->whereIn('id', $originals)->count())->toBe(5);

    $after = sectionTotals($fx);
    expect($after)->toEqual(['Hall Bath' => 460.0, 'Kitchen' => 820.0, 'Laundry' => 120.0, 'Flooring' => 6589.25])
        ->and(array_sum($after))->toEqualWithDelta(array_sum($before), 0.001);
});

it('adds to a section already carrying the name, and says which sections it emptied', function () {
    $fx = estimateAdmin(claudeEstimateFixture());
    $c = $fx['catalog'];
    $dining = EstimateSection::create(['estimate_id' => $fx['estimate']->id, 'name' => 'Dining Room', 'total' => 0]);
    $flooring = EstimateSection::create(['estimate_id' => $fx['estimate']->id, 'name' => 'flooring', 'total' => 0]);
    combineLine($fx, $flooring, 'Roof Shingles', 2);
    combineLine($fx, $dining, 'Floor Tile', 30, ['desc' => 'Large-format tile']);
    EstimateLineItem::query()->where('section_id', $fx['section']->id)->where('name', 'Floor Tile')->update(['desc' => 'Catalog description of Floor Tile']);

    $result = app(EstimateLineItemCombiner::class)->combine($fx['estimate'], [$c['Floor Tile']->id], 'Flooring');

    $line = $result['combined'][0];
    expect($result['section']->id)->toBe($flooring->id)
        ->and($result['combined'])->toHaveCount(1)
        ->and(EstimateSection::query()->where('estimate_id', $fx['estimate']->id)->count())->toBe(3)
        ->and((float) $line->quantity)->toBe(75.0)
        ->and((float) $line->cost)->toBe(23.85)
        ->and((float) $line->total)->toBe(1788.75)
        ->and($line->desc)->toBe("Catalog description of Floor Tile\nLarge-format tile")
        ->and((float) $flooring->fresh()->total)->toBe(24.0 + 1788.75)
        ->and($result['emptied'])->toBe(['Dining Room'])
        // An emptied section is left for the estimator to disable.
        ->and($dining->fresh()->trashed())->toBeFalse();
});

it('keeps a line per unit price where the sections price an item differently, never an averaged price', function () {
    $fx = estimateAdmin(claudeEstimateFixture());
    $c = $fx['catalog'];
    $dining = EstimateSection::create(['estimate_id' => $fx['estimate']->id, 'name' => 'Dining Room', 'total' => 0]);
    $kitchen = EstimateSection::create(['estimate_id' => $fx['estimate']->id, 'name' => 'Kitchen', 'total' => 0]);
    // Priced by hand in two rooms: 30 and 7 sq.ft. at $20, beside the hall bath's 45 at the catalog's $23.85.
    combineLine($fx, $dining, 'Floor Tile', 30, ['cost' => 20]);
    combineLine($fx, $kitchen, 'Floor Tile', 7, ['cost' => 20]);
    $before = array_sum(sectionTotals($fx));

    // The window says what it will become before anything is saved.
    $offered = collect(app(EstimateLineItemCombiner::class)->candidates($fx['estimate']))->firstWhere('line_item_id', $c['Floor Tile']->id);
    expect($offered['quantity'])->toBe(82.0)
        ->and($offered['total'])->toBe(1813.25)
        ->and($offered['becomes'])->toBe([
            ['quantity' => 45.0, 'cost' => 23.85, 'total' => 1073.25],
            ['quantity' => 37.0, 'cost' => 20.0, 'total' => 740.0],
        ])
        ->and(array_column($offered['lines'], 'cost'))->toBe([23.85, 20.0, 20.0]);

    Livewire::test(EstimateCombineLineItems::class, ['estimate' => $fx['estimate']])
        ->dispatch('openCombineLineItems')
        ->assertSee('Priced 2 ways, so it becomes 2 lines')->assertSee('45 sq.ft. at $23.85')->assertSee('37 sq.ft. at $20.00');

    $result = app(EstimateLineItemCombiner::class)->combine($fx['estimate'], [$c['Floor Tile']->id], 'Flooring');

    // 2026-11-13: one line at an averaged $22.11 would read 82 × 22.11 = $1,813.02 against a total of $1,813.25.
    $lines = collect($result['combined']);
    expect($result['items'])->toBe(1)
        ->and($result['removed'])->toBe(3)
        ->and($lines->map(fn (EstimateLineItem $line) => [(float) $line->quantity, (float) $line->cost, (float) $line->total])->all())
        ->toBe([[45.0, 23.85, 1073.25], [37.0, 20.0, 740.0]])
        // Every line multiplies out to its own total.
        ->and($lines->every(fn (EstimateLineItem $line) => abs((float) $line->quantity * (float) $line->cost - (float) $line->total) < 0.005))->toBeTrue()
        ->and($lines->pluck('notes')->all())->toBe(['Hall Bath 45 sq.ft.', 'Dining Room 30 sq.ft. · Kitchen 7 sq.ft.'])
        ->and($lines->pluck('section_id')->unique()->all())->toBe([$result['section']->id])
        ->and(array_sum(sectionTotals($fx)))->toEqualWithDelta($before, 0.001);

    // The AI's memory of a draft knows which combined line each of its lines went into, price by price.
    $again = collect(app(EstimateLineItemCombiner::class)->candidates($fx['estimate']))->firstWhere('line_item_id', $c['Floor Tile']->id);
    expect($again)->toBeNull();
});

it('carries the allowances over without changing what they add up to', function () {
    $fx = estimateAdmin(claudeEstimateFixture());
    $c = $fx['catalog'];
    $kitchen = EstimateSection::create(['estimate_id' => $fx['estimate']->id, 'name' => 'Kitchen', 'total' => 0]);
    $bathTile = EstimateLineItem::query()->where('section_id', $fx['section']->id)->where('name', 'Floor Tile')->sole();
    $kitchenTile = combineLine($fx, $kitchen, 'Floor Tile', 40);

    // The catalog's default allowance for the item: $5 a square foot of tile.
    $default = \App\Models\LineItemAllowance::query()->create([
        'line_item_id' => $c['Floor Tile']->id, 'description' => 'Tile material', 'pricing_mode' => 'per_unit', 'unit_amount' => 5, 'amount' => 5,
        'belongs_to_vendor_id' => $fx['vendor']->id,
    ]);

    // The tile itself at $5 a foot in both rooms; grout only in the bath; trim at a different rate in each.
    $bathTile->allowances()->createMany([
        ['description' => 'Tile material', 'pricing_mode' => 'per_unit', 'unit_amount' => 5, 'amount' => 225, 'line_item_allowance_id' => $default->id],
        ['description' => 'Grout', 'pricing_mode' => 'lump_sum', 'unit_amount' => null, 'amount' => 50],
        ['description' => 'Trim', 'pricing_mode' => 'per_unit', 'unit_amount' => 2, 'amount' => 90],
    ]);
    $kitchenTile->allowances()->createMany([
        ['description' => 'tile material ', 'pricing_mode' => 'per_unit', 'unit_amount' => 5, 'amount' => 200],
        ['description' => 'Trim', 'pricing_mode' => 'per_unit', 'unit_amount' => 3, 'amount' => 120],
    ]);

    $line = app(EstimateLineItemCombiner::class)->combine($fx['estimate'], [$c['Floor Tile']->id], 'Flooring')['combined'][0];
    $allowances = $line->allowances()->get()->keyBy('description');

    expect($allowances->keys()->all())->toBe(['Tile material', 'Grout', 'Trim'])
        // The same rate on every line: still per square foot, now over all 85.
        ->and($allowances['Tile material'])->toMatchArray(['pricing_mode' => 'per_unit', 'unit_amount' => '5.00', 'amount' => '425.00'])
        ->and($allowances['Grout'])->toMatchArray(['pricing_mode' => 'lump_sum', 'amount' => '50.00'])
        // Unlike rates cannot follow one quantity: a lump of what the two rooms carried.
        ->and($allowances['Trim'])->toMatchArray(['pricing_mode' => 'lump_sum', 'unit_amount' => null, 'amount' => '210.00'])
        ->and((float) $allowances->sum('amount'))->toBe(225.0 + 50 + 90 + 200 + 120)
        // The catalog's own default for the item is not this job's sum: the next estimate is still offered $5 a foot.
        ->and($allowances['Tile material']->line_item_allowance_id)->toBe($default->id)
        ->and($default->fresh())->toMatchArray(['pricing_mode' => 'per_unit', 'unit_amount' => '5.00', 'amount' => '5.00'])
        ->and(\App\Models\LineItemAllowance::query()->where('line_item_id', $c['Floor Tile']->id)->count())->toBe(1);
});

it('leaves a signed contract\'s lines where they are', function () {
    $fx = roomsWithSharedItems();
    $c = $fx['catalog'];
    // The company can see its own project (so the contract can be told signed); the client has no one who must sign.
    $project = \App\Models\Project::withoutGlobalScopes()->findOrFail($fx['estimate']->project_id);
    $project->vendors()->attach($fx['vendor']->id, ['client_id' => $project->client_id]);
    $fx['vendor']->clients()->attach($project->client_id);

    $this->travel(1)->hours();
    EstimateSignature::create([
        'estimate_id' => $fx['estimate']->id, 'user_id' => $fx['user']->id, 'signer_name' => 'Est Imator', 'signer_email' => $fx['user']->email,
        'signature_data' => 'data:image/png;base64,x', 'signature_type' => 'draw', 'ip_address' => '127.0.0.1', 'user_agent' => 'pest', 'document_hash' => 'h', 'signed_at' => now(),
    ]);
    expect($fx['section']->fresh()->isLocked())->toBeTrue();

    // Two change-order sections added after signing, both with tile.
    $this->travel(1)->hours();
    $mudroom = EstimateSection::create(['estimate_id' => $fx['estimate']->id, 'name' => 'Mudroom', 'total' => 0]);
    $pantry = EstimateSection::create(['estimate_id' => $fx['estimate']->id, 'name' => 'Pantry', 'total' => 0]);
    combineLine($fx, $mudroom, 'Floor Tile', 60);
    combineLine($fx, $pantry, 'Floor Tile', 25);

    $combiner = app(EstimateLineItemCombiner::class);
    $candidates = collect($combiner->candidates($fx['estimate']->fresh()))->keyBy('name');

    expect($candidates->keys()->all())->toBe(['Floor Tile'])
        ->and(collect($candidates['Floor Tile']['lines'])->pluck('section')->all())->toBe(['Mudroom', 'Pantry']);

    // A signed section is never offered to take combined lines, nor taken if asked for.
    Livewire::test(EstimateCombineLineItems::class, ['estimate' => $fx['estimate']->fresh()])
        ->dispatch('openCombineLineItems')
        ->assertDontSeeHtml('wire:key="combine-into-'.$fx['section']->id.'"')->assertSeeHtml('wire:key="combine-into-'.$mudroom->id.'"')->assertSee('Section 4 on this estimate')
        ->set('selected', [(string) $c['Floor Tile']->id])->set('into', (string) $fx['kitchen']->id)
        ->call('combine')->assertHasErrors(['into' => 'in']);
    expect(fn () => $combiner->combine($fx['estimate']->fresh(), [$c['Floor Tile']->id], '', $fx['kitchen']->id))->toThrow(InvalidArgumentException::class);

    // "Kitchen" is a signed section: the combined line gets a section of its own beside it, not a place in the contract.
    $result = $combiner->combine($fx['estimate']->fresh(), [$c['Floor Tile']->id], 'Kitchen');
    expect($result['section']->id)->not->toBe($fx['kitchen']->id)
        ->and((float) $result['combined'][0]->quantity)->toBe(85.0)
        ->and(EstimateLineItem::query()->where('section_id', $fx['section']->id)->count())->toBe(3)
        ->and(EstimateLineItem::query()->where('section_id', $fx['kitchen']->id)->count())->toBe(3);
});

it('does not read to the AI as lines the estimator removed', function () {
    $fx = roomsWithSharedItems();
    $c = $fx['catalog'];
    $kitchenLines = EstimateLineItem::query()->where('section_id', $fx['kitchen']->id)->get()->keyBy('name');

    $draft = EstimateAiDraft::create([
        'vendor_id' => $fx['vendor']->id, 'estimate_id' => $fx['estimate']->id, 'section_id' => $fx['kitchen']->id,
        'inquiry' => 'New kitchen.', 'status' => EstimateAiDraft::DRAFTED,
        'drafted_items' => $kitchenLines->map(fn (EstimateLineItem $line) => [
            'estimate_line_item_id' => $line->id, 'line_item_id' => $line->line_item_id, 'name' => $line->name,
            'quantity' => (float) $line->quantity, 'unit_type' => $line->unit_type, 'cost' => (float) $line->cost,
        ])->values()->all(),
    ]);

    app(EstimateLineItemCombiner::class)->combine($fx['estimate'], [$c['Floor Tile']->id, $c['Demo Bathroom']->id], 'Throughout');
    // The drywall the estimator really did take out.
    $kitchenLines['Full Drywall']->delete();

    $corrections = DraftCorrections::forDraft($draft->fresh());
    expect(collect($corrections['removed'])->pluck('name')->all())->toBe(['Full Drywall'])
        ->and($corrections['added'])->toBe([])
        ->and(collect($draft->fresh()->drafted_items)->whereNotNull('combined_into')->pluck('name')->sort()->values()->all())->toBe(['Demo Bathroom', 'Floor Tile']);
});

it('combines from the estimate page: pick the items, name the section', function () {
    $fx = roomsWithSharedItems();
    $c = $fx['catalog'];

    $component = Livewire::test(EstimateCombineLineItems::class, ['estimate' => $fx['estimate']])
        ->dispatch('openCombineLineItems')
        ->assertSee('Combine line items')
        ->assertSee('Floor Tile')->assertSee('205 sq.ft.')->assertSee('$4,889.25')
        ->assertSee('3 sections:')->assertSee('Kitchen 120')
        ->assertSee('Demo Bathroom')->assertSee('$1,700.00')
        ->assertDontSee('Full Drywall')->assertDontSee('Roof Shingles');

    // Nothing ticked, nowhere picked.
    $component->call('combine')->assertHasErrors(['selected', 'into']);
    expect(EstimateSection::query()->where('estimate_id', $fx['estimate']->id)->count())->toBe(3);

    // The section name follows the picks until the estimator types one: one trade, then several.
    $component->set('selected', [(string) $c['Floor Tile']->id])->assertSet('sectionName', 'Tiles')
        ->set('selected', [(string) $c['Floor Tile']->id, (string) $c['Demo Bathroom']->id])->assertSet('sectionName', 'Throughout')
        ->set('sectionName', 'Whole House')
        ->set('selected', [(string) $c['Floor Tile']->id])->assertSet('sectionName', 'Whole House');

    // An id that is not one of this estimate's items (typed into the request) is not acted on.
    $component->set('selected', [(string) $c['Full Drywall']->id, '999999'])->call('combine')->assertHasErrors('selected');
    expect(EstimateSection::query()->where('estimate_id', $fx['estimate']->id)->count())->toBe(3);

    $component->set('selected', [(string) $c['Floor Tile']->id])->call('combine')->assertHasNoErrors()
        ->assertDispatched('refreshComponent');

    $wholeHouse = EstimateSection::query()->where('estimate_id', $fx['estimate']->id)->where('name', 'Whole House')->sole();
    expect(EstimateLineItem::query()->where('section_id', $wholeHouse->id)->pluck('quantity', 'name')->map(fn ($q) => (float) $q)->all())->toBe(['Floor Tile' => 205.0]);

    // Opened again, what is left to combine is the demo.
    $component->dispatch('openCombineLineItems')->assertSet('selected', [])->assertSet('sectionName', '')
        ->assertSee('Demo Bathroom')->assertDontSee('205 sq.ft.');
});

it('lists the items A to Z by name, items of the same name by trade', function () {
    $fx = roomsWithSharedItems();
    $c = $fx['catalog'];
    combineLine($fx, $fx['laundry'], 'Full Drywall', 2);
    // Another catalog item called Floor Tile, under Flooring.
    $fx['catalog']['Vinyl'] = LineItem::withoutGlobalScopes()->create([
        'name' => 'Floor Tile', 'category' => 'Flooring', 'sub_category' => 'Vinyl', 'unit_type' => 'sq.ft.', 'cost' => 9, 'belongs_to_vendor_id' => $fx['vendor']->id,
    ]);
    combineLine($fx, $fx['kitchen'], 'Vinyl', 30, ['name' => 'Floor Tile']);
    combineLine($fx, $fx['laundry'], 'Vinyl', 12, ['name' => 'Floor Tile']);

    $listed = collect(app(EstimateLineItemCombiner::class)->candidates($fx['estimate']))
        ->map(fn (array $item) => $item['name'].' ('.$item['category'].')')->all();

    // By trade it read Demolition, Drywall, Flooring, Tiles — Full Drywall before Floor Tile.
    expect($listed)->toBe(['Demo Bathroom (Demolition)', 'Floor Tile (Flooring)', 'Floor Tile (Tiles)', 'Full Drywall (Drywall)']);

    // The window and its Show picker list them the same way.
    $window = Livewire::test(EstimateCombineLineItems::class, ['estimate' => $fx['estimate']])->dispatch('openCombineLineItems')->instance();

    expect(array_column($window->shown, 'line_item_id'))->toBe([$c['Demo Bathroom']->id, $fx['catalog']['Vinyl']->id, $c['Floor Tile']->id, $c['Full Drywall']->id])
        ->and(array_column($window->itemChoices, 'label'))->toBe(['Demo Bathroom — Demolition/Demo', 'Floor Tile — Flooring/Vinyl', 'Floor Tile — Tiles/Floor', 'Full Drywall — Drywall/Install']);
});

it('shows and combines one catalog item on its own, by its global line item id, never another item of the same name', function () {
    $fx = roomsWithSharedItems();
    $c = $fx['catalog'];
    // A different catalog item that happens to be called Floor Tile too.
    $fx['catalog']['Vinyl'] = LineItem::withoutGlobalScopes()->create([
        'name' => 'Floor Tile', 'category' => 'Flooring', 'sub_category' => 'Vinyl', 'unit_type' => 'sq.ft.', 'cost' => 9, 'belongs_to_vendor_id' => $fx['vendor']->id,
    ]);
    combineLine($fx, $fx['kitchen'], 'Vinyl', 30, ['name' => 'Floor Tile']);
    combineLine($fx, $fx['laundry'], 'Vinyl', 12, ['name' => 'Floor Tile']);
    $vinyl = $fx['catalog']['Vinyl'];
    $row = fn (int $key) => 'wire:key="combine-item-'.$key.'"';

    // Opened on the vinyl alone (a line's own combine button): only its lines, ticked, its trade suggested.
    $component = Livewire::test(EstimateCombineLineItems::class, ['estimate' => $fx['estimate']])
        ->dispatch('openCombineLineItems', only: $vinyl->id)
        ->assertSet('only', (string) $vinyl->id)
        ->assertSet('selected', [(string) $vinyl->id])
        ->assertSet('sectionName', 'Flooring')
        ->assertSee('42 sq.ft.')
        ->assertSeeHtml($row($vinyl->id))
        ->assertDontSeeHtml($row($c['Floor Tile']->id))
        ->assertDontSeeHtml($row($c['Demo Bathroom']->id))
        // The picker tells the two Floor Tiles apart by trade and catalog number.
        ->assertSee('Floor Tile — Flooring/Vinyl (#'.$vinyl->id.')');

    // Picking the other Floor Tile shows and ticks it alone.
    $component->set('only', (string) $c['Floor Tile']->id)
        ->assertSet('selected', [(string) $c['Floor Tile']->id])
        ->assertSee('205 sq.ft.')
        ->assertDontSeeHtml($row($vinyl->id));

    // Every item again: all of them listed, the tick kept.
    $component->set('only', '')
        ->assertSet('selected', [(string) $c['Floor Tile']->id])
        ->assertSeeHtml($row($vinyl->id))
        ->assertSeeHtml($row($c['Demo Bathroom']->id));

    // With one item shown, only it is combined — even with another ticked out of sight.
    $component->set('only', (string) $vinyl->id)
        ->set('selected', [(string) $vinyl->id, (string) $c['Floor Tile']->id])
        ->set('sectionName', 'Vinyl')
        ->call('combine')->assertHasNoErrors();

    $vinylSection = EstimateSection::query()->where('estimate_id', $fx['estimate']->id)->where('name', 'Vinyl')->sole();
    expect(EstimateLineItem::query()->where('section_id', $vinylSection->id)->get()->map(fn (EstimateLineItem $line) => [(int) $line->line_item_id, (float) $line->quantity])->all())
        ->toBe([[$vinyl->id, 42.0]])
        ->and(EstimateLineItem::query()->where('estimate_id', $fx['estimate']->id)->where('line_item_id', $c['Floor Tile']->id)->count())->toBe(3);
});

function linesIn(EstimateSection $section): array
{
    return EstimateLineItem::query()->where('section_id', $section->id)->orderBy('id')->get()
        ->map(fn (EstimateLineItem $line) => [$line->name, (float) $line->quantity])->all();
}

it('puts the combined lines in a section already on the estimate, picked from the list — one they come from included', function () {
    $fx = roomsWithSharedItems();
    $c = $fx['catalog'];
    $before = array_sum(sectionTotals($fx));

    $component = Livewire::test(EstimateCombineLineItems::class, ['estimate' => $fx['estimate']])
        ->dispatch('openCombineLineItems', only: $c['Floor Tile']->id)
        // One combobox: the suggested new section to start with, then every section, numbered as the estimate numbers them.
        ->assertSet('into', 'new:Tiles')
        ->assertSee('New section at the end of the estimate')
        ->assertSeeHtml('wire:key="combine-into-'.$fx['section']->id.'"')->assertSee('Section 1 on this estimate')
        ->assertSeeHtml('wire:key="combine-into-'.$fx['kitchen']->id.'"')->assertSee('Section 2 on this estimate')
        ->assertSeeHtml('wire:key="combine-into-'.$fx['laundry']->id.'"')->assertSee('Section 3 on this estimate');

    // A new section needs its name; a section that is not this estimate's (an id typed into the request) is refused.
    $component->set('sectionName', '')->call('combine')->assertHasErrors(['sectionName' => 'required']);
    $component->set('into', '999999')->call('combine')->assertHasErrors(['into' => 'in']);
    expect(EstimateLineItem::query()->where('estimate_id', $fx['estimate']->id)->where('line_item_id', $c['Floor Tile']->id)->count())->toBe(3);

    // A name typed in the select's search and created in line becomes the new section's; the search empties.
    $component->set('into', (string) $fx['kitchen']->id)->set('search', '  Flooring ')->call('newSection')
        // The name is in the value, so the select shows the new name at once rather than the suggestion it replaced.
        ->assertSet('into', 'new:Flooring')->assertSet('sectionName', 'Flooring')->assertSet('search', '')->assertSee('Flooring');
    // Nothing typed creates nothing; a name too long is refused, the one before it kept.
    $component->call('newSection')->assertSet('sectionName', 'Flooring')
        ->set('search', str_repeat('x', 61))->call('newSection')->assertHasErrors('sectionName')->assertSet('sectionName', 'Flooring');
    // 2026-11-14: bound to the name, the select wrote its own value "new" into it and a section called "new" was made.
    // The search is its own property; the name is never bound to anything the select can write.
    $component->assertSeeHtml('wire:model="search"')->assertDontSeeHtml('wire:model="sectionName"');

    // A name typed in the search but never chosen is not guessed at: Combine asks, and nothing moves.
    $component->set('search', 'Floo')->call('combine')->assertHasErrors('into')->assertSee('You typed &quot;Floo&quot;', false);
    expect(EstimateLineItem::query()->where('estimate_id', $fx['estimate']->id)->where('line_item_id', $c['Floor Tile']->id)->count())->toBe(3);
    $component->set('search', '');

    // Then the kitchen picked instead: the pick wins, and its name is not suggested over.
    $component->set('into', (string) $fx['kitchen']->id)->set('sectionName', 'Kitchen')
        ->set('selected', [(string) $c['Floor Tile']->id])->assertSet('sectionName', 'Kitchen')
        ->assertSee('At the end of that section');

    // Into the kitchen, one of the rooms the tile comes from: no new section.
    $component->call('combine')->assertHasNoErrors();

    expect(linesIn($fx['kitchen']))->toBe([['Full Drywall', 4.0], ['Demo Bathroom', 1.0], ['Floor Tile', 205.0]])
        ->and(linesIn($fx['laundry']))->toBe([['Roof Shingles', 10.0]])
        ->and(EstimateSection::query()->where('estimate_id', $fx['estimate']->id)->count())->toBe(3)
        ->and(array_sum(sectionTotals($fx)))->toEqualWithDelta($before, 0.01);

    // Into a section the lines do not come from.
    $finishes = EstimateSection::create(['estimate_id' => $fx['estimate']->id, 'name' => 'Finishes', 'total' => 0]);
    $component->dispatch('openCombineLineItems')
        ->set('selected', [(string) $c['Demo Bathroom']->id])
        ->set('into', (string) $finishes->id)
        ->call('combine')->assertHasNoErrors();

    expect(linesIn($finishes))->toBe([['Demo Bathroom', 1.0]])
        ->and(linesIn($fx['kitchen']))->toBe([['Full Drywall', 4.0], ['Floor Tile', 205.0]])
        ->and(array_sum(sectionTotals($fx)))->toEqualWithDelta($before, 0.01);
});

it('keeps a change order\'s money on its own bid when a section is picked', function () {
    $fx = estimateAdmin(claudeEstimateFixture());
    $project = \App\Models\Project::withoutGlobalScopes()->findOrFail($fx['estimate']->project_id);
    $changeOrder = \App\Models\Bid::withoutGlobalScopes()->create(['project_id' => $project->id, 'vendor_id' => $fx['vendor']->id, 'amount' => 0, 'type' => 2]);
    $kitchen = EstimateSection::create(['estimate_id' => $fx['estimate']->id, 'name' => 'Kitchen', 'total' => 0]);
    $mudroom = EstimateSection::create(['estimate_id' => $fx['estimate']->id, 'name' => 'Mudroom', 'total' => 0, 'bid_id' => $changeOrder->id]);
    $pantry = EstimateSection::create(['estimate_id' => $fx['estimate']->id, 'name' => 'Pantry', 'total' => 0, 'bid_id' => $changeOrder->id]);
    combineLine($fx, $kitchen, 'Floor Tile', 100);
    combineLine($fx, $mudroom, 'Floor Tile', 60);
    combineLine($fx, $pantry, 'Floor Tile', 25);

    $combiner = app(EstimateLineItemCombiner::class);
    $combiner->combine($fx['estimate'], collect($combiner->candidates($fx['estimate']))->pluck('key')->all(), '', $kitchen->id);

    // The contract's tile goes in the kitchen picked; the change order's in a Kitchen on its own bid.
    $kitchens = EstimateSection::query()->where('estimate_id', $fx['estimate']->id)->where('name', 'Kitchen')->orderBy('id')->get();
    expect($kitchens)->toHaveCount(2)
        ->and($kitchens->first()->id)->toBe($kitchen->id)
        ->and(linesIn($kitchen))->toBe([['Floor Tile', 145.0]])
        ->and($kitchens->last()->bid_id)->toBe($changeOrder->id)
        ->and(linesIn($kitchens->last()))->toBe([['Floor Tile', 85.0]]);
});

it('is for whoever may change the estimate, and says so when there is nothing to combine', function () {
    $fx = estimateAdmin(claudeEstimateFixture());

    Livewire::test(EstimateCombineLineItems::class, ['estimate' => $fx['estimate']])
        ->dispatch('openCombineLineItems')
        ->assertSee('No item is on this estimate in more than one section.');

    // A signed-in member of the company who is not one of its admins.
    $member = \App\Models\User::query()->create([
        'first_name' => 'Crew', 'last_name' => 'Member', 'email' => 'crew-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224777####'), 'password' => bcrypt('password'),
    ]);
    $member->forceFill(['primary_vendor_id' => $fx['vendor']->id])->saveQuietly();
    $member->vendors()->attach($fx['vendor']->id, ['role_id' => 2, 'is_employed' => 1]);
    $this->actingAs($member->refresh());

    Livewire::test(EstimateCombineLineItems::class, ['estimate' => $fx['estimate']])
        ->dispatch('openCombineLineItems')
        ->assertForbidden();

    // Another company's catalog item, even one this estimate uses nowhere, changes nothing.
    $other = LineItem::withoutGlobalScopes()->create(['name' => 'Theirs', 'category' => 'X', 'unit_type' => 'pieces', 'cost' => 1, 'belongs_to_vendor_id' => $fx['vendor']->id + 999]);
    expect(fn () => app(EstimateLineItemCombiner::class)->combine($fx['estimate'], [$other->id], 'Flooring'))->toThrow(InvalidArgumentException::class);
    expect(EstimateSection::query()->where('estimate_id', $fx['estimate']->id)->count())->toBe(1);
});

it('keeps a change order\'s money on its own bid', function () {
    $fx = estimateAdmin(claudeEstimateFixture());
    $c = $fx['catalog'];
    $project = \App\Models\Project::withoutGlobalScopes()->findOrFail($fx['estimate']->project_id);
    $changeOrder = \App\Models\Bid::withoutGlobalScopes()->create(['project_id' => $project->id, 'vendor_id' => $fx['vendor']->id, 'amount' => 0, 'type' => 2]);

    // The contract's own sections (no bid of their own) and two change-order sections, tile in all four.
    $kitchen = EstimateSection::create(['estimate_id' => $fx['estimate']->id, 'name' => 'Kitchen', 'total' => 0]);
    $mudroom = EstimateSection::create(['estimate_id' => $fx['estimate']->id, 'name' => 'Mudroom', 'total' => 0, 'bid_id' => $changeOrder->id]);
    $pantry = EstimateSection::create(['estimate_id' => $fx['estimate']->id, 'name' => 'Pantry', 'total' => 0, 'bid_id' => $changeOrder->id]);
    combineLine($fx, $kitchen, 'Floor Tile', 100);
    combineLine($fx, $mudroom, 'Floor Tile', 60);
    combineLine($fx, $pantry, 'Floor Tile', 25);
    $changeOrder->update(['amount' => 85 * 23.85]);

    $combiner = app(EstimateLineItemCombiner::class);
    $candidates = collect($combiner->candidates($fx['estimate']))->keyBy('key');

    // The same item, offered once per bid: the contract's two rooms, the change order's two.
    expect($candidates->keys()->all())->toEqual([$c['Floor Tile']->id, $c['Floor Tile']->id.'-b'.$changeOrder->id])
        ->and(collect($candidates[$c['Floor Tile']->id.'-b'.$changeOrder->id]['lines'])->pluck('section')->all())->toBe(['Mudroom', 'Pantry'])
        ->and(collect($candidates[(string) $c['Floor Tile']->id]['lines'])->pluck('section')->all())->toBe(['Hall Bath', 'Kitchen']);

    $result = $combiner->combine($fx['estimate'], $candidates->keys()->all(), 'Flooring');
    $targets = EstimateSection::query()->where('estimate_id', $fx['estimate']->id)->where('name', 'Flooring')->orderBy('id')->get();

    // Two Flooring sections, each on the bid its lines came from; no new change order was opened, and no bid's amount moved.
    expect($targets)->toHaveCount(2)
        ->and($targets->pluck('bid_id')->all())->toEqualCanonicalizing([null, $changeOrder->id])
        ->and((float) $targets->firstWhere('bid_id', $changeOrder->id)->total)->toBe(round(85 * 23.85, 2))
        ->and((float) $targets->firstWhere('bid_id', null)->total)->toBe(round(145 * 23.85, 2))
        ->and(\App\Models\Bid::withoutGlobalScopes()->where('project_id', $project->id)->count())->toBe(1)
        ->and((float) $changeOrder->fresh()->amount)->toBe(round(85 * 23.85, 2))
        ->and($result['combined'])->toHaveCount(2);
});
