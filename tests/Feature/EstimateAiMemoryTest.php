<?php

use App\Console\Commands\EvaluateEstimateAi;
use App\Models\EstimateAiDraft;
use App\Models\EstimateAiRule;
use App\Models\EstimateLineItem;
use App\Models\EstimateSection;
use App\Models\EstimateSectionEmbedding;
use App\Models\EstimateSignature;
use App\Services\EstimateAI\DraftCorrections;
use App\Services\EstimateAI\EmbeddingService;
use App\Services\EstimateAI\RuleProposer;
use App\Services\EstimateAI\SectionRetriever;
use App\Services\EstimateAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/estimate-ai-fixtures.php';

/**
 * Two more past sections next to the fixture's Hall Bath: a kitchen and a basement.
 */
function morePastSections(array $fx): array
{
    $c = $fx['catalog'];
    $sections = [];
    foreach ([
        'Kitchen' => [['Demo Bathroom', 1], ['Full Drywall', 4], ['Floor Tile', 180]],
        'Basement' => [['Full Drywall', 30], ['New GFCI Location', 6]],
    ] as $name => $lines) {
        $section = EstimateSection::create(['estimate_id' => $fx['estimate']->id, 'name' => $name, 'total' => 0]);
        foreach ($lines as [$item, $qty]) {
            EstimateLineItem::create([
                'estimate_id' => $fx['estimate']->id, 'section_id' => $section->id, 'line_item_id' => $c[$item]->id,
                'name' => $item, 'category' => $c[$item]->category, 'sub_category' => $c[$item]->sub_category,
                'unit_type' => $c[$item]->unit_type, 'quantity' => $qty, 'cost' => $c[$item]->cost, 'total' => $qty * $c[$item]->cost,
            ]);
        }
        $sections[$name] = $section;
    }

    return $sections;
}

function keptDraft(array $fx, EstimateSection $section, array $draftedNames, string $inquiry = 'Hall bath rip and replace.'): EstimateAiDraft
{
    $c = $fx['catalog'];

    return EstimateAiDraft::create([
        'vendor_id' => $fx['vendor']->id, 'estimate_id' => $fx['estimate']->id, 'section_id' => $section->id,
        'inquiry' => $inquiry, 'status' => EstimateAiDraft::FINISHED,
        'drafted_items' => collect($draftedNames)->map(fn ($name) => [
            'line_item_id' => $c[$name]->id, 'name' => $name, 'quantity' => is_array($name) ? 1 : 1, 'unit_type' => $c[$name]->unit_type, 'cost' => (float) $c[$name]->cost,
        ])->all(),
    ]);
}

beforeEach(function () {
    config()->set('services.openai.api_key', null);
});

it('finds the past section closest to the enquiry by its words when there are no embeddings', function () {
    $fx = claudeEstimateFixture();
    $more = morePastSections($fx);
    $retriever = app(SectionRetriever::class);

    $kitchen = $retriever->similar($fx['vendor']->id, 'Kitchen remodel: new cabinets, counters and a tile floor.', 2);
    expect($kitchen[0]['section']->id)->toBe($more['Kitchen']->id)
        ->and($kitchen[0]['score'])->toBeGreaterThan(0);

    $bath = $retriever->similar($fx['vendor']->id, 'Rip out the hall bathroom, new tile floor and shower.', 2);
    expect($bath[0]['section']->id)->toBe($fx['section']->id);

    // Nothing resembles a wine cellar: the latest sections stand in, and the answer key is never shown.
    $cellar = $retriever->similar($fx['vendor']->id, 'Wine cellar in a closet.', 2, [$more['Basement']->id]);
    expect(collect($cellar)->pluck('section.id')->all())->toBe([$more['Kitchen']->id, $fx['section']->id])
        ->and($cellar[0]['score'])->toBe(0.0);
});

it('ranks by meaning with embeddings, stores them once, and re-embeds a section whose text changed', function () {
    config()->set('services.openai.api_key', 'test-key');
    $fx = claudeEstimateFixture();
    $more = morePastSections($fx);

    // A toy embedding space: kitchens point one way, everything else the other.
    Http::fake(['https://api.openai.com/v1/embeddings' => function ($request) {
        $inputs = $request['input'];

        return Http::response(['data' => collect($inputs)->map(fn ($text, $i) => [
            'index' => $i,
            'embedding' => str_contains(strtolower($text), 'kitchen') ? [1.0, 0.1] : [0.1, 1.0],
        ])->all()]);
    }]);

    $retriever = app(SectionRetriever::class);
    $ranked = $retriever->similar($fx['vendor']->id, 'Kitchen: cabinets and counters.', 3);

    expect($ranked[0]['section']->id)->toBe($more['Kitchen']->id)
        ->and($ranked[0]['score'])->toBeGreaterThan($ranked[1]['score'])
        ->and(EstimateSectionEmbedding::count())->toBe(3);

    // One batch for the three sections, one for the enquiry; the next enquiry embeds only itself.
    Http::assertSentCount(2);
    $retriever->similar($fx['vendor']->id, 'Another kitchen.', 3);
    Http::assertSentCount(3);

    // A section that gained a line is embedded again.
    $c = $fx['catalog'];
    EstimateLineItem::create([
        'estimate_id' => $fx['estimate']->id, 'section_id' => $more['Basement']->id, 'line_item_id' => $c['Floor Tile']->id,
        'name' => 'Floor Tile', 'category' => 'Tiles', 'sub_category' => 'Floor', 'unit_type' => 'sq.ft.', 'quantity' => 300, 'cost' => 23.85, 'total' => 7155,
    ]);
    $before = EstimateSectionEmbedding::where('section_id', $more['Basement']->id)->value('text_hash');
    $retriever->similar($fx['vendor']->id, 'Basement.', 3);
    expect(EstimateSectionEmbedding::where('section_id', $more['Basement']->id)->value('text_hash'))->not->toBe($before)
        ->and(EstimateSectionEmbedding::count())->toBe(3);
});

it('falls back to words when the embeddings API is down', function () {
    config()->set('services.openai.api_key', 'test-key');
    Http::fake(['https://api.openai.com/v1/embeddings' => Http::response('nope', 500)]);
    $fx = claudeEstimateFixture();
    $more = morePastSections($fx);

    $ranked = app(SectionRetriever::class)->similar($fx['vendor']->id, 'Kitchen remodel.', 1);

    expect($ranked[0]['section']->id)->toBe($more['Kitchen']->id)
        ->and(EstimateSectionEmbedding::count())->toBe(0);
});

it('briefs the model with the company name, its rules and what estimators changed after similar drafts, and hides the answer key', function () {
    $fx = claudeEstimateFixture();
    $c = $fx['catalog'];
    $fx['vendor']->forceFill(['short_name' => 'GS Construction', 'city' => 'Palatine', 'state' => 'IL'])->save();

    EstimateAiRule::create(['vendor_id' => $fx['vendor']->id, 'text' => 'Always add a Misc Framing line with a structural header.', 'status' => 'active', 'source' => 'manual']);
    EstimateAiRule::create(['vendor_id' => $fx['vendor']->id, 'text' => 'Not yet approved.', 'status' => 'proposed', 'source' => 'proposed', 'fingerprint' => 'x']);
    $other = \App\Models\Vendor::query()->create(['business_name' => 'Other Co', 'business_type' => 'Sub', 'business_email' => 'other@example.test', 'address' => '1 Elm', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601']);
    EstimateAiRule::create(['vendor_id' => $other->id, 'text' => 'Another company\'s rule.', 'status' => 'active', 'source' => 'manual']);

    // The kept Hall Bath draft had roofing in it and lacked the GFCI; the estimator fixed both and resized the tile.
    keptDraft($fx, $fx['section'], ['Demo Bathroom', 'Floor Tile', 'Roof Shingles'], 'Hall bath for Debby Hill at 1463 W Winnetka St: floor and shower.');
    EstimateAiDraft::latest('id')->first()->update(['drafted_items' => [
        ['line_item_id' => $c['Demo Bathroom']->id, 'name' => 'Demo Bathroom', 'quantity' => 1, 'unit_type' => 'no_unit', 'cost' => 850.0],
        ['line_item_id' => $c['Floor Tile']->id, 'name' => 'Floor Tile', 'quantity' => 40, 'unit_type' => 'sq.ft.', 'cost' => 23.85],
        ['line_item_id' => $c['Roof Shingles']->id, 'name' => 'Roof Shingles', 'quantity' => 100, 'unit_type' => 'sq.ft.', 'cost' => 12.0],
    ]]);

    $service = new FakeClaudeEstimateService;
    $service->generateEstimate('Hall bathroom: new tile floor and shower.', null, $fx['vendor']->id);
    $req = $service->sentRequests[0];
    $system = $req['system'][0]['text'];
    $user = $req['messages'][0]['content'];

    expect($system)->toContain('You are the estimator at GS Construction, a residential remodeling contractor in Palatine, IL.')
        ->and($system)->not->toContain('Chicago suburbs')
        ->and($user)->toContain("COMPANY ESTIMATING RULES")->toContain('Always add a Misc Framing line')
        ->and($user)->not->toContain('Not yet approved')->not->toContain("Another company")
        ->and($user)->toContain('WHAT THE ESTIMATORS CHANGED')
        ->and($user)->toContain('removed Roof Shingles')
        ->and($user)->toContain('added New GFCI Location × 2')
        ->and($user)->toContain('changed Floor Tile 40 → 45')
        // The enquiry quoted back is redacted like everything else.
        ->and($user)->not->toContain('Winnetka')->toContain('[address]');

    // Evaluating against Hall Bath itself must not show Hall Bath, or its corrections, as the example.
    $service->generateEstimate('Hall bathroom: new tile floor and shower.', null, $fx['vendor']->id, null, [$fx['section']->id]);
    $user = $service->sentRequests[1]['messages'][0]['content'];
    expect($user)->not->toContain('RECENT SECTIONS')->not->toContain('WHAT THE ESTIMATORS CHANGED');
});

it('records every run, marks how it ended, and snapshots the corrections when the estimate is signed', function () {
    [$fx, $component] = draftingGenerator();
    capturingLivewireStream(fn () => $component->call('generate'));

    $draft = EstimateAiDraft::sole();
    expect($draft->inquiry)->toBe('Hall bath: new tile floor and shower.')
        ->and($draft->status)->toBe('drafted')
        ->and($draft->user_id)->toBe($fx['user']->id)
        ->and($draft->model)->toBe('claude-opus-5')
        ->and($draft->usage['output_tokens'])->toBe(300)
        ->and(collect($draft->drafted_items)->pluck('name')->all())->toBe(['Demo Bathroom', 'Floor Tile'])
        ->and((float) $draft->drafted_items[1]['quantity'])->toBe(40.0);
    $component->assertSet('draftId', $draft->id);

    // The estimator resizes the tile and removes the demo, then keeps the draft.
    $component->set('generatedItems.1.quantity', '52')->call('removeItem', 0)->call('finish');
    expect($draft->fresh()->status)->toBe('finished')
        ->and(DraftCorrections::forDraft($draft->fresh()))->toMatchArray([
            'removed' => [['line_item_id' => $fx['catalog']['Demo Bathroom']->id, 'name' => 'Demo Bathroom', 'quantity' => 1.0]],
            'quantity_changed' => [['line_item_id' => $fx['catalog']['Floor Tile']->id, 'name' => 'Floor Tile', 'from' => 40.0, 'to' => 52.0]],
        ]);

    // Signing fixes the snapshot; the estimator adding a line later changes the live diff, not the snapshot.
    EstimateSignature::create([
        'estimate_id' => $fx['estimate']->id, 'user_id' => $fx['user']->id, 'signer_name' => 'Est Imator', 'signer_email' => $fx['user']->email,
        'signature_data' => 'data:image/png;base64,x', 'signature_type' => 'draw', 'ip_address' => '127.0.0.1', 'user_agent' => 'pest', 'document_hash' => 'h', 'signed_at' => now(),
    ]);
    $draft->refresh();
    expect($draft->finalized_at)->not->toBeNull()
        ->and((float) $draft->corrections['quantity_changed'][0]['to'])->toBe(52.0)
        // The snapshot is the whole section as signed: the three lines it had before, plus the kept drafted tile at 52.
        ->and(collect($draft->final_items)->pluck('name')->sort()->values()->all())->toBe(['Demo Bathroom', 'Floor Tile', 'Floor Tile', 'New GFCI Location'])
        ->and(collect($draft->final_items)->firstWhere('quantity', 52)['name'])->toBe('Floor Tile');

    // A discarded draft is remembered as discarded and never taught from.
    [$fx2, $component2] = draftingGenerator();
    capturingLivewireStream(fn () => $component2->call('generate'));
    $component2->call('discardDraft');
    expect(EstimateAiDraft::latest('id')->first()->status)->toBe('discarded');
});

it('sees repricing and rewritten text as corrections too', function () {
    $fx = claudeEstimateFixture();
    $c = $fx['catalog'];
    $draft = keptDraft($fx, $fx['section'], ['Demo Bathroom', 'Floor Tile', 'New GFCI Location']);
    $draft->update(['drafted_items' => collect($draft->drafted_items)->map(fn ($i) => $i + ['quantity' => 1])->map(function ($i) use ($c) {
        $i['quantity'] = $i['name'] === 'Floor Tile' ? 45 : ($i['name'] === 'New GFCI Location' ? 2 : 1);

        return $i;
    })->all()]);

    // Drafted lines carry the catalog's text; here the estimator rewrote one and repriced it.
    foreach (EstimateLineItem::where('section_id', $fx['section']->id)->get() as $line) {
        $line->update(['desc' => $c[$line->name]->desc, 'notes' => $c[$line->name]->notes]);
    }
    EstimateLineItem::where('section_id', $fx['section']->id)->where('name', 'Floor Tile')->update(['cost' => 25.0, 'desc' => 'Rewritten by the estimator']);

    $corrections = DraftCorrections::forDraft($draft);
    expect($corrections['cost_changed'])->toBe([['line_item_id' => $c['Floor Tile']->id, 'name' => 'Floor Tile', 'from' => 23.85, 'to' => 25.0]])
        ->and($corrections['text_edited'])->toBe([['line_item_id' => $c['Floor Tile']->id, 'name' => 'Floor Tile', 'fields' => ['desc']]])
        ->and(DraftCorrections::summarize($corrections))->toBe('repriced Floor Tile $23.85 → $25.00; rewrote the desc of Floor Tile');
});

it('proposes a rule once a correction has recurred, and never the same one twice', function () {
    $fx = claudeEstimateFixture();
    $c = $fx['catalog'];

    // Three kept drafts on sections that ended up without the roofing the model kept adding, and with the GFCI it kept missing whenever it drafted demo.
    foreach ([1, 2, 3] as $n) {
        $section = EstimateSection::create(['estimate_id' => $fx['estimate']->id, 'name' => "Bath {$n}", 'total' => 0]);
        foreach (['Demo Bathroom', 'New GFCI Location'] as $name) {
            EstimateLineItem::create([
                'estimate_id' => $fx['estimate']->id, 'section_id' => $section->id, 'line_item_id' => $c[$name]->id, 'name' => $name,
                'category' => $c[$name]->category, 'sub_category' => $c[$name]->sub_category, 'unit_type' => $c[$name]->unit_type,
                'quantity' => 1, 'cost' => $c[$name]->cost, 'total' => $c[$name]->cost,
            ]);
        }
        keptDraft($fx, $section, ['Demo Bathroom', 'Roof Shingles']);
    }

    $this->artisan('estimates:ai-propose-rules', ['--vendor' => $fx['vendor']->id])
        ->expectsOutputToContain('Leave out "Roof Shingles"')
        ->expectsOutputToContain('2 rules proposed.')
        ->assertExitCode(0);

    $proposed = EstimateAiRule::proposed()->orderBy('id')->get();
    expect($proposed->pluck('text')->all())->toBe([
        'Leave out "Roof Shingles" unless the description asks for it.',
        'Draft "New GFCI Location" whenever "Demo Bathroom" is drafted.',
    ])->and($proposed[0]->evidence['note'])->toBe('Estimators removed it from 3 of 3 drafts that included it.');

    // Dismissed stays dismissed; approved stays approved; nothing is proposed again.
    $proposed[0]->update(['status' => 'dismissed']);
    $proposed[1]->update(['status' => 'active']);
    expect(app(RuleProposer::class)->propose($fx['vendor']->id))->toBe([])
        ->and(EstimateAiRule::count())->toBe(2);
});

it('lets an admin write, approve, dismiss and delete rules from the generator', function () {
    [$fx, $component] = draftingGenerator();
    $proposal = EstimateAiRule::create(['vendor_id' => $fx['vendor']->id, 'text' => 'Proposed one.', 'status' => 'proposed', 'source' => 'proposed', 'fingerprint' => 'removed:1', 'evidence' => ['note' => 'seen 3 times']]);

    $component->call('$refresh')->assertSee('1 proposed')
        ->set('showRules', true)
        ->assertSee('Proposed one.')->assertSee('seen 3 times')
        ->set('newRule', 'Always include a Misc Framing line with a structural header.')
        ->call('addRule')
        ->assertSet('newRule', '')
        ->call('approveRule', $proposal->id);

    expect(EstimateAiRule::active()->pluck('text')->all())->toBe(['Proposed one.', 'Always include a Misc Framing line with a structural header.']);

    $manual = EstimateAiRule::where('source', 'manual')->sole();
    $component->call('deleteRule', $manual->id)->call('deleteRule', $proposal->id);

    // The manual rule is gone; the approved proposal is only dismissed, so it is not proposed again.
    expect(EstimateAiRule::count())->toBe(1)
        ->and($proposal->fresh()->status)->toBe('dismissed');
});

it('scores a draft against the lines that were billed', function () {
    $fx = claudeEstimateFixture();
    $c = $fx['catalog'];
    $actual = EstimateLineItem::where('section_id', $fx['section']->id)->get(); // Demo ×1, Floor Tile ×45, GFCI ×2

    $score = EvaluateEstimateAi::score([
        ['line_item_id' => $c['Demo Bathroom']->id, 'name' => 'Demo Bathroom', 'quantity' => 1, 'cost' => 850],
        ['line_item_id' => $c['Floor Tile']->id, 'name' => 'Floor Tile', 'quantity' => 36, 'cost' => 23.85],
        ['line_item_id' => $c['Roof Shingles']->id, 'name' => 'Roof Shingles', 'quantity' => 10, 'cost' => 12],
    ], $actual);

    expect($score['precision'])->toBe(round(2 / 3, 4))
        ->and($score['recall'])->toBe(round(2 / 3, 4))
        ->and($score['quantity_mape'])->toBe(0.2)
        ->and($score['missed'])->toBe(['New GFCI Location'])
        ->and($score['extra'])->toBe(['Roof Shingles']);
});

it('evaluates recorded drafts against their sections with the answer key hidden, and writes a report', function () {
    Storage::fake('local');
    $fx = claudeEstimateFixture();
    $c = $fx['catalog'];
    keptDraft($fx, $fx['section'], ['Demo Bathroom'], 'Hall bath: new tile floor.');

    $fake = new FakeClaudeEstimateService([
        'text' => json_encode(['reasoning' => 'ok', 'line_items' => [
            ['line_item_id' => $c['Demo Bathroom']->id, 'quantity' => 1],
            ['line_item_id' => $c['Floor Tile']->id, 'quantity' => 45],
        ]]),
        'stop_reason' => 'end_turn',
    ]);
    app()->instance(EstimateAIService::class, $fake);

    $this->artisan('estimates:ai-eval', ['--vendor' => $fx['vendor']->id, '--dry-run' => true])
        ->expectsOutputToContain('1 case for vendor')
        ->assertExitCode(0);
    expect($fake->sentRequests)->toBe([]);

    $this->artisan('estimates:ai-eval', ['--vendor' => $fx['vendor']->id, '--report' => 'estimate-ai/test-eval.json'])
        ->expectsOutputToContain('Mean precision 100% · recall 67% · F1 80%')
        ->assertExitCode(0);

    // Hall Bath was the answer key, so it was not among the examples.
    expect($fake->sentRequests)->toHaveCount(1)
        ->and($fake->sentRequests[0]['messages'][0]['content'])->not->toContain('RECENT SECTIONS');

    $report = json_decode(Storage::disk('local')->get('estimate-ai/test-eval.json'), true);
    expect($report['summary']['cases'])->toBe(1)
        ->and($report['cases'][0]['missed'])->toBe(['New GFCI Location']);
});
