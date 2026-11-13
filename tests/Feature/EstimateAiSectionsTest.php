<?php

use App\Jobs\DraftEstimateSections;
use App\Livewire\Estimates\EstimateAIGenerator;
use App\Livewire\Estimates\EstimateShow;
use App\Models\EstimateAiDraft;
use App\Models\EstimateAiRun;
use App\Models\EstimateLineItem;
use App\Models\EstimateSection;
use App\Models\EstimateSignature;
use App\Models\LineItem;
use App\Services\EstimateAI\EstimateSectionsDrafter;
use App\Services\EstimateAI\SectionStreamParser;
use App\Services\Estimates\FloorplanParser;
use App\Services\EstimateAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/estimate-ai-fixtures.php';

/**
 * The generator drafts a whole estimate, section by section (2026-11-13):
 * a room scan's rooms are offered as sections the moment it is uploaded,
 * and each section's lines are sized from its own room. The fixture is a
 * made-up four-room scan in the shape Polycam exports.
 */
function houseScan(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('house.csv', file_get_contents(base_path('tests/fixtures/polycam-house.csv')));
}

/** The fixture company with baseboard in its catalog, an admin signed in, and the generator open on its estimate. */
function sectionsGenerator(): array
{
    $fx = estimateAdmin(claudeEstimateFixture());
    $fx['catalog']['Baseboard Install'] = LineItem::withoutGlobalScopes()->create([
        'name' => 'Baseboard Install', 'category' => 'Carpentry', 'sub_category' => 'Trim', 'unit_type' => 'li.ft.', 'cost' => 9,
        'desc' => 'Catalog description of Baseboard Install', 'belongs_to_vendor_id' => $fx['vendor']->id,
    ]);

    $component = Livewire::test(EstimateAIGenerator::class, ['estimate' => $fx['estimate']])
        ->set('inquiry', 'Remodel the kitchen and the hall bath, new baseboard in the living room.');

    return [$fx, $component];
}

function rowNamed($component, string $name): int
{
    return array_search($name, array_column($component->get('sectionRows'), 'name'), true);
}

function sectionNamed(array $fx, string $name): ?EstimateSection
{
    return EstimateSection::query()->where('estimate_id', $fx['estimate']->id)->where('name', $name)->first();
}

function linesOf(?EstimateSection $section): array
{
    return EstimateLineItem::query()->where('section_id', $section?->id)->orderBy('id')->get()
        ->map(fn (EstimateLineItem $line) => [$line->name, (float) $line->quantity])->all();
}

it('reads each room\'s own cabinets, windows, doors and appliances out of a scan', function () {
    $rooms = collect(app(FloorplanParser::class)->rooms(houseScan()))->keyBy('name');

    expect($rooms->keys()->all())->toBe(['Living Room (1)', 'Kitchen', 'Hall Bath', 'Living Room (3)'])
        ->and($rooms['Kitchen'])->toMatchArray([
            'floor_sqft' => 234.4, 'wall_sqft' => 333.9, 'perimeter_ft' => 64.08, 'dimensions' => "17' 4\" x 12' 10\"",
            'window_count' => 2, 'window_area_sqft' => 49.59, 'window_casing_lf' => 42.33,
            // Two deep boxes are the base run, the shallow one an upper, the 9' one a pantry; the counter also covers the dishwasher.
            'cabinet_count' => 4, 'base_cabinet_lf' => 11.83, 'upper_cabinet_lf' => 2.67, 'tall_cabinet_lf' => 3.83, 'countertop_lf' => 13.83,
            // The bar stools are furniture, not something an estimate hooks up.
            'appliances' => ['ovens' => 1, 'sinks' => 1, 'dishwashers' => 1],
        ])
        ->and($rooms['Hall Bath'])->toMatchArray(['floor_sqft' => 50.3, 'door_count' => 1, 'door_casing_lf' => 17.0, 'appliances' => ['sinks' => 1, 'toilets' => 1]])
        // A sloped ceiling keeps both ends.
        ->and($rooms['Living Room (1)'])->toMatchArray(['ceiling_height_ft' => 9.75, 'ceiling_height_max_ft' => 14.33])
        ->and($rooms['Living Room (1)'])->not->toHaveKey('sliver')
        // No rectangle fits inside it: a sliver between rooms, shown by its outer size.
        ->and($rooms['Living Room (3)'])->toMatchArray(['sliver' => true, 'dimensions' => "15' 9\" x 0' 11\""]);

    // A picture or a PDF is measured as a whole: it names no rooms (and is not sent anywhere to find out).
    expect(app(FloorplanParser::class)->rooms(UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf')))->toBe([]);
});

it('lists a scan\'s rooms as sections the moment it is uploaded', function () {
    [$fx, $component] = sectionsGenerator();

    // Before any upload: only what is on the estimate, unticked.
    expect($component->get('sectionRows'))->toHaveCount(1)
        ->and($component->get('sectionRows.0'))->toMatchArray(['kind' => 'existing', 'name' => 'Hall Bath', 'include' => false, 'section_id' => $fx['section']->id]);

    $component->set('newFloorplans', [houseScan()]);

    $rows = collect($component->get('sectionRows'));
    // Every room the scan measured is ticked (2026-11-13: the last one, left unticked, read as a bug).
    expect($rows->where('kind', 'room')->pluck('include', 'name')->all())->toBe([
        'Living Room (1)' => true, 'Kitchen' => true, 'Hall Bath' => true, 'Living Room (3)' => true,
    ])
        // The sliver beside the living room starts out as part of it; the real rooms stand on their own.
        ->and($rows->firstWhere('name', 'Living Room (3)')['merged_into'])->toBe($rows->firstWhere('name', 'Living Room (1)')['key'])
        ->and($rows->where('kind', 'room')->whereNull('merged_into')->pluck('name')->all())->toBe(['Living Room (1)', 'Kitchen', 'Hall Bath'])
        ->and($rows->firstWhere('name', 'Kitchen')['detail'])->toBe("234.4 sq.ft. · 17' 4\" x 12' 10\" · 4 cabinets")
        ->and($rows->where('kind', 'existing')->pluck('name')->all())->toBe(['Hall Bath']);

    $component->assertSee('437.9 sq.ft. · 2 rooms together')
        ->assertSee('Split out')
        ->assertSee('234.4 sq.ft.')
        ->assertSee('New section')
        // The scanned Hall Bath is the Hall Bath already on the estimate.
        ->assertSee('Adds to this section')
        ->assertSee('Already on this estimate');

    // A renamed, unticked row keeps both through another upload; a removed scan takes its rooms with it.
    $component->set('sectionRows.'.rowNamed($component, 'Kitchen').'.name', 'Kitchen & Pantry')
        ->set('sectionRows.'.rowNamed($component, 'Living Room (1)').'.include', false)
        ->set('newFloorplans', [UploadedFile::fake()->createWithContent('garage.csv', "Room,Description,Value\nGarage,Floor area [ft^2],400.0\nKitchen,Floor area [ft^2],60.0\n")]);

    $rows = collect($component->get('sectionRows'));
    expect($rows->where('kind', 'room')->pluck('include', 'name')->all())->toBe([
        'Living Room (1)' => false, 'Kitchen & Pantry' => true, 'Hall Bath' => true, 'Living Room (3)' => true,
        'Garage' => true, 'Kitchen' => true,
    ])
        // The sliver is still with its room after the second upload.
        ->and($rows->firstWhere('name', 'Living Room (3)')['merged_into'])->toBe($rows->firstWhere('name', 'Living Room (1)')['key'])
        // With several layouts each room says which one it is on.
        ->and($rows->firstWhere('name', 'Garage')['detail'])->toBe('Floor 2 · 400 sq.ft.');

    $component->call('removeFloorplan', 0);
    expect(collect($component->get('sectionRows'))->pluck('name')->all())->toBe(['Garage', 'Kitchen', 'Hall Bath']);
});

it('drafts each listed section from its own room, making the section as its first line lands', function () {
    [$fx, $component] = sectionsGenerator();
    $c = $fx['catalog'];
    // The empty, unnamed section a new estimate starts with.
    $blank = EstimateSection::create(['estimate_id' => $fx['estimate']->id, 'name' => null, 'total' => 0, 'order' => 5]);

    // The sliver beside the living room is drafted as part of it without anyone saying so.
    $component->set('newFloorplans', [houseScan()]);

    $fake = claudeDrafts([
        ['S1', 'Living Room (1)', 'New baseboard around the living room.', [[$c['Baseboard Install'], 10]]],
        ['S2', 'Kitchen', 'Full kitchen remodel.', [[$c['Floor Tile'], 100], [$c['Cement Boards'], 8], [$c['Baseboard Install'], 5]]],
        ['S3', 'Hall Bath', 'Hall bath, floor and walls tiled.', [[$c['Cement Boards'], 3], [$c['Floor Tile'], 1]]],
        ['NEW', 'Code Compliance', 'Outlets brought up to code.', [[$c['New GFCI Location'], 2]]],
    ], 'Kitchen and hall bath remodel with living room trim.');

    $component->call('generate')->assertOk();

    // What Claude was told: each section with its key and its own room's numbers.
    $request = $fake->sentRequests[0];
    $prompt = $request['messages'][0]['content'];
    expect($prompt)->toContain("SECTIONS OF THIS ESTIMATE (the estimator's own list")
        ->toContain("S1 — Living Room (1)\n")
        ->toContain('"scanned_as":"Living Room (1) + Living Room (3)"')
        ->toContain('"floor_sqft":437.9')
        ->toContain("S2 — Kitchen\n")
        ->toContain('"countertop_lf":13.83')
        ->toContain('"appliances":{"ovens":1,"sinks":1,"dishwashers":1}')
        ->toContain('S3 — Hall Bath (already on the estimate: add to it)')
        ->toContain('WHOLE-PLAN MEASUREMENTS')
        // The rooms are with their sections, not listed a second time.
        ->not->toContain('"rooms"')
        // Nothing of the scan's name or address travels.
        ->not->toContain('Sample House')->not->toContain('Example Ln')
        ->and($request['system'][0]['text'])->toContain('This draft covers the whole estimate')
        ->and($request['outputConfig']['format']['schema']['properties']['sections']['items']['properties']['key']['enum'])->toBe(['S1', 'S2', 'S3', 'NEW'])
        ->and($request['maxTokens'])->toBe(32000)
        ->and($request['thinking'])->toBe(['type' => 'adaptive', 'display' => 'summarized']);

    // Each line keeps the quantity Claude sized it at — never forced to the room's floor or walls
    // (2026-11-13: a ~40 sq.ft. pantry tile allowance became the whole 286 sq.ft. kitchen).
    $livingRoom = sectionNamed($fx, 'Living Room (1)');
    expect($livingRoom->id)->toBe($blank->id)
        ->and(linesOf($livingRoom))->toBe([['Baseboard Install', 10.0]])
        ->and(linesOf(sectionNamed($fx, 'Kitchen')))->toBe([['Floor Tile', 100.0], ['Cement Boards', 8.0], ['Baseboard Install', 5.0]])
        ->and(array_slice(linesOf($fx['section']), 3))->toBe([['Cement Boards', 3.0], ['Floor Tile', 1.0]])
        ->and(linesOf(sectionNamed($fx, 'Code Compliance')))->toBe([['New GFCI Location', 2.0]])
        ->and((float) sectionNamed($fx, 'Kitchen')->total)->toEqualWithDelta(100 * 23.85 + 8 * 38 + 5 * 9, 0.01);

    // Each section keeps how its own scope was read; the enquiry, which covers the whole job, stays with the run.
    expect(sectionNamed($fx, 'Kitchen')->ai_scope)->toBe('Full kitchen remodel.')
        ->and(sectionNamed($fx, 'Kitchen')->ai_inquiry)->toBeNull()
        ->and($fx['section']->fresh()->ai_scope)->toBe('Hall bath, floor and walls tiled.');

    $run = EstimateAiRun::sole();
    expect($run->status)->toBe('finished')
        ->and($run->user_id)->toBe($fx['user']->id)
        ->and($run->reasoning)->toBe('Kitchen and hall bath remodel with living room trim.')
        ->and($run->usage['output_tokens'])->toBe(300)
        ->and($run->plan['listed'])->toBeTrue()
        ->and(collect($run->sections)->pluck('origin', 'name')->all())->toBe([
            'Living Room (1)' => 'named', 'Kitchen' => 'created', 'Hall Bath' => 'existing', 'Code Compliance' => 'created',
        ]);

    // One draft per section in the generator's memory, each tied to the run.
    $drafts = EstimateAiDraft::query()->orderBy('id')->get();
    expect($drafts)->toHaveCount(4)
        ->and($drafts->pluck('run_id')->unique()->all())->toBe([$run->id])
        ->and($drafts->pluck('status')->unique()->all())->toBe(['drafted'])
        ->and($drafts[1]->section_id)->toBe(sectionNamed($fx, 'Kitchen')->id)
        ->and($drafts[1]->reasoning)->toBe('Full kitchen remodel.')
        ->and($drafts[1]->floorplan['floor_sqft'])->toBe(234.4)
        ->and(collect($drafts[1]->drafted_items)->pluck('quantity', 'name')->all())->toEqual(['Floor Tile' => 100.0, 'Cement Boards' => 8.0, 'Baseboard Install' => 5.0])
        ->and($drafts[3]->floorplan)->toBeNull();

    // The review lists the lines under their sections.
    $component->assertSet('showPreview', true)
        ->assertCount('generatedItems', 7)
        ->assertSet('generatedItems.1.section_name', 'Kitchen')
        ->assertSee('7 line items drafted into 4 sections')
        ->assertSee('Code Compliance')
        ->assertSee('New section')
        // Each section says how its scope was read.
        ->assertSee('Outlets brought up to code.')
        ->assertDontSee('Nothing was drafted for');

    $component->call('finish')->assertOk();
    expect(EstimateAiDraft::query()->pluck('status')->unique()->all())->toBe(['finished']);
});

it('leaves a listed section the work does not touch off the estimate, and says so', function () {
    [$fx, $component] = sectionsGenerator();
    $c = $fx['catalog'];

    $component->call('addSectionRow')->call('addSectionRow');
    $rows = $component->get('sectionRows');
    $component->set('sectionRows.'.(count($rows) - 2).'.name', 'Pantry')->set('sectionRows.'.(count($rows) - 1).'.name', 'Closet');

    claudeDrafts([['S1', 'Pantry', 'New pantry floor.', [[$c['Floor Tile'], 30]]], ['S2', 'Closet', 'Nothing asked for.', []]]);

    $component->call('generate')
        ->assertSee('1 line item drafted into Pantry')
        ->assertSee('Nothing was drafted for Closet');

    expect(linesOf(sectionNamed($fx, 'Pantry')))->toBe([['Floor Tile', 30.0]])
        ->and(sectionNamed($fx, 'Closet'))->toBeNull()
        ->and(EstimateAiDraft::count())->toBe(1);

    // A typed row can be taken away again; a scanned or existing one cannot be removed this way.
    $component->call('discardDraft');
    $pantry = collect($component->get('sectionRows'))->firstWhere('name', 'Pantry');
    $component->call('removeSectionRow', $pantry['key'])->call('removeSectionRow', 'section-'.$fx['section']->id);
    expect(collect($component->get('sectionRows'))->pluck('name')->all())->toBe(['Closet', 'Hall Bath']);
});

it('splits the work into sections itself when none is ticked, adding to what the estimate has', function () {
    [$fx, $component] = sectionsGenerator();
    $c = $fx['catalog'];

    $fake = claudeDrafts([
        ['S1', 'Hall Bath', 'More outlets in the hall bath.', [[$c['New GFCI Location'], 1]]],
        ['NEW', 'Kitchen', 'Kitchen floor.', [[$c['Floor Tile'], 120]]],
    ]);

    $component->call('generate')->assertSee('2 line items drafted into 2 sections');

    $prompt = $fake->sentRequests[0]['messages'][0]['content'];
    expect($prompt)->toContain("Split the work into sections the way this company's past estimates do")
        ->toContain('S1 — Hall Bath (already on the estimate: add to it)')
        ->not->toContain("the estimator's own list")
        ->and(EstimateAiRun::sole()->plan['listed'])->toBeFalse()
        ->and(array_slice(linesOf($fx['section']), 3))->toBe([['New GFCI Location', 1.0]])
        ->and(linesOf(sectionNamed($fx, 'Kitchen')))->toBe([['Floor Tile', 120.0]]);
});

it('discards a run: its lines, the sections it made, and the name it gave an empty one', function () {
    [$fx, $component] = sectionsGenerator();
    $c = $fx['catalog'];
    $blank = EstimateSection::create(['estimate_id' => $fx['estimate']->id, 'name' => null, 'total' => 0, 'order' => 5]);

    $component->call('addSectionRow')->call('addSectionRow')->call('addSectionRow');
    $first = count($component->get('sectionRows')) - 3;
    $component->set("sectionRows.{$first}.name", 'Foyer')->set('sectionRows.'.($first + 1).'.name', 'Kitchen')->set('sectionRows.'.($first + 2).'.name', 'Laundry');
    tickSection($component, $fx['section']->id);

    // Keys follow the list: the Hall Bath already on the estimate, then the three typed sections.
    claudeDrafts([
        ['S2', 'Foyer', 'Foyer floor.', [[$c['Floor Tile'], 80]]],
        ['S3', 'Kitchen', 'Kitchen floor.', [[$c['Floor Tile'], 120]]],
        ['S4', 'Laundry', 'Laundry floor.', [[$c['Floor Tile'], 45]]],
        ['S1', 'Hall Bath', 'One more outlet.', [[$c['New GFCI Location'], 1]]],
    ]);
    $component->call('generate')->assertSee('4 line items drafted into 4 sections');

    expect(sectionNamed($fx, 'Foyer')->id)->toBe($blank->id);

    // The estimator has already put a line of their own in the new Kitchen, and binned the Laundry's the restorable way.
    $kitchen = sectionNamed($fx, 'Kitchen');
    EstimateLineItem::create([
        'estimate_id' => $fx['estimate']->id, 'section_id' => $kitchen->id, 'line_item_id' => $c['Full Drywall']->id, 'name' => 'Full Drywall',
        'category' => 'Drywall', 'sub_category' => 'Install', 'unit_type' => 'pieces', 'quantity' => 2, 'cost' => 205, 'total' => 410,
    ]);
    $laundry = sectionNamed($fx, 'Laundry');
    EstimateLineItem::query()->where('section_id', $laundry->id)->first()->delete();

    $component->call('discardDraft')->assertOk()->assertSet('showPreview', false)->assertSet('generatedItems', [])->assertSet('runId', null);

    expect($blank->fresh()->name)->toBeNull()
        ->and(linesOf($blank))->toBe([])
        // Made by the draft and empty again: gone for good, not parked among the restorable sections.
        ->and(EstimateSection::withTrashed()->find($laundry->id))->toBeNull()
        // The estimator's own line keeps its section standing.
        ->and(linesOf($kitchen->fresh()))->toBe([['Full Drywall', 2.0]])
        ->and(linesOf($fx['section']))->toHaveCount(3)
        ->and((float) $fx['section']->fresh()->total)->toBe(850.0 + 1073.25 + 460.0)
        ->and($fx['section']->fresh()->ai_scope)->toBeNull()
        ->and(EstimateAiDraft::query()->pluck('status')->unique()->all())->toBe(['discarded']);

    // The description and the typed sections are still there to try again.
    expect(collect($component->get('sectionRows'))->where('kind', 'manual')->pluck('name')->all())->toBe(['Foyer', 'Kitchen', 'Laundry'])
        ->and($component->get('inquiry'))->not->toBe('');
});

it('drafts in a queued job and follows it, picking a running draft back up when the window reopens', function () {
    Queue::fake();
    [$fx, $component] = sectionsGenerator();
    $c = $fx['catalog'];
    tickSection($component, $fx['section']->id);
    claudeDrafts([['S1', 'Hall Bath', 'Hall bath floor.', [[$c['Floor Tile'], 40]]]], 'Hall bath.');

    $component->call('generate')
        ->assertSet('isGenerating', true)
        ->assertSet('showPreview', false)
        ->assertSee('Starting…')
        ->assertSeeHtml('wire:poll.1s="pollDraft"');

    $run = EstimateAiRun::sole();
    expect($run->status)->toBe('queued');
    Queue::assertPushed(DraftEstimateSections::class, fn (DraftEstimateSections $job) => $job->runId === $run->id && $job->queue === 'estimate-ai' && $job->timeout === 900);
    $component->assertSet('runId', $run->id);

    // Another window on the same estimate — the page was reloaded — follows the same draft and cannot start a second.
    $other = Livewire::test(EstimateAIGenerator::class, ['estimate' => $fx['estimate']])
        ->dispatch('openAIGenerator')
        ->assertSet('isGenerating', true)
        ->assertSet('runId', $run->id)
        ->set('inquiry', 'Something else entirely, typed in the other window.')
        ->call('generate');
    expect(EstimateAiRun::count())->toBe(1);
    Queue::assertPushed(DraftEstimateSections::class, 1);

    // The worker takes the job; the windows see it at their next poll.
    (new DraftEstimateSections($run->id))->handle(app(EstimateSectionsDrafter::class));

    $component->call('pollDraft')
        ->assertSet('isGenerating', false)
        ->assertSet('showPreview', true)
        ->assertSee('1 line item drafted into Hall Bath')
        ->assertDontSeeHtml('wire:poll');
    $other->call('pollDraft')->assertSet('showPreview', true);

    // Taken twice (a retried job), it drafts once.
    (new DraftEstimateSections($run->id))->handle(app(EstimateSectionsDrafter::class));
    expect(array_slice(linesOf($fx['section']), 3))->toBe([['Floor Tile', 40.0]]);
});

it('stops when asked, keeping what was drafted so far', function () {
    // Asked before the worker took the job: nothing is drafted.
    Queue::fake();
    [$fx, $component] = sectionsGenerator();
    $c = $fx['catalog'];
    tickSection($component, $fx['section']->id);
    $fake = claudeDrafts([
        ['S1', 'Hall Bath', 'Hall bath floor.', [[$c['Floor Tile'], 40], [$c['Cement Boards'], 5], [$c['New GFCI Location'], 2]]],
        ['NEW', 'Kitchen', 'Kitchen floor.', [[$c['Floor Tile'], 120]]],
    ]);

    $component->call('generate')->call('stopDraft');
    $run = EstimateAiRun::sole();
    expect($run->status)->toBe('stopping');

    (new DraftEstimateSections($run->id))->handle(app(EstimateSectionsDrafter::class));
    expect($run->fresh()->status)->toBe('stopped')->and($fake->sentRequests)->toBe([]);

    $component->call('pollDraft')
        ->assertSet('isGenerating', false)
        ->assertSet('showPreview', false)
        ->assertSet('runId', null)
        ->assertSee('You stopped the draft.');

    // Asked while the reply is arriving: the first line stays, the rest never lands.
    $fake->beforeChunk = function () use ($fx) {
        if (EstimateLineItem::query()->where('section_id', $fx['section']->id)->count() > 3) {
            EstimateAiRun::query()->where('status', 'drafting')->update(['status' => 'stopping']);
        }
    };

    $component->call('generate');
    $second = EstimateAiRun::query()->latest('id')->first();
    (new DraftEstimateSections($second->id))->handle(app(EstimateSectionsDrafter::class));

    expect($second->fresh()->status)->toBe('stopped')
        ->and(array_slice(linesOf($fx['section']), 3))->toBe([['Floor Tile', 40.0]])
        ->and(sectionNamed($fx, 'Kitchen'))->toBeNull();

    $component->call('pollDraft')
        ->assertSet('showPreview', true)
        ->assertSee('1 line item drafted into Hall Bath')
        ->assertSee('The draft stopped early')
        ->assertSee('You stopped the draft.');
});

it('says so when a draft dies with its worker, and keeps what had landed', function () {
    [$fx, $component] = sectionsGenerator();

    // The job failed outright (timed out, worker killed).
    $run = EstimateAiRun::create(['vendor_id' => $fx['vendor']->id, 'estimate_id' => $fx['estimate']->id, 'status' => 'drafting', 'inquiry' => 'Kitchen.', 'started_at' => now(), 'heartbeat_at' => now()]);
    (new DraftEstimateSections($run->id))->failed(new RuntimeException('killed'));
    expect($run->fresh()->status)->toBe('failed')->and($run->fresh()->error)->toContain('stopped before it finished');

    // A run that is merely thinking — heard from a minute ago — is followed when the window opens.
    $thinking = EstimateAiRun::create(['vendor_id' => $fx['vendor']->id, 'estimate_id' => $fx['estimate']->id, 'status' => 'drafting', 'inquiry' => 'Kitchen.', 'started_at' => now()->subMinutes(2), 'heartbeat_at' => now()->subMinute()]);
    $component->dispatch('openAIGenerator')->assertSet('isGenerating', true)->assertSet('runId', $thinking->id)
        ->assertSee('Working out sections and quantities…');

    // Then nobody hears from it for five minutes: its worker is gone, and the window says so at its next poll.
    $this->travel(6)->minutes();
    $component->call('pollDraft')
        ->assertSet('isGenerating', false)
        ->assertSet('runId', null)
        ->assertSee('The draft stopped before it finished');
    expect($thinking->fresh()->status)->toBe('failed');

    // A run that went silent is not offered to follow, and does not block a new draft.
    $silent = EstimateAiRun::create(['vendor_id' => $fx['vendor']->id, 'estimate_id' => $fx['estimate']->id, 'status' => 'drafting', 'inquiry' => 'Kitchen.', 'started_at' => now()->subMinutes(12), 'heartbeat_at' => now()->subMinutes(6)]);
    $component->dispatch('openAIGenerator')->assertSet('isGenerating', false)->assertSet('runId', null);
});

it('turns a cut-off or refused whole-estimate draft into a plain message and keeps what had landed', function () {
    [$fx, $component] = sectionsGenerator();
    $c = $fx['catalog'];
    tickSection($component, $fx['section']->id);

    // Cut off mid-section: the reply ends before the JSON does.
    $whole = sectionsReply([['S1', 'Hall Bath', 'Hall bath floor.', [[$c['Floor Tile'], 40], [$c['Cement Boards'], 5]]]])['text'];
    app()->instance(EstimateAIService::class, new FakeClaudeEstimateService(['text' => substr($whole, 0, strpos($whole, '},{') + 1), 'stop_reason' => 'max_tokens']));

    $component->call('generate')->assertSet('showPreview', true)->assertSee('The draft stopped early')->assertSee('cut off');
    expect(array_slice(linesOf($fx['section']), 3))->toBe([['Floor Tile', 40.0]])
        ->and(EstimateAiRun::sole()->status)->toBe('failed');

    // Refused: nothing lands, and the form says why.
    $component->call('finish');
    tickSection($component, $fx['section']->id)->set('inquiry', 'Hall bath: new tile floor and shower.');
    app()->instance(EstimateAIService::class, new FakeClaudeEstimateService(['text' => '', 'stop_reason' => 'refusal']));

    $component->call('generate')->assertSet('showPreview', false)->assertSee('Claude declined to draft this estimate');
    expect(linesOf($fx['section']))->toHaveCount(4);
});

it('hands sections, their lines and their closings over in order however the text is cut up', function () {
    $json = json_encode(['reasoning' => 'A job with "quotes", {braces} and [brackets].', 'sections' => [
        ['key' => 'S1', 'name' => 'Kitchen', 'scope' => 'Counters {and} a "floor".', 'line_items' => [['line_item_id' => 4, 'quantity' => 12.5], ['line_item_id' => 9, 'quantity' => 1]]],
        ['key' => 'NEW', 'name' => 'Code Compliance', 'scope' => '', 'line_items' => []],
        ['key' => 'S2', 'name' => 'Hall Bath', 'scope' => 'Tile.', 'line_items' => [['line_item_id' => 7, 'quantity' => 3]]],
    ]]);

    foreach ([1, 3, 7, 64, strlen($json)] as $size) {
        $parser = new SectionStreamParser;
        $events = collect(str_split($json, $size))->flatMap(fn (string $chunk) => $parser->push($chunk));

        expect($events->map(fn (array $e) => $e['event'].':'.$e['index'].(isset($e['item']) ? ':'.$e['item']['line_item_id'] : ''))->all())->toBe([
            'section:0', 'item:0:4', 'item:0:9', 'section_end:0',
            'section:1', 'section_end:1',
            'section:2', 'item:2:7', 'section_end:2',
        ])
            ->and($events[0])->toMatchArray(['key' => 'S1', 'name' => 'Kitchen', 'scope' => 'Counters {and} a "floor".'])
            ->and($events[1]['item'])->toBe(['line_item_id' => 4, 'quantity' => 12.5])
            ->and($events[4])->toMatchArray(['key' => 'NEW', 'name' => 'Code Compliance', 'scope' => null])
            ->and($parser->finished())->toBeTrue();
    }

    // A section that names itself only after its items is held back and handed over whole, never under a guessed name.
    $late = '{"sections":[{"line_items":[{"line_item_id":4,"quantity":2}],"scope":"Late.","name":"Kitchen","key":"S1"}],"reasoning":"x"}';
    $parser = new SectionStreamParser;
    $events = collect(str_split($late, 5))->flatMap(fn (string $chunk) => $parser->push($chunk))->all();

    expect(array_column($events, 'event'))->toBe(['section', 'item', 'section_end'])
        ->and($events[0])->toMatchArray(['key' => 'S1', 'name' => 'Kitchen'])
        ->and($events[1]['item']['line_item_id'])->toBe(4);
});

/**
 * A past section of this company with these lines, on its own estimate.
 *
 * @param  list<array{0: string, 1: int|float}>  $lines
 */
function pastSection(array $fx, string $name, array $lines, ?string $project = null): EstimateSection
{
    $estimate = $fx['estimate'];
    if ($project !== null) {
        $estimate = \App\Models\Estimate::withoutGlobalScopes()->create([
            'project_id' => \App\Models\Project::query()->create([
                'project_name' => $project, 'client_id' => $fx['estimate']->project->client_id,
                'address' => '1 Oak St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
            ])->id,
            'belongs_to_vendor_id' => $fx['vendor']->id,
        ]);
    }

    $section = EstimateSection::create(['estimate_id' => $estimate->id, 'name' => $name, 'total' => 0]);
    foreach ($lines as [$item, $quantity]) {
        $line = $fx['catalog'][$item];
        EstimateLineItem::create([
            'estimate_id' => $estimate->id, 'section_id' => $section->id, 'line_item_id' => $line->id, 'name' => $item,
            'category' => $line->category, 'sub_category' => $line->sub_category, 'unit_type' => $line->unit_type,
            'quantity' => $quantity, 'cost' => $line->cost, 'total' => $quantity * $line->cost,
        ]);
    }

    return $section;
}

it('shows each section this company\'s own past sections of the same or a similar name', function () {
    config()->set('services.openai.api_key', null);
    $fx = claudeEstimateFixture();

    // 2026-11-13, estimate 296: the drafted Kitchen was shown no past kitchen and came back with 4 lines.
    $kitchens = [
        pastSection($fx, 'Kitchen', [['Demo Bathroom', 1], ['Full Drywall', 6], ['New GFCI Location', 4]], 'Elm kitchen'),
        pastSection($fx, 'Kitchen', [['Demo Bathroom', 1], ['Floor Tile', 120]], 'Oak kitchen'),
        pastSection($fx, 'kitchen ', [['Full Drywall', 3]], 'Pine kitchen'),
        pastSection($fx, 'Kitchen', [['New GFCI Location', 2]], 'Ash kitchen'),
    ];
    $combined = pastSection($fx, 'Kitchen / Family Rm', [['Full Drywall', 8]], 'Maple open plan');
    $laundry = pastSection($fx, 'Laundry Room', [['Floor Tile', 40], ['Cement Boards', 2]], 'Birch laundry');
    $mudroom = pastSection($fx, 'Mud Room', [['Floor Tile', 30]], 'Cedar mudroom');
    pastSection($fx, 'Change Order', [['Floor Tile', 5]], 'Elm kitchen 2');
    // On the estimate being drafted: never its own example.
    $ownKitchen = pastSection($fx, 'Kitchen', [['Roof Shingles', 1]]);

    $fake = new FakeClaudeEstimateService(sectionsReply([]));
    $fake->generateSections('Remodel the kitchen; new laundry with tile; patch the hall bath.', [
        ['key' => 'S1', 'name' => 'Kitchen'],
        ['key' => 'S2', 'name' => 'Laundry'],
        ['key' => 'S3', 'name' => 'Hall Bath'],
        // Unnamed by the scan: nothing to look up.
        ['key' => 'S4', 'name' => 'New room (2)'],
        // Dropped: not a usable key, no name.
        ['key' => 'NEW', 'name' => 'Sneaky'],
        ['key' => 'S5', 'name' => '  '],
    ], true, null, $fx['vendor']->id, [], [$ownKitchen->id]);

    $prompt = $fake->sentRequests[0]['messages'][0]['content'];
    $under = fn (string $key) => \Illuminate\Support\Str::betweenFirst($prompt, "\n{$key} — ", "\n\n");

    // Three namesakes for the kitchen: exact names first, then the job most like this one; the combined room only after them.
    expect(substr_count($under('S1'), "\n  - Kitchen — "))->toBe(3)
        ->and($under('S1'))->toContain("This company's past \"Kitchen\" sections:")
        ->toContain('Kitchen — Elm Kitchen')->toContain('Kitchen — Oak Kitchen')
        ->toContain('Demo Bathroom × 1; Full Drywall × 6 pieces; New GFCI Location × 4 pieces')
        ->not->toContain('Kitchen / Family Rm')->not->toContain('Roof Shingles')
        // Laundry finds the laundry room and the mudroom.
        ->and($under('S2'))->toContain('Laundry Room — Birch Laundry')->toContain('Mud Room — Cedar Mudroom')->not->toContain('Kitchen —')
        ->and($under('S3'))->toContain('Hall Bath — Hall Bath')
        ->and($under('S4'))->toContain('(no past section of this name at this company)')
        ->and($prompt)->not->toContain('Change Order')->not->toContain('Sneaky')
        // Shown under its section, so not again among the job's other examples.
        ->and(substr_count($prompt, 'Laundry Room — Birch Laundry'))->toBe(1)
        ->and($fake->sentRequests[0]['system'][0]['text'])->toContain('They are its checklist for that room')
        ->and($fake->sentRequests[0]['outputConfig']['format']['schema']['properties']['sections']['items']['properties']['key']['enum'])->toBe(['S1', 'S2', 'S3', 'S4', 'NEW']);

    // A room that is a kitchen and a family room in one is not a kitchen's namesake: asked for ten, only the four kitchens come back.
    $retriever = app(\App\Services\EstimateAI\SectionRetriever::class);
    $found = $retriever->namesakes($fx['vendor']->id, ['S1' => 'Kitchen'], 'kitchen', 10, [$ownKitchen->id]);
    expect(collect($found['S1'])->pluck('section.id')->sort()->values()->all())->toBe(collect($kitchens)->pluck('id')->sort()->values()->all())
        ->and(collect($found['S1'])->pluck('section.id')->all())->not->toContain($combined->id);
});

it('reads section names as rooms', function (string $wanted, string $past, bool $alike) {
    $score = \App\Services\EstimateAI\SectionRetriever::nameMatch(
        \App\Services\EstimateAI\SectionRetriever::nameTokens($wanted),
        \App\Services\EstimateAI\SectionRetriever::nameTokens($past),
    );

    expect($score > 0)->toBe($alike);
})->with([
    ['Kitchen', 'kitchen', true],
    ['Kitchen', 'Kitchen Area', true],
    ['Kitchen', 'Basement Kitchen', true],
    ['Laundry', 'Mud Room', true],
    ['Dining Room 1', 'Dining Room', true],
    ['Sitting Room', 'Family Room', true],
    ['Primary Bath', 'Master Bath', true],
    ['Hall Bath', 'Bathroom', true],
    ['Floor 2 · Bedroom', 'Bedroom', true],
    ['Living Room (3)', 'Living Room', true],
    ['Hall Bath', 'Primary Bath', false],
    ['Kitchen', 'Laundry', false],
    // The same word, a different room: a hallway is not shown bathrooms as its checklist.
    ['Hallway', 'Hall Bath', false],
    ['Basement', 'Basement Bath', false],
    ['Kitchen', 'Kitchen / Family Rm', false],
    ['Laundry', 'Laundry Closet', false],
    ['New room (2)', 'Bedroom 2', false],
    ['Other 2 (1)', 'Other', false],
]);

it('puts the very same name ahead of names that only read alike', function () {
    config()->set('services.openai.api_key', null);
    $fx = claudeEstimateFixture();
    $bathroom = pastSection($fx, 'Bathroom', [['Floor Tile', 60]], 'Elm bath');
    $powder = pastSection($fx, 'Powder Room', [['Floor Tile', 20]], 'Oak powder');
    $bedroomFlooring = pastSection($fx, 'Bedroom Flooring', [['Floor Tile', 150]], 'Ash floors');
    $bedroom = pastSection($fx, 'Bedroom', [['Full Drywall', 2]], 'Pine bedroom');

    $found = app(\App\Services\EstimateAI\SectionRetriever::class)->namesakes($fx['vendor']->id, ['S1' => 'Powder Room', 'S2' => 'Floor 2 · Bedroom'], 'small bath and a bedroom', 3);

    // A powder room reads as a bath, so both are found — its namesake first. (The fixture's own "Hall Bath" comes after.)
    expect(collect($found['S1'])->pluck('section.id')->take(2)->all())->toBe([$powder->id, $bathroom->id])
        // The scan's "Floor 2 ·" is no part of the name: "Bedroom" before "Bedroom Flooring".
        ->and(collect($found['S2'])->pluck('section.id')->all())->toBe([$bedroom->id, $bedroomFlooring->id]);
});

it('keeps sections drafted together out of the one-section evaluation, and a section drafted alone in it', function () {
    [$fx, $component] = sectionsGenerator();
    $c = $fx['catalog'];
    tickSection($component, $fx['section']->id);

    // One section drafted on its own: its enquiry is its own, so it can be drafted again and scored.
    claudeDrafts([['S1', 'Hall Bath', 'Hall bath floor.', [[$c['Floor Tile'], 40]]]]);
    $component->call('generate')->call('finish');

    $this->artisan('estimates:ai-eval', ['--vendor' => $fx['vendor']->id, '--dry-run' => true])
        ->expectsOutputToContain('1 case for vendor')
        ->assertExitCode(0);

    // Two sections drafted from one description of the whole job: neither is a case on its own.
    tickSection($component, $fx['section']->id)->set('inquiry', 'Hall bath floor and a new kitchen floor.');
    claudeDrafts([
        ['S1', 'Hall Bath', 'Hall bath floor.', [[$c['Floor Tile'], 40]]],
        ['NEW', 'Kitchen', 'Kitchen floor.', [[$c['Floor Tile'], 120]]],
    ]);
    $component->call('generate')->call('finish');

    expect(EstimateAiDraft::count())->toBe(3);
    $this->artisan('estimates:ai-eval', ['--vendor' => $fx['vendor']->id, '--dry-run' => true])
        ->expectsOutputToContain('1 case for vendor')
        ->assertExitCode(0);
});

it('merges rooms into one section, measurements added up, and splits them back out', function () {
    [$fx, $component] = sectionsGenerator();
    $c = $fx['catalog'];
    $component->set('newFloorplans', [houseScan()]);

    $rows = fn () => collect($component->get('sectionRows'));
    $key = fn (string $name) => $rows()->firstWhere('name', $name)['key'];
    $mergedInto = fn (string $name) => $rows()->firstWhere('name', $name)['merged_into'];
    $hallBathOnEstimate = 'section-'.$fx['section']->id;
    $livingRoom = $key('Living Room (1)');
    $sliver = $key('Living Room (3)');
    $kitchen = $key('Kitchen');

    // The sliver belongs to the living room: one click, listed under it, sizes added.
    $component->call('mergeSectionRow', $sliver, $livingRoom)
        ->assertSee('437.9 sq.ft. · 2 rooms together')
        ->assertSee('Split out');
    expect($mergedInto('Living Room (3)'))->toBe($livingRoom);

    // And back out again: its own section, ticked — and it stays out when another scan is added.
    $component->call('splitSectionRow', $sliver)->assertDontSee('rooms together');
    expect($rows()->firstWhere('key', $sliver))->toMatchArray(['merged_into' => null, 'include' => true]);
    $component->set('floorplanLabels.0', 'Main floor');
    expect($rows()->firstWhere('key', $sliver)['merged_into'])->toBeNull();

    // A room that already has another merged into it takes that one along.
    $component->call('mergeSectionRow', $sliver, $livingRoom)->call('mergeSectionRow', $livingRoom, $kitchen)
        ->assertSee('672.3 sq.ft. · 3 rooms together');
    expect($mergedInto('Living Room (1)'))->toBe($kitchen)->and($mergedInto('Living Room (3)'))->toBe($kitchen);

    // The scanned hall bath goes into the Hall Bath already on the estimate, which is ticked for it.
    $component->call('mergeSectionRow', $key('Hall Bath'), $hallBathOnEstimate)->assertSee('adding 50.3 sq.ft. · 1 room together');
    expect($rows()->firstWhere('key', $hallBathOnEstimate)['include'])->toBeTrue();

    // Never into itself, into a room that is itself merged away, or a section of the estimate merged into something else.
    $component->call('mergeSectionRow', $kitchen, $kitchen)->call('mergeSectionRow', $kitchen, $livingRoom)->call('mergeSectionRow', $hallBathOnEstimate, $kitchen);
    expect($rows()->firstWhere('key', $kitchen)['merged_into'])->toBeNull()
        ->and($rows()->firstWhere('key', $hallBathOnEstimate)['merged_into'])->toBeNull();

    $fake = claudeDrafts([
        ['S1', 'Kitchen', 'Kitchen and living room as one.', [[$c['Baseboard Install'], 1]]],
        ['S2', 'Hall Bath', 'Hall bath floor.', [[$c['Floor Tile'], 1]]],
    ]);
    $component->call('generate')->assertSee('2 line items drafted into 2 sections');

    // Two sections asked for, each with the rooms put in it.
    $prompt = $fake->sentRequests[0]['messages'][0]['content'];
    expect($prompt)->toContain("S1 — Kitchen\n")
        ->toContain('"scanned_as":"Living Room (1) + Kitchen + Living Room (3)"')
        ->toContain('"floor_sqft":672.3')
        ->toContain('S2 — Hall Bath (already on the estimate: add to it)')
        ->toContain('"scanned_as":"Hall Bath"')
        ->not->toContain('S3')
        // The three rooms' perimeters together (64' 1" + 83' 8" + 32' 4") are Claude's to size baseboard from.
        ->toContain('"perimeter_ft":180.08')
        ->and(linesOf(sectionNamed($fx, 'Kitchen')))->toBe([['Baseboard Install', 1.0]])
        ->and(array_slice(linesOf($fx['section']), 3))->toBe([['Floor Tile', 1.0]])
        ->and(sectionNamed($fx, 'Living Room (1)'))->toBeNull();

    // Discarding keeps the merges for another try; taking the scan away undoes them.
    $component->call('discardDraft');
    expect($mergedInto('Living Room (1)'))->toBe($kitchen)
        ->and($rows()->firstWhere('key', $hallBathOnEstimate)['include'])->toBeTrue();

    $component->call('addSectionRow');
    $typed = $rows()->last()['key'];
    $component->set('sectionRows.'.($rows()->search(fn ($row) => $row['key'] === $typed)).'.name', 'Mudroom')
        ->call('mergeSectionRow', $kitchen, $typed)
        ->call('removeSectionRow', $typed);
    // Its section gone, the kitchen (and what was merged into it) stands on its own again.
    expect($rows()->firstWhere('key', $kitchen)['merged_into'])->toBeNull()
        ->and($rows()->firstWhere('key', $livingRoom)['merged_into'])->toBeNull();

    $component->call('removeFloorplan', 0);
    expect($rows()->pluck('kind')->unique()->values()->all())->toBe(['existing'])
        ->and($rows()->pluck('merged_into')->filter()->all())->toBe([]);
});

it('keeps the requests typed for an estimate, and drafts one again with its sections and measurements — no second upload', function () {
    [$fx, $component] = sectionsGenerator();
    $c = $fx['catalog'];
    $asked = 'Remodel the kitchen and the hall bath, new baseboard in the living room.';

    // The scan's rooms, the kitchen renamed, the hall bath left out.
    $component->set('newFloorplans', [houseScan()]);
    $component->set('sectionRows.'.rowNamed($component, 'Kitchen').'.name', 'Kitchen & Pantry')
        ->set('sectionRows.'.rowNamed($component, 'Hall Bath').'.include', false);

    claudeDrafts([
        ['S1', 'Living Room (1)', 'Baseboard.', [[$c['Baseboard Install'], 116]]],
        ['S2', 'Kitchen & Pantry', 'Kitchen floor.', [[$c['Floor Tile'], 234]]],
    ]);

    // The review says what was asked; Done clears the form but not the memory of it.
    $component->call('generate')->assertSee('You asked:')->assertSee($asked)->call('finish')
        ->assertSet('inquiry', '')->assertCount('floorplans', 0)
        ->assertSee('Earlier requests on this estimate')
        ->assertSee($asked)
        ->assertSee('2 sections')
        ->assertSee('2 line items from it are on the estimate')
        ->assertSee('Use again')->assertSee('Remove its draft');

    $run = EstimateAiRun::sole();
    expect($run->inquiry)->toBe($asked);

    // Its draft comes off the estimate — the sections it made with it — and the request is still listed.
    $component->call('removeDraft', $run->id)
        ->assertSee('its draft is no longer on the estimate')
        ->assertDontSee('Remove its draft');
    expect(sectionNamed($fx, 'Kitchen & Pantry'))->toBeNull()
        ->and(sectionNamed($fx, 'Living Room (1)'))->toBeNull()
        ->and(linesOf($fx['section']))->toHaveCount(3);

    // Used again: the description, the sections as they were named and merged, and their measurements.
    $component->call('useRequest', $run->id)
        ->assertSet('inquiry', $asked)
        ->assertSet('reusedRunId', $run->id);

    $saved = collect($component->get('sectionRows'))->where('kind', 'saved')->values();
    // The hall bath was measured but left out of that request: it comes back too, unticked as it was.
    expect($saved->pluck('include', 'name')->all())->toBe(['Living Room (1)' => true, 'Kitchen & Pantry' => true, 'Hall Bath' => false])
        ->and($saved[0]['detail'])->toBe('437.9 sq.ft. · 2 rooms together · saved with the earlier request')
        ->and($saved[1]['detail'])->toBe("234.4 sq.ft. · 17' 4\" x 12' 10\" · saved with the earlier request")
        ->and($run->plan['left_out'][0]['rooms'][0]['floor_sqft'])->toBe(50.3);

    // A second click does not list them twice; a saved section can be taken out like a typed one.
    $component->call('useRequest', $run->id)->call('removeSectionRow', $saved[2]['key']);
    expect(collect($component->get('sectionRows'))->where('kind', 'saved')->pluck('name')->all())->toBe(['Living Room (1)', 'Kitchen & Pantry']);

    $fake = claudeDrafts([['S2', 'Kitchen & Pantry', 'Kitchen floor again.', [[$c['Floor Tile'], 200]]]]);
    $component->set('inquiry', $asked.' Heated floor in the kitchen.')->call('generate')->assertSee('1 line item drafted into Kitchen & Pantry');

    $prompt = $fake->sentRequests[0]['messages'][0]['content'];
    expect($prompt)->toContain('Heated floor in the kitchen.')
        ->toContain("S1 — Living Room (1)\n")
        ->toContain('"scanned_as":"Living Room (1) + Living Room (3)"')
        ->toContain('"floor_sqft":437.9')
        ->toContain("S2 — Kitchen & Pantry\n")
        ->toContain('"countertop_lf":13.83')
        // The whole plan's numbers came back with the request too.
        ->toContain('WHOLE-PLAN MEASUREMENTS')
        ->not->toContain('Hall Bath (already on the estimate')
        ->and(EstimateAiRun::count())->toBe(2);

    // Both requests are remembered, the newest first; the same words typed twice are listed once.
    $component->call('finish');
    $listed = Illuminate\Support\Str::of($component->html())->between('data-earlier-requests', '</form>');
    expect($listed->substrCount('Use again'))->toBe(2)
        ->and($listed->position('Heated floor in the kitchen.'))->toBeLessThan($listed->position('its draft is no longer on the estimate'));
});

it('does not lose what was typed when the window is closed and opened again, and stops for a section that has gone', function () {
    [$fx, $component] = sectionsGenerator();
    $c = $fx['catalog'];

    $component->set('newFloorplans', [houseScan()])->call('addSectionRow');
    $component->set('sectionRows.'.(count($component->get('sectionRows')) - 1).'.name', 'Pantry');
    tickSection($component, $fx['section']->id);
    $rows = fn () => collect($component->get('sectionRows'))->map(fn ($row) => $row['kind'].' '.$row['name'].' '.($row['include'] ? 'ticked' : 'unticked').' '.($row['merged_into'] ? 'merged' : 'own'))->sort()->values()->all();
    $before = $rows();
    expect($before)->toContain('manual Pantry ticked own')->toContain('existing Hall Bath ticked own')->toContain('room Living Room (3) ticked merged');

    // Closed with the X, opened again from the estimate.
    $component->dispatch('openAIGenerator')
        ->assertSet('inquiry', 'Remodel the kitchen and the hall bath, new baseboard in the living room.')
        ->assertCount('floorplans', 1);
    expect($rows())->toBe($before);

    // Meanwhile a colleague disables the Hall Bath section that is still ticked here.
    $fx['section']->delete();
    claudeDrafts([['S1', 'Kitchen', 'Kitchen.', [[$c['Floor Tile'], 10]]]]);

    $component->call('generate')
        ->assertSet('isGenerating', false)
        ->assertSee('A ticked section is no longer on this estimate');
    expect(EstimateAiRun::count())->toBe(0)
        ->and(collect($component->get('sectionRows'))->where('kind', 'existing')->all())->toBe([])
        ->and($component->get('inquiry'))->not->toBe('');

    // The list refreshed, the same click now drafts.
    $component->call('generate')->assertSet('showPreview', true);
    expect(EstimateAiRun::count())->toBe(1);
});

it('offers to combine what the rooms have in common straight from the review', function () {
    [$fx, $component] = sectionsGenerator();
    $c = $fx['catalog'];
    $component->call('addSectionRow')->call('addSectionRow');
    $first = count($component->get('sectionRows')) - 2;
    $component->set("sectionRows.{$first}.name", 'Dining Room')->set('sectionRows.'.($first + 1).'.name', 'Kitchen');

    // One item in one section only: nothing to combine, no offer.
    claudeDrafts([['S1', 'Dining Room', 'Floor.', [[$c['Floor Tile'], 50]]]]);
    $component->call('generate')->assertDontSee('Done, then combine items')->call('discardDraft');

    claudeDrafts([
        ['S1', 'Dining Room', 'Floor.', [[$c['Floor Tile'], 50]]],
        ['S2', 'Kitchen', 'Floor.', [[$c['Floor Tile'], 120]]],
    ]);
    $component->call('generate')
        ->assertSee('Done, then combine items')
        ->call('finishAndCombine')
        ->assertSet('showPreview', false)
        ->assertDispatched('openCombineLineItems');

    expect(EstimateAiDraft::query()->pluck('status')->unique()->all())->toBe(['finished']);
});

it('comes back up on its own draft when the page is reloaded mid-draft, and not on a colleague\'s', function () {
    Queue::fake();
    [$fx, $component] = sectionsGenerator();
    tickSection($component, $fx['section']->id);
    $component->call('generate');
    $run = EstimateAiRun::sole();

    // The same person, a fresh page: following again, and the window told to open.
    Livewire::test(EstimateAIGenerator::class, ['estimate' => $fx['estimate']])
        ->assertSet('isGenerating', true)
        ->assertSet('runId', $run->id)
        ->assertSeeHtml('x-init="watch(true)"')
        ->assertSeeHtml('wire:poll.1s="pollDraft"');

    // A colleague opening the estimate is left alone until they open AI Generate themselves.
    $colleague = \App\Models\User::query()->create([
        'first_name' => 'Other', 'last_name' => 'Admin', 'email' => 'other-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224888####'), 'password' => bcrypt('password'),
    ]);
    $colleague->forceFill(['primary_vendor_id' => $fx['vendor']->id])->saveQuietly();
    $colleague->vendors()->attach($fx['vendor']->id, ['role_id' => 1, 'is_employed' => 1]);
    $this->actingAs($colleague->refresh());

    Livewire::test(EstimateAIGenerator::class, ['estimate' => $fx['estimate']])
        ->assertSet('isGenerating', false)
        ->assertSet('runId', null)
        ->assertSeeHtml('x-init="watch(false)"')
        ->dispatch('openAIGenerator')
        ->assertSet('isGenerating', true)
        ->assertSet('runId', $run->id);
});

it('gives drafts a queue of their own that outlasts them, and never starts one that waited too long', function () {
    // Horizon force-stops a worker it is scaling down once the supervisor's timeout has passed,
    // whatever the job's own timeout says: on the default supervisor (60 s) a draft could be killed midway.
    $job = new DraftEstimateSections(1);
    $supervisor = collect(config('horizon.defaults'))->first(fn (array $settings) => in_array($job->queue, $settings['queue'], true));

    expect($job->queue)->toBe('estimate-ai')
        ->and($supervisor)->not->toBeNull()
        ->and($supervisor['timeout'])->toBeGreaterThan($job->timeout)
        ->and(config('horizon.environments.production'))->toHaveKey('estimate-ai')
        ->and(config('horizon.environments.local'))->toHaveKey('estimate-ai');

    // The worker was down: the window gave up after five minutes and the estimator moved on.
    Queue::fake();
    [$fx, $component] = sectionsGenerator();
    $c = $fx['catalog'];
    tickSection($component, $fx['section']->id);
    $fake = claudeDrafts([['S1', 'Hall Bath', 'Floor.', [[$c['Floor Tile'], 40]]]]);
    $component->call('generate');
    $stale = EstimateAiRun::sole();

    $this->travel(6)->minutes();

    // Opening the window again writes the old run off, so a new one can be started…
    $component->dispatch('openAIGenerator')->assertSet('isGenerating', false);
    expect($stale->fresh()->status)->toBe('failed');
    $component->call('generate');
    expect(EstimateAiRun::count())->toBe(2);

    // …and when the worker comes back, the old job drafts nothing; the new one drafts once.
    (new DraftEstimateSections($stale->id))->handle(app(EstimateSectionsDrafter::class));
    expect($fake->sentRequests)->toBe([])->and(linesOf($fx['section']))->toHaveCount(3);

    $fresh = EstimateAiRun::query()->latest('id')->first();
    (new DraftEstimateSections($fresh->id))->handle(app(EstimateSectionsDrafter::class));
    (new DraftEstimateSections($fresh->id))->handle(app(EstimateSectionsDrafter::class));
    expect($fake->sentRequests)->toHaveCount(1)->and(linesOf($fx['section']))->toHaveCount(4);

    // A queued run nobody looked at again is refused by the worker itself.
    $component->call('pollDraft')->call('finish');
    tickSection($component, $fx['section']->id)->set('inquiry', 'Hall bath: one more outlet please.')->call('generate');
    $late = EstimateAiRun::query()->latest('id')->first();
    $this->travel(6)->minutes();
    (new DraftEstimateSections($late->id))->handle(app(EstimateSectionsDrafter::class));
    expect($late->fresh()->status)->toBe('failed')
        ->and($late->fresh()->error)->toContain('waited too long')
        ->and($fake->sentRequests)->toHaveCount(1);
});

it('stops adding lines once its run has been written off, and does not come back as finished', function () {
    Queue::fake();
    [$fx, $component] = sectionsGenerator();
    $c = $fx['catalog'];
    tickSection($component, $fx['section']->id);
    $fake = claudeDrafts([['S1', 'Hall Bath', 'Floor.', [[$c['Floor Tile'], 40], [$c['Cement Boards'], 5], [$c['New GFCI Location'], 2]]]]);
    $component->call('generate');
    $run = EstimateAiRun::sole();

    // Midway, the window that was following it marks it failed (it heard nothing for too long).
    $fake->beforeChunk = function () use ($fx) {
        if (EstimateLineItem::query()->where('section_id', $fx['section']->id)->count() > 3) {
            EstimateAiRun::query()->where('status', 'drafting')->update(['status' => 'failed', 'error' => 'The draft stopped before it finished. Try again.']);
        }
    };
    (new DraftEstimateSections($run->id))->handle(app(EstimateSectionsDrafter::class));

    expect($run->fresh()->status)->toBe('failed')
        ->and(array_slice(linesOf($fx['section']), 3))->toBe([['Floor Tile', 40.0]])
        // What did land is totalled.
        ->and((float) $fx['section']->fresh()->total)->toBe(850.0 + 1073.25 + 460.0 + 954.0);
});

it('comes back to a review that was closed without Done, instead of a form that would draft it all again', function () {
    [$fx, $component] = sectionsGenerator();
    $c = $fx['catalog'];
    $component->call('addSectionRow');
    $component->set('sectionRows.'.(count($component->get('sectionRows')) - 1).'.name', 'Pantry');
    claudeDrafts([['S1', 'Pantry', 'Pantry floor.', [[$c['Floor Tile'], 30], [$c['Cement Boards'], 1]]]]);

    $component->call('generate')->assertSet('showPreview', true);
    $run = EstimateAiRun::sole();

    // Escape, or the X: the window says so, and nothing is thrown away.
    $component->call('windowClosed')->assertSet('windowOpen', false);

    // Opened again — on this page, or after a reload in a fresh one — it is the review, with its Discard and Done.
    foreach ([$component, Livewire::test(EstimateAIGenerator::class, ['estimate' => $fx['estimate']])] as $window) {
        $window->dispatch('openAIGenerator')
            ->assertSet('showPreview', true)
            ->assertSet('runId', $run->id)
            ->assertCount('generatedItems', 2)
            ->assertSee('2 line items drafted into Pantry')
            ->assertSee('Discard Draft');
    }

    // Done is what closes a review: after it the window opens on the form.
    $component->call('finish')->dispatch('openAIGenerator')->assertSet('showPreview', false)->assertSet('runId', null)->assertSee('Earlier requests on this estimate');

    // A review left for more than a day is not brought back; the request is still listed to use again.
    $component->call('addSectionRow');
    $component->set('sectionRows.'.(count($component->get('sectionRows')) - 1).'.name', 'Closet')->set('inquiry', 'New tile floor in the closet.');
    claudeDrafts([['S1', 'Closet', 'Closet floor.', [[$c['Floor Tile'], 12]]]]);
    $component->call('generate')->call('windowClosed');
    $this->travel(25)->hours();
    $component->dispatch('openAIGenerator')->assertSet('showPreview', false)->assertSee('New tile floor in the closet.');
});

it('checks on a draft quietly once its window is closed, and says when it is ready instead of opening over other work', function () {
    Queue::fake();
    [$fx, $component] = sectionsGenerator();
    $c = $fx['catalog'];
    tickSection($component, $fx['section']->id);
    claudeDrafts([['S1', 'Hall Bath', 'Floor.', [[$c['Floor Tile'], 40]]]]);

    $component->call('generate')
        ->assertSet('windowOpen', true)
        ->assertSeeHtml('wire:poll.1s="pollDraft"')
        ->assertDontSeeHtml('data-quiet-follow');

    // The estimator closes the window and goes back to the estimate.
    $component->call('windowClosed')
        ->assertSeeHtml('wire:poll.5s="pollDraft"')
        ->assertDontSeeHtml('wire:poll.1s');

    (new DraftEstimateSections(EstimateAiRun::sole()->id))->handle(app(EstimateSectionsDrafter::class));

    // The quiet check finds it done: the estimate behind is refreshed and a toast says so — the window stays shut.
    $component->call('pollDraft')
        ->assertSet('isGenerating', false)
        ->assertSet('showPreview', true)
        ->assertDispatched('refreshComponent')
        ->assertDispatched('toast-show')
        ->assertNotDispatched('modal-show')
        ->assertDontSeeHtml('wire:poll');

    // Opening AI Generate is the way to the review.
    $component->dispatch('openAIGenerator')->assertSet('showPreview', true)->assertDispatched('modal-show');
});

it('takes a whole section out of the review, and leaves no section the draft made standing empty', function () {
    [$fx, $component] = sectionsGenerator();
    $c = $fx['catalog'];
    $component->call('addSectionRow')->call('addSectionRow');
    $first = count($component->get('sectionRows')) - 2;
    $component->set("sectionRows.{$first}.name", 'Pantry')->set('sectionRows.'.($first + 1).'.name', 'Closet');
    tickSection($component, $fx['section']->id);

    // Keys follow the list: the Hall Bath already on the estimate, then the two typed sections.
    claudeDrafts([
        ['S2', 'Pantry', 'Pantry floor.', [[$c['Floor Tile'], 30], [$c['Cement Boards'], 1]]],
        ['S3', 'Closet', 'Closet floor.', [[$c['Floor Tile'], 12]]],
        ['S1', 'Hall Bath', 'One more outlet.', [[$c['New GFCI Location'], 1]]],
        ['NEW', 'Code Compliance', 'Smoke detectors.', [[$c['New GFCI Location'], 2]]],
    ]);
    $component->call('generate')->assertSee('5 line items drafted into 4 sections')->assertSeeHtml('removeSection(');

    // The pantry goes in one click: its lines, and the section the draft made for them.
    $component->call('removeSection', sectionNamed($fx, 'Pantry')->id)->assertCount('generatedItems', 3);
    expect(sectionNamed($fx, 'Pantry'))->toBeNull();

    // A section that was on the estimate before only loses what the draft put in it.
    $component->call('removeSection', $fx['section']->id)->assertCount('generatedItems', 2);
    expect(linesOf($fx['section']))->toHaveCount(3)->and($fx['section']->fresh())->not->toBeNull();

    // Line by line works too: the last line of Code Compliance removed, then Done — no empty section left behind.
    $compliance = sectionNamed($fx, 'Code Compliance');
    $component->call('removeItem', 1, (int) collect($component->get('generatedItems'))->firstWhere('section_id', $compliance->id)['id'])
        ->call('finish');

    expect(sectionNamed($fx, 'Code Compliance'))->toBeNull()
        ->and(EstimateSection::withTrashed()->find($compliance->id))->toBeNull()
        ->and(linesOf(sectionNamed($fx, 'Closet')))->toBe([['Floor Tile', 12.0]]);
});

it('never takes a signed contract apart: a draft that was signed stays when its request is removed', function () {
    [$fx, $component] = sectionsGenerator();
    $c = $fx['catalog'];
    $project = \App\Models\Project::withoutGlobalScopes()->findOrFail($fx['estimate']->project_id);
    $project->vendors()->attach($fx['vendor']->id, ['client_id' => $project->client_id]);
    $fx['vendor']->clients()->attach($project->client_id);

    $component->call('addSectionRow');
    $component->set('sectionRows.'.(count($component->get('sectionRows')) - 1).'.name', 'Kitchen');
    claudeDrafts([['S1', 'Kitchen', 'Kitchen floor.', [[$c['Floor Tile'], 120]]]]);
    $component->call('generate')->call('finish');
    $run = EstimateAiRun::sole();
    $kitchen = sectionNamed($fx, 'Kitchen');

    // The client signs; a week later someone opens AI Generate for a change order.
    $this->travel(1)->hours();
    EstimateSignature::create([
        'estimate_id' => $fx['estimate']->id, 'user_id' => $fx['user']->id, 'signer_name' => 'Est Imator', 'signer_email' => $fx['user']->email,
        'signature_data' => 'data:image/png;base64,x', 'signature_type' => 'draw', 'ip_address' => '127.0.0.1', 'user_agent' => 'pest', 'document_hash' => 'h', 'signed_at' => now(),
    ]);
    $this->travel(7)->days();
    expect($kitchen->fresh()->isLocked())->toBeTrue();

    // The request is still there to use again; its draft is no longer offered for removal.
    $component->dispatch('openAIGenerator')
        ->assertSee('1 line item from it is in the signed contract')
        ->assertDontSee('Remove its draft')
        ->assertSee('Use again');

    // And asked for anyway (a stale page), nothing of the contract goes.
    $component->call('removeDraft', $run->id);
    expect(linesOf($kitchen->fresh()))->toBe([['Floor Tile', 120.0]])
        ->and($kitchen->fresh())->not->toBeNull()
        ->and(EstimateAiDraft::sole()->status)->toBe('finished');
});

it('warns before drafting a request again while its first draft still stands, and takes combined lines with a removed draft', function () {
    [$fx, $component] = sectionsGenerator();
    $c = $fx['catalog'];
    $component->call('addSectionRow')->call('addSectionRow');
    $first = count($component->get('sectionRows')) - 2;
    $component->set("sectionRows.{$first}.name", 'Dining Room')->set('sectionRows.'.($first + 1).'.name', 'Kitchen');
    claudeDrafts([
        ['S1', 'Dining Room', 'Trim and drywall.', [[$c['Baseboard Install'], 40], [$c['Full Drywall'], 2]]],
        ['S2', 'Kitchen', 'Trim and drywall.', [[$c['Baseboard Install'], 60], [$c['Full Drywall'], 4]]],
    ]);
    $component->call('generate')->call('finishAndCombine')
        // Both items are in both rooms: ticked for the estimator in the window that opens next.
        ->assertDispatched('openCombineLineItems', tick: [$c['Baseboard Install']->id, $c['Full Drywall']->id]);
    $run = EstimateAiRun::sole();

    // The estimator combines the baseboard only.
    Livewire::test(\App\Livewire\Estimates\EstimateCombineLineItems::class, ['estimate' => $fx['estimate']])
        ->dispatch('openCombineLineItems', tick: [$c['Baseboard Install']->id])
        ->assertSet('selected', [(string) $c['Baseboard Install']->id])
        ->assertSet('sectionName', 'Carpentry')
        ->set('sectionName', 'Trim')
        ->call('combine')->assertHasNoErrors();

    $trim = sectionNamed($fx, 'Trim');
    expect(linesOf($trim))->toBe([['Baseboard Install', 100.0]]);

    // Used again while its draft stands: the window says what would happen, and offers the way out.
    // (Four of its lines stand: the two drywall lines, and the two baseboard lines inside the combined one.)
    $component->dispatch('openAIGenerator')->call('useRequest', $run->id)
        ->assertSee('draft is still on the estimate')
        ->assertSee('4 line items from it are there now')
        ->assertSee('Remove that draft first');

    $component->call('removeDraft', $run->id)
        ->assertDontSee('draft is still on the estimate')
        ->assertSee('its draft is no longer on the estimate');

    // Everything the draft made is gone — the combined baseboard line too, since it held nothing but this draft's
    // baseboard — and so are the rooms it made. The Trim section, made by the combine, is left for the estimator.
    expect(linesOf($trim->fresh()))->toBe([])
        ->and((float) $trim->fresh()->total)->toBe(0.0)
        ->and(sectionNamed($fx, 'Dining Room'))->toBeNull()
        ->and(sectionNamed($fx, 'Kitchen'))->toBeNull()
        ->and(EstimateLineItem::withTrashed()->where('estimate_id', $fx['estimate']->id)->count())->toBe(3);
});

it('leaves a combined line that also carries other work when a draft is removed', function () {
    [$fx, $component] = sectionsGenerator();
    $c = $fx['catalog'];
    $component->call('addSectionRow');
    $component->set('sectionRows.'.(count($component->get('sectionRows')) - 1).'.name', 'Kitchen');
    claudeDrafts([['S1', 'Kitchen', 'Floor.', [[$c['Floor Tile'], 120]]]]);
    $component->call('generate')->call('finish');
    $run = EstimateAiRun::sole();

    // Combined with the hall bath's own 45 sq.ft., which no draft made.
    $line = app(\App\Services\Estimates\EstimateLineItemCombiner::class)->combine($fx['estimate'], [(string) $c['Floor Tile']->id], 'Flooring')['combined'][0];
    expect((float) $line->quantity)->toBe(165.0);

    $result = app(\App\Services\Estimates\EstimateAiDraftWriter::class)->discardRun($fx['estimate'], $run);

    expect($result['kept_combined'])->toBe(1)
        ->and(EstimateLineItem::query()->find($line->id))->not->toBeNull()
        ->and((float) $line->fresh()->quantity)->toBe(165.0);
});

it('reads a picture plan within the request, and a reading that fails is a note rather than an error', function () {
    config()->set('services.ocr_space.api_key', 'key');
    config()->set('services.ocr_space.endpoint', 'https://ocr.test/parse');
    Illuminate\Support\Facades\Http::fake(['https://ocr.test/*' => fn () => throw new Illuminate\Http\Client\ConnectionException('Operation timed out')]);

    $plan = app(FloorplanParser::class)->parseFloorplanFile(UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf'));

    expect($plan['note'])->toContain('OCR failed')->and($plan['filename'])->toBe('plan.pdf');
});

it('sends a section\'s own note to Claude, and keeps it with the request', function () {
    [$fx, $component] = sectionsGenerator();
    $c = $fx['catalog'];
    $component->set('newFloorplans', [houseScan()]);

    // "new kitchen and pantry shelving" could be shelving or a whole kitchen: the note says which, for that section.
    $kitchen = rowNamed($component, 'Kitchen');
    $component->set("sectionRows.{$kitchen}.note", 'Full new kitchen with an island; call greg@gs.test about the hood')
        ->set('sectionRows.'.rowNamed($component, 'Hall Bath').'.include', false);
    tickSection($component, $fx['section']->id);
    $component->set('sectionRows.'.array_search($fx['section']->id, array_column($component->get('sectionRows'), 'section_id'), true).'.note', 'One more outlet only');

    $fake = claudeDrafts([['S2', 'Kitchen', 'Kitchen.', [[$c['Floor Tile'], 10]]]]);
    $component->call('generate')->assertHasNoErrors();

    $request = $fake->sentRequests[0];
    $prompt = $request['messages'][0]['content'];
    expect($prompt)->toContain("The estimator's note for this section: Full new kitchen with an island; call [email] about the hood")
        ->toContain("The estimator's note for this section: One more outlet only")
        // A section without a note says nothing about one.
        ->and(substr_count($prompt, "The estimator's note for this section:"))->toBe(2)
        ->and($request['system'][0]['text'])->toContain("where the two read differently, the note decides for that section");

    $run = EstimateAiRun::sole();
    expect(collect($run->plan['sections'])->pluck('note', 'name')->all())->toMatchArray(['Kitchen' => 'Full new kitchen with an island; call greg@gs.test about the hood', 'Hall Bath' => 'One more outlet only', 'Living Room (1)' => null]);

    // Used again, the note comes back with its section.
    $component->call('finish')->call('useRequest', $run->id);
    expect(collect($component->get('sectionRows'))->where('kind', 'saved')->firstWhere('name', 'Kitchen')['note'])->toBe('Full new kitchen with an island; call greg@gs.test about the hood');
    $component->assertSeeHtml('noting: true');

    // A note longer than a sentence or two is refused rather than cut silently.
    $component->set('sectionRows.0.note', str_repeat('x', 301))->call('generate')->assertHasErrors('sectionRows.0.note');
});

it('drafts one section again from the review with what should be different, and keeps the rest', function () {
    [$fx, $component] = sectionsGenerator();
    $c = $fx['catalog'];
    $component->set('newFloorplans', [houseScan()]);
    $component->set('sectionRows.'.rowNamed($component, 'Hall Bath').'.include', false);

    claudeDrafts([
        ['S1', 'Living Room (1)', 'Baseboard.', [[$c['Baseboard Install'], 116]]],
        ['S2', 'Kitchen', 'Shelving only.', [[$c['Full Drywall'], 1]]],
    ], 'Whole job.');
    $component->call('generate')->assertSee('2 line items drafted into 2 sections')->assertSeeHtml('startRedo(');
    $first = EstimateAiRun::sole();
    $kitchen = sectionNamed($fx, 'Kitchen');
    $thin = linesOf($kitchen);

    // The kitchen came back thin. Its redo box opens under its heading…
    $component->call('startRedo', $kitchen->id)->assertSet('redoSectionId', $kitchen->id)->assertSee('what should be different?')
        ->call('cancelRedo')->assertSet('redoSectionId', null)->assertDontSee('what should be different?')
        ->call('startRedo', $kitchen->id)->set('redoNote', 'Full new kitchen with an island');

    // …and only the kitchen is drafted again.
    $fake = claudeDrafts([['S1', 'Kitchen', 'Full kitchen.', [[$c['Demo Bathroom'], 1], [$c['Full Drywall'], 12], [$c['Floor Tile'], 234]]]], 'Kitchen again.');
    $component->call('redoSection')->assertHasNoErrors()
        ->assertSet('showPreview', true)
        ->assertSet('redoSectionId', null)
        ->assertSee('3 line items drafted into Kitchen');

    $again = EstimateAiRun::query()->latest('id')->first();
    $request = $fake->sentRequests[0];
    $prompt = $request['messages'][0]['content'];

    expect($again->id)->not->toBe($first->id)
        ->and($again->inquiry)->toBe($first->inquiry)
        ->and($again->plan)->toMatchArray(['listed' => true, 'closed' => true, 'redo_of' => $first->id])
        ->and($component->get('runId'))->toBe($again->id)
        // The same request, that one section with its room's measurements and the note — and nothing else to answer with.
        ->and($prompt)->toContain('Remodel the kitchen and the hall bath, new baseboard in the living room.')
        ->toContain('S1 — Kitchen (already on the estimate: add to it)')
        ->toContain('"floor_sqft":234.4')
        ->toContain("The estimator's note for this section: Full new kitchen with an island")
        ->toContain('Only what is listed below is being drafted now')
        ->not->toContain('Living Room')->not->toContain('Use key NEW')
        ->and($request['outputConfig']['format']['schema']['properties']['sections']['items']['properties']['key']['enum'])->toBe(['S1']);

    // The kitchen holds the new draft, in the same section; the living room is as it was.
    expect(sectionNamed($fx, 'Kitchen')->id)->toBe($kitchen->id)
        ->and(linesOf($kitchen))->toBe([['Demo Bathroom', 1.0], ['Full Drywall', 12.0], ['Floor Tile', 234.0]])
        ->and($thin)->toBe([['Full Drywall', 1.0]])
        ->and(linesOf(sectionNamed($fx, 'Living Room (1)')))->toBe([['Baseboard Install', 116.0]])
        ->and(EstimateSection::query()->where('estimate_id', $fx['estimate']->id)->where('name', 'Kitchen')->count())->toBe(1);

    // The first kitchen draft was not wanted — nothing is learned from it; the living room's is kept; the new one awaits its Done.
    $drafts = EstimateAiDraft::query()->orderBy('id')->get();
    expect($drafts->map(fn (EstimateAiDraft $draft) => [$draft->run_id, $draft->section_id, $draft->status])->all())->toBe([
        [$first->id, sectionNamed($fx, 'Living Room (1)')->id, 'finished'],
        [$first->id, $kitchen->id, 'discarded'],
        [$again->id, $kitchen->id, 'drafted'],
    ]);

    // Still one request in the list — a section drafted again is part of it — and Use again brings back both sections.
    $component->call('finish');
    $listed = Illuminate\Support\Str::of($component->html())->between('data-earlier-requests', '</form>');
    expect($listed->substrCount('Use again'))->toBe(1);
    $component->call('useRequest', $first->id);
    expect(collect($component->get('sectionRows'))->where('kind', 'saved')->where('include', true)->pluck('name')->all())->toBe(['Living Room (1)', 'Kitchen']);

    // Not for a section of a signed contract, a section the draft did not touch, or while another draft runs: nothing happens.
    $runs = EstimateAiRun::count();
    $component->call('startRedo', $fx['section']->id)->call('redoSection')->assertSet('redoSectionId', null);
    expect(EstimateAiRun::count())->toBe($runs)->and(linesOf($fx['section']))->toHaveCount(3);
});

it('keeps a description that was typed and not sent through a reload, for the person who typed it', function () {
    [$fx, $component] = sectionsGenerator();
    $typed = 'Gut the kitchen, new cabinets and island, and refinish every floor on the first level.';
    $component->set('inquiry', $typed);

    // A fresh page for the same person on the same estimate: the text is there.
    Livewire::test(EstimateAIGenerator::class, ['estimate' => $fx['estimate']])->assertSet('inquiry', $typed);

    // Cleared, it stays cleared.
    $component->set('inquiry', '');
    Livewire::test(EstimateAIGenerator::class, ['estimate' => $fx['estimate']])->assertSet('inquiry', '');

    // Sent, it is a saved request and no longer unsent text; Done leaves a clean box behind.
    $c = $fx['catalog'];
    tickSection($component->set('inquiry', $typed), $fx['section']->id);
    claudeDrafts([['S1', 'Hall Bath', 'Floor.', [[$c['Floor Tile'], 40]]]]);
    $component->call('generate')->call('finish');
    Livewire::test(EstimateAIGenerator::class, ['estimate' => $fx['estimate']])->assertSet('inquiry', '')->assertSee($typed);

    // A colleague does not get this person's half-typed text.
    $component->set('inquiry', 'Something only half typed');
    $colleague = \App\Models\User::query()->create([
        'first_name' => 'Other', 'last_name' => 'Admin', 'email' => 'other-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224999####'), 'password' => bcrypt('password'),
    ]);
    $colleague->forceFill(['primary_vendor_id' => $fx['vendor']->id])->saveQuietly();
    $colleague->vendors()->attach($fx['vendor']->id, ['role_id' => 1, 'is_employed' => 1]);
    $this->actingAs($colleague->refresh());
    Livewire::test(EstimateAIGenerator::class, ['estimate' => $fx['estimate']])->assertSet('inquiry', '');
});

it('does not list a scan\'s rooms a second time beside the saved sections of a request being used again', function () {
    [$fx, $component] = sectionsGenerator();
    $c = $fx['catalog'];
    $asked = 'Remodel the kitchen and the hall bath, new baseboard in the living room.';

    // The first request: the scan's rooms, the kitchen renamed, the hall bath left out.
    $component->set('newFloorplans', [houseScan()]);
    $component->set('sectionRows.'.rowNamed($component, 'Kitchen').'.name', 'Kitchen & Pantry')
        ->set('sectionRows.'.rowNamed($component, 'Hall Bath').'.include', false);
    claudeDrafts([['S2', 'Kitchen & Pantry', 'Kitchen.', [[$c['Floor Tile'], 10]]]]);
    $component->call('generate')->call('finish');
    $run = EstimateAiRun::sole();
    $component->call('removeDraft', $run->id);

    $rows = fn () => collect($component->get('sectionRows'))->reject(fn ($row) => $row['kind'] === 'existing')->map(fn ($row) => $row['kind'].': '.$row['name'])->values()->all();

    // 2026-11-13, estimate 296: "Use again", then — out of habit — the same scan uploaded. Every room was listed
    // twice, and same-named ones would have been drafted as one section at twice the size.
    $component->call('useRequest', $run->id)->set('newFloorplans', [houseScan()]);
    expect($rows())->toBe(['saved: Living Room (1)', 'saved: Kitchen & Pantry', 'saved: Hall Bath']);

    // The other way round — the scan first, then Use again — ends in the same place.
    $component->call('startOver')->assertSet('inquiry', '')->assertCount('floorplans', 0)->assertSet('reusedRunId', null);
    expect($rows())->toBe([]);
    $component->set('newFloorplans', [houseScan()]);
    expect($rows())->toHaveCount(4);
    $component->call('useRequest', $run->id);
    expect($rows())->toBe(['saved: Living Room (1)', 'saved: Kitchen & Pantry', 'saved: Hall Bath']);

    $fake = claudeDrafts([['S2', 'Kitchen & Pantry', 'Kitchen.', [[$c['Floor Tile'], 10]]]]);
    $component->call('generate');
    $prompt = $fake->sentRequests[0]['messages'][0]['content'];

    // Each room once, at its own size.
    expect($prompt)->toContain('"scanned_as":"Living Room (1) + Living Room (3)"')->toContain('"floor_sqft":437.9')
        ->toContain('"scanned_as":"Kitchen"')->toContain('"floor_sqft":234.4')
        ->not->toContain('875.8')->not->toContain('468.8')
        ->and(substr_count($prompt, 'Kitchen & Pantry'))->toBe(1);

    // A saved section taken out no longer speaks for its room: the scan lists it again.
    $component->call('discardDraft')->call('removeSectionRow', collect($component->get('sectionRows'))->firstWhere('name', 'Kitchen & Pantry')['key']);
    expect($rows())->toBe(['room: Kitchen', 'saved: Living Room (1)', 'saved: Hall Bath']);
});

it('drafts a saved section from a newer scan of the same rooms', function () {
    [$fx, $component] = sectionsGenerator();
    $c = $fx['catalog'];
    $component->set('newFloorplans', [houseScan()]);
    $component->set('sectionRows.'.rowNamed($component, 'Hall Bath').'.include', false);
    claudeDrafts([['S2', 'Kitchen', 'Kitchen.', [[$c['Floor Tile'], 10]]]]);
    $component->call('generate')->call('finish');
    $run = EstimateAiRun::sole();
    $component->call('removeDraft', $run->id)->call('useRequest', $run->id);

    // The house was scanned again after the kitchen wall moved: the kitchen is now 300 sq.ft., and there is a new pantry.
    $rescan = str_replace('Kitchen,Floor area [ft^2],234.4', "Kitchen,Floor area [ft^2],300.0\nPantry,Floor area [ft^2],40.0", file_get_contents(base_path('tests/fixtures/polycam-house.csv')));
    $component->set('newFloorplans', [UploadedFile::fake()->createWithContent('house-2.csv', $rescan)]);

    $rows = collect($component->get('sectionRows'));
    // The saved sections stay as they were set up, at the new sizes; only the room the request never had is new.
    expect($rows->where('kind', 'room')->pluck('name')->all())->toBe(['Pantry'])
        ->and($rows->firstWhere('name', 'Kitchen')['kind'])->toBe('saved')
        ->and($rows->firstWhere('name', 'Kitchen')['detail'])->toStartWith('300 sq.ft.');

    $fake = claudeDrafts([['S2', 'Kitchen', 'Kitchen.', [[$c['Floor Tile'], 10]]]]);
    $component->call('generate');
    expect($fake->sentRequests[0]['messages'][0]['content'])->toContain('"floor_sqft":300')->not->toContain('"floor_sqft":234.4');
});

it('puts a picture of a plan aside at the click and reads it in the job, beside a scan read on the spot', function () {
    Queue::fake();
    Illuminate\Support\Facades\Storage::fake('local');
    config()->set('services.ocr_space.api_key', 'key');
    config()->set('services.ocr_space.endpoint', 'https://ocr.test/parse');
    Illuminate\Support\Facades\Http::fake(['https://ocr.test/*' => Illuminate\Support\Facades\Http::response(['ParsedResults' => [['ParsedText' => "BASEMENT 20' x 30'\nFloor area: 600 sq ft"]]])]);
    [$fx, $component] = sectionsGenerator();
    $c = $fx['catalog'];

    // The first floor as a room scan, the basement as a photo of a drawing.
    $component->set('newFloorplans', [houseScan(), UploadedFile::fake()->image('basement.jpg', 1200, 900)])
        ->set('floorplanLabels.1', 'Basement');
    // The scan's rooms are listed at once, as before; the picture names none.
    expect(collect($component->get('sectionRows'))->where('kind', 'room')->count())->toBe(4);

    $component->call('generate')->assertSet('isGenerating', true)->assertSet('error', '');

    // 2026-11-13: the click read every picture itself, up to 20 seconds a file. Now nothing is sent off until the job runs.
    Illuminate\Support\Facades\Http::assertNothingSent();
    $run = EstimateAiRun::sole();
    expect($run->status)->toBe(EstimateAiRun::QUEUED)
        // What the scan says is with the request already; the basement waits.
        ->and((float) $run->floorplan['floor_sqft'])->toBeGreaterThan(700.0)->toBeLessThan(800.0)
        ->and(array_column($run->plan['plans'], 'label'))->toBe(['Floor 1', 'Basement'])
        ->and($run->plan['plans'][0])->toHaveKey('data')->not->toHaveKey('path')
        ->and($run->plan['plans'][1])->toMatchArray(['filename' => 'basement.jpg'])->not->toHaveKey('data');
    $kept = $run->plan['plans'][1]['path'];
    Illuminate\Support\Facades\Storage::disk('local')->assertExists($kept);
    $scanOnly = (float) $run->floorplan['floor_sqft'];

    // While the worker reads it, the window says so.
    $run->forceFill(['status' => EstimateAiRun::DRAFTING, 'started_at' => now(), 'heartbeat_at' => now()])->save();
    $component->call('pollDraft')->assertSee('Reading the plan…');
    $run->forceFill(['status' => EstimateAiRun::QUEUED])->save();

    $fake = claudeDrafts([['S2', 'Kitchen', 'Kitchen.', [[$c['Floor Tile'], 10]]]]);
    (new DraftEstimateSections($run->id))->handle(app(EstimateSectionsDrafter::class));

    Illuminate\Support\Facades\Http::assertSentCount(1);
    $run->refresh();
    expect($run->status)->toBe(EstimateAiRun::FINISHED)
        ->and($run->plan)->not->toHaveKey('plans')
        // Both floors now: the scan's rooms plus the basement's 600 sq.ft., each floor kept beside the total.
        ->and((float) $run->floorplan['floor_sqft'])->toBe(round($scanOnly + 600.0, 2))
        ->and(array_column($run->floorplan['floors'], 'label'))->toBe(['Floor 1', 'Basement'])
        ->and((float) $run->floorplan['floors'][1]['floor_sqft'])->toBe(600.0)
        ->and($fake->sentRequests[0]['messages'][0]['content'])->toContain('Basement');
    Illuminate\Support\Facades\Storage::disk('local')->assertMissing($kept);

    $component->call('pollDraft')->assertSet('isGenerating', false)->assertSet('showPreview', true);
});

it('reads a scan alone at the click, with nothing left for the job to read', function () {
    Queue::fake();
    Illuminate\Support\Facades\Storage::fake('local');
    [$fx, $component] = sectionsGenerator();

    $component->set('newFloorplans', [houseScan()])->call('generate');

    $run = EstimateAiRun::sole();
    expect($run->plan)->not->toHaveKey('plans')
        ->and($run->floorplan['rooms'])->toHaveCount(4)
        ->and(Illuminate\Support\Facades\Storage::disk('local')->allFiles('estimate-ai-plans'))->toBe([]);
});

it('starts a draft only from the Generate button, never from Enter in a box', function () {
    [$fx, $component] = sectionsGenerator();
    $component->call('addSectionRow');

    // 2026-11-13: the window was a form, so Enter in a section name or a note submitted it and a draft started.
    $component->assertDontSeeHtml('wire:submit="generate"')
        ->assertSeeHtml('wire:click="generate"')
        ->assertSeeHtml('data-generator-form');

    // The same in the review: Enter in the "draft it again" note does not draft.
    $c = $fx['catalog'];
    claudeDrafts([['S1', 'Kitchen', 'Kitchen.', [[$c['Floor Tile'], 10]]]]);
    $component->set('sectionRows.'.(count($component->get('sectionRows')) - 1).'.name', 'Kitchen')->call('generate');
    $section = sectionNamed($fx, 'Kitchen');
    $component->call('startRedo', $section->id)
        ->assertSeeHtml('data-redo-section')
        ->assertDontSeeHtml('wire:keydown.enter="redoSection"')
        ->assertSeeHtml('wire:click="redoSection"');
});

it('keeps the note that went to Claude on its section, and shows it in the review and on the estimate', function () {
    [$fx, $component] = sectionsGenerator();
    $c = $fx['catalog'];

    // The Hall Bath is on the estimate already; a kitchen with a note and a dining room without one are typed in.
    tickSection($component, $fx['section']->id);
    $component->set('sectionRows.0.note', 'One more outlet only');
    $component->call('addSectionRow')->set('sectionRows.1.name', 'Kitchen')->set('sectionRows.1.note', 'Full new kitchen with an island')
        ->call('addSectionRow')->set('sectionRows.2.name', 'Dining Room');

    claudeDrafts([
        ['S1', 'Hall Bath', 'One more outlet in the bath.', [[$c['New GFCI Location'], 1]]],
        ['S2', 'Kitchen', 'A full kitchen.', [[$c['Floor Tile'], 10]]],
        ['S3', 'Dining Room', 'Patch the dining room.', [[$c['Full Drywall'], 2]]],
    ]);
    $component->call('generate')->assertSet('showPreview', true)
        // 2026-11-13: the note was sent to Claude and then shown nowhere. The review says it, section by section.
        ->assertSeeHtml('data-section-note')
        ->assertSee('Note to Claude:')->assertSee('Full new kitchen with an island')->assertSee('One more outlet only');
    expect(substr_count($component->html(), 'data-section-note'))->toBe(2);

    $kitchen = sectionNamed($fx, 'Kitchen');
    expect($kitchen->ai_note)->toBe('Full new kitchen with an island')
        ->and($kitchen->ai_scope)->toBe('A full kitchen.')
        ->and(sectionNamed($fx, 'Dining Room')->ai_note)->toBeNull()
        ->and($fx['section']->fresh()->ai_note)->toBe('One more outlet only');

    // On the estimate page it stands with the section, beside how the AI read it.
    $project = \App\Models\Project::withoutGlobalScopes()->findOrFail($fx['estimate']->project_id);
    $project->vendors()->attach($fx['vendor']->id, ['client_id' => $project->client_id]);
    $fx['vendor']->clients()->attach($project->client_id);
    $page = Livewire::test(EstimateShow::class, ['estimate' => $fx['estimate']->fresh()]);
    $page->assertSee('A full kitchen.')->assertSee('Note to Claude:')->assertSee('Full new kitchen with an island')->assertSee('One more outlet only');
    expect(substr_count($page->html(), 'data-section-ai-note'))->toBe(2);

    // Drafted again with a word more: the section carries both.
    claudeDrafts([['S1', 'Kitchen', 'A full kitchen with a pantry wall.', [[$c['Floor Tile'], 12], [$c['Full Drywall'], 3]]]]);
    $component->call('startRedo', $kitchen->id)->set('redoNote', 'Add a pantry wall')->call('redoSection')
        ->assertSee('Full new kitchen with an island; Add a pantry wall');
    expect($kitchen->fresh()->ai_note)->toBe('Full new kitchen with an island; Add a pantry wall');
    $component->call('finish');

    // Added to later without a note: the section keeps the note it had.
    tickSection($component, $fx['section']->id);
    claudeDrafts([['S1', 'Hall Bath', 'Retile the bath floor.', [[$c['Floor Tile'], 45]]]]);
    $component->set('inquiry', 'Retile the hall bath floor as well.')->call('generate')->assertSet('showPreview', true);
    expect($fx['section']->fresh()->ai_note)->toBe('One more outlet only')
        ->and($fx['section']->fresh()->ai_scope)->toBe('Retile the bath floor.');

    // A discarded draft takes what it said about the section with it, as it does the scope.
    $component->call('discardDraft');
    expect($fx['section']->fresh()->ai_note)->toBeNull()->and($fx['section']->fresh()->ai_scope)->toBeNull()
        ->and($kitchen->fresh()->ai_note)->toBe('Full new kitchen with an island; Add a pantry wall');
});

it('shows a no-unit line\'s quantity and price in the review, and saves a price typed there', function () {
    [$fx, $component] = draftingGenerator();
    $component->call('generate')->assertSet('showPreview', true);

    $rows = collect($component->get('generatedItems'));
    $demo = $rows->search(fn (array $row) => $row['name'] === 'Demo Bathroom');
    $tile = $rows->search(fn (array $row) => $row['name'] === 'Floor Tile');

    // 2026-11-13: Demo, Framing, Drywall Patch and Painting showed no quantity and no price at all.
    $component->assertSeeHtml('data-lump-sum-price')->assertSeeHtml('aria-label="Quantity of Demo Bathroom"');

    $component->set("generatedItems.{$demo}.cost", '1250')->set("generatedItems.{$demo}.quantity", '2')
        ->assertSet("generatedItems.{$demo}.cost", 1250.0)
        ->assertSet("generatedItems.{$demo}.total", 2500.0);
    $line = EstimateLineItem::find($component->get("generatedItems.{$demo}.id"));
    expect((float) $line->cost)->toBe(1250.0)->and((float) $line->total)->toBe(2500.0);

    // A line with a unit keeps the catalog price: a price sent for it changes nothing.
    $component->set("generatedItems.{$tile}.cost", '1')->assertSet("generatedItems.{$tile}.cost", 23.85);
    expect((float) EstimateLineItem::find($component->get("generatedItems.{$tile}.id"))->cost)->toBe(23.85);
});

