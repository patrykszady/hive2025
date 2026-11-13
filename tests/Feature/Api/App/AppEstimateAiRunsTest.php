<?php

use App\Http\Middleware\EnsureAppUserAllowed;
use App\Jobs\DraftEstimateSections;
use App\Models\Estimate;
use App\Models\EstimateAiDraft;
use App\Models\EstimateAiRun;
use App\Models\EstimateLineItem;
use App\Models\EstimateSection;
use App\Models\EstimateSignature;
use App\Models\Project;
use App\Models\User;
use App\Services\EstimateAI\EstimateSectionsDrafter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/estimate-ai-fixtures.php';

beforeEach(function () {
    config(['scout.driver' => null]);
    // The app is GS Construction's alone (company id 1 — AppAuthTest covers that gate). The estimate
    // fixture shared with the website's tests makes a company of its own, so the gate is set aside here.
    $this->withoutMiddleware(EnsureAppUserAllowed::class);
});

/**
 * Whole-estimate AI drafting in the Hive Contractors app (2026-11-13): the
 * website's generator window as endpoints. The fixture estimate has a Hall
 * Bath section — a lump-sum demo ($850), 45 sq.ft. of Floor Tile at $23.85
 * and two GFCI locations at $230.
 *
 * @return array<string, mixed>
 */
function appAiEstimate(): array
{
    $fx = estimateAdmin(claudeEstimateFixture());
    // The company can see its own project and client, as it does in real data.
    $project = Project::withoutGlobalScopes()->findOrFail($fx['estimate']->project_id);
    $project->vendors()->attach($fx['vendor']->id, ['client_id' => $project->client_id]);
    $fx['vendor']->clients()->attach($project->client_id);
    Sanctum::actingAs($fx['user'], ['app']);

    return $fx + ['url' => '/api/app/v1/estimates/'.$fx['estimate']->id];
}

/** The queued job, run as the worker would run it. */
function appAiWorkerDrafts(int $runId): void
{
    (new DraftEstimateSections($runId))->handle(app(EstimateSectionsDrafter::class));
}

/** A kitchen and a hall bath drafted and waiting for review: floor tile in both, a demo in the bath. */
function appAiReviewedRun(array $fx): array
{
    $c = $fx['catalog'];
    claudeDrafts([
        ['S1', 'Hall Bath', 'Retile the hall bath.', [[$c['Demo Bathroom'], 1], [$c['Floor Tile'], 40]]],
        ['S2', 'Kitchen', 'New kitchen floor.', [[$c['Floor Tile'], 120], [$c['Full Drywall'], 4]]],
    ], 'Two rooms.');

    $run = test()->postJson($fx['url'].'/ai-runs', [
        'inquiry' => 'Retile the hall bath and the kitchen floor.',
        'sections' => [
            ['key' => 'section-'.$fx['section']->id, 'section_id' => $fx['section']->id, 'name' => 'Hall Bath', 'include' => true],
            ['key' => 'new-1', 'name' => 'Kitchen', 'include' => true],
        ],
    ])->assertCreated()->json('data');

    return test()->getJson($fx['url'].'/ai-runs/'.$run['id'])->assertOk()->json('data');
}

it('opens on the sections a draft can add to, with nothing running and nothing asked before', function () {
    $fx = appAiEstimate();
    // The blank section a new estimate starts with is not offered: the draft's first new section takes it.
    EstimateSection::create(['estimate_id' => $fx['estimate']->id, 'name' => null, 'total' => 0]);

    $this->getJson($fx['url'].'/ai-generator')->assertOk()
        ->assertJsonPath('data.run', null)
        ->assertJsonPath('data.earlier_requests', [])
        ->assertJsonPath('data.sections', [
            ['key' => 'section-'.$fx['section']->id, 'name' => 'Hall Bath', 'section_id' => $fx['section']->id, 'detail' => '3 line items · $2,383.25'],
        ]);
});

it('starts a draft that a queued job writes, and shows the lines as they land', function () {
    Queue::fake();
    $fx = appAiEstimate();
    $c = $fx['catalog'];

    $started = $this->postJson($fx['url'].'/ai-runs', [
        'inquiry' => 'Retile the hall bath and put a new floor in the kitchen.',
        'sections' => [
            ['key' => 'section-'.$fx['section']->id, 'section_id' => $fx['section']->id, 'name' => 'Hall Bath', 'include' => true, 'note' => 'Keep the tub'],
            ['key' => 'new-1', 'name' => 'Kitchen', 'include' => true],
            ['key' => 'new-2', 'name' => 'Garage', 'include' => false],
            ['key' => 'new-3', 'name' => '', 'include' => true],
        ],
    ])->assertCreated()
        ->assertJsonPath('joined', false)
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.running', true)
        ->assertJsonPath('data.status_text', 'Starting…')
        ->assertJsonPath('data.asked_by', null)
        ->assertJsonPath('data.sections', [])
        ->json('data');

    // Nothing was drafted inside the request: the job is on the queue made for it.
    Queue::assertPushedOn('estimate-ai', DraftEstimateSections::class, fn (DraftEstimateSections $job) => $job->runId === $started['id']);
    $run = EstimateAiRun::sole();
    expect($run->user_id)->toBe($fx['user']->id)
        ->and(array_column($run->plan['sections'], 'name'))->toBe(['Hall Bath', 'Kitchen'])
        ->and($run->plan['sections'][0])->toMatchArray(['section_id' => $fx['section']->id, 'on_estimate' => true, 'note' => 'Keep the tub'])
        ->and($run->plan['listed'])->toBeTrue();

    // The screen, opened again (or by a colleague), is handed the draft being written.
    $colleague = User::factory()->create(['first_name' => 'Greg', 'primary_vendor_id' => $fx['vendor']->id]);
    $fx['vendor']->users()->attach($colleague->id, ['role_id' => 1, 'is_employed' => 1]);
    Sanctum::actingAs($colleague->refresh(), ['app']);
    $this->getJson($fx['url'].'/ai-generator')->assertOk()
        ->assertJsonPath('data.run.id', $started['id'])
        ->assertJsonPath('data.run.running', true)
        ->assertJsonPath('data.run.asked_by', 'Est');

    // A second Generate while one is running follows that one: 200, not a second run.
    $this->postJson($fx['url'].'/ai-runs', ['inquiry' => 'Something else entirely, please.'])->assertOk()->assertJsonPath('data.id', $started['id'])->assertJsonPath('joined', true);
    expect(EstimateAiRun::count())->toBe(1);
    Sanctum::actingAs($fx['user'], ['app']);

    $fake = claudeDrafts([
        ['S1', 'Hall Bath', 'Retile the hall bath.', [[$c['Demo Bathroom'], 1], [$c['Floor Tile'], 40]]],
        ['S2', 'Kitchen', 'New kitchen floor.', [[$c['Floor Tile'], 120]]],
    ], 'Two rooms.');
    appAiWorkerDrafts($started['id']);

    expect($fake->sentRequests[0]['messages'][0]['content'])->toContain('Keep the tub');

    $kitchen = EstimateSection::query()->where('estimate_id', $fx['estimate']->id)->where('name', 'Kitchen')->sole();
    $this->getJson($fx['url'].'/ai-runs/'.$started['id'])->assertOk()
        ->assertJsonPath('data.status', 'finished')
        ->assertJsonPath('data.running', false)
        ->assertJsonPath('data.error', null)
        ->assertJsonPath('data.reasoning', 'Two rooms.')
        ->assertJsonPath('data.line_count', 3)
        ->assertJsonPath('data.total', 4666)
        ->assertJsonPath('data.sections.0.section_id', $fx['section']->id)
        ->assertJsonPath('data.sections.0.name', 'Hall Bath')
        ->assertJsonPath('data.sections.0.is_new', false)
        ->assertJsonPath('data.sections.0.scope', 'Retile the hall bath.')
        // The note typed for that section alone — what went to Claude for it — is shown with it.
        ->assertJsonPath('data.sections.0.note', 'Keep the tub')
        ->assertJsonPath('data.sections.1.note', null)
        ->assertJsonPath('data.sections.0.lines.1.name', 'Floor Tile')
        ->assertJsonPath('data.sections.0.lines.1.quantity', 40)
        ->assertJsonPath('data.sections.0.lines.1.unit_type', 'sq.ft.')
        ->assertJsonPath('data.sections.0.lines.1.cost', 23.85)
        ->assertJsonPath('data.sections.0.lines.1.total', 954)
        ->assertJsonPath('data.sections.1.section_id', $kitchen->id)
        ->assertJsonPath('data.sections.1.is_new', true)
        ->assertJsonPath('data.sections.1.total', 2862)
        ->assertJsonPath('data.not_drafted', [])
        // The floor tile is in both rooms: worth offering to combine.
        ->assertJsonPath('data.repeated_item_ids', [$c['Floor Tile']->id]);

    // …and stays on the section: the estimate itself carries it beside how the AI read that section.
    $sections = collect($this->getJson($fx['url'])->assertOk()->json('data.sections'));
    expect($sections->firstWhere('id', $fx['section']->id))->toMatchArray(['ai_scope' => 'Retile the hall bath.', 'ai_note' => 'Keep the tub'])
        ->and($sections->firstWhere('id', $kitchen->id))->toMatchArray(['ai_scope' => 'New kitchen floor.', 'ai_note' => null]);

    // Until Done or Discard, the screen opens on that review.
    $this->getJson($fx['url'].'/ai-generator')->assertOk()->assertJsonPath('data.run.id', $started['id'])->assertJsonPath('data.run.running', false);
});

it('lets the AI name the sections when none is ticked, and says what a listed draft left out', function () {
    $fx = appAiEstimate();
    $c = $fx['catalog'];

    claudeDrafts([['NEW', 'Roof', 'New roof.', [[$c['Roof Shingles'], 900]]]]);
    $named = $this->postJson($fx['url'].'/ai-runs', ['inquiry' => 'Tear off and replace the whole roof.'])->assertCreated()->json('data');
    expect(EstimateAiRun::find($named['id'])->plan['listed'])->toBeFalse();
    $this->getJson($fx['url'].'/ai-runs/'.$named['id'])->assertJsonPath('data.sections.0.name', 'Roof')->assertJsonPath('data.sections.0.is_new', true);
    $this->postJson($fx['url'].'/ai-runs/'.$named['id'].'/finish')->assertOk();

    claudeDrafts([['S1', 'Kitchen', 'Kitchen.', [[$c['Full Drywall'], 4]]]]);
    $listed = $this->postJson($fx['url'].'/ai-runs', [
        'inquiry' => 'Drywall in the kitchen and the pantry.',
        'sections' => [['key' => 'a', 'name' => 'Kitchen'], ['key' => 'b', 'name' => 'Pantry']],
    ])->assertCreated()->json('data');

    $this->getJson($fx['url'].'/ai-runs/'.$listed['id'])->assertJsonPath('data.not_drafted', ['Pantry']);
});

it('reviews the draft: a quantity, a line out, a section out, Done', function () {
    $fx = appAiEstimate();
    $c = $fx['catalog'];
    $run = appAiReviewedRun($fx);
    $url = $fx['url'].'/ai-runs/'.$run['id'];
    [$bath, $kitchen] = $run['sections'];
    $tile = collect($kitchen['lines'])->firstWhere('name', 'Floor Tile');
    $drywall = collect($kitchen['lines'])->firstWhere('name', 'Full Drywall');

    // A quantity is saved to the estimate line straight away; a blank counts as one.
    $this->patchJson($url.'/lines/'.$tile['id'], ['quantity' => 100])->assertOk()
        ->assertJsonPath('data.sections.1.lines.0.quantity', 100)
        ->assertJsonPath('data.sections.1.lines.0.total', 2385);
    expect((float) EstimateLineItem::find($tile['id'])->total)->toBe(2385.0);
    $this->patchJson($url.'/lines/'.$drywall['id'], ['quantity' => ''])->assertOk()->assertJsonPath('data.sections.1.lines.1.quantity', 1);

    // A line that is not this draft's — the estimate's own first line — cannot be named.
    $own = EstimateLineItem::query()->where('section_id', $fx['section']->id)->orderBy('id')->first();
    $this->patchJson($url.'/lines/'.$own->id, ['quantity' => 99])->assertNotFound();
    $this->deleteJson($url.'/lines/'.$own->id)->assertNotFound();
    expect((float) $own->fresh()->quantity)->toBe(1.0);

    // A drafted line never wanted is gone for good.
    $this->deleteJson($url.'/lines/'.$drywall['id'])->assertOk()->assertJsonPath('data.line_count', 3);
    expect(EstimateLineItem::withTrashed()->find($drywall['id']))->toBeNull();

    // The kitchen out: the section the draft made goes with its lines.
    $this->deleteJson($url.'/sections/'.$kitchen['section_id'])->assertOk()
        ->assertJsonPath('data.line_count', 2)
        ->assertJsonCount(1, 'data.sections');
    expect(EstimateSection::withTrashed()->find($kitchen['section_id']))->toBeNull();
    $this->deleteJson($url.'/sections/'.$kitchen['section_id'])->assertNotFound();

    $this->postJson($url.'/finish')->assertOk()->assertExactJson(['data' => ['finished' => true, 'line_count' => 2, 'section_count' => 1, 'repeated_item_ids' => []]]);
    expect(EstimateAiDraft::query()->where('run_id', $run['id'])->where('section_id', $bath['section_id'])->value('status'))->toBe(EstimateAiDraft::FINISHED);

    // Closed with Done: the screen opens on the form again, and the request is listed to use again.
    $this->getJson($fx['url'].'/ai-generator')->assertOk()
        ->assertJsonPath('data.run', null)
        ->assertJsonPath('data.earlier_requests.0.id', $run['id'])
        ->assertJsonPath('data.earlier_requests.0.on_estimate', 2)
        ->assertJsonPath('data.earlier_requests.0.sections', 2);
});

it('discards the draft when its last section is taken out, and on Discard', function () {
    $fx = appAiEstimate();
    $run = appAiReviewedRun($fx);
    $url = $fx['url'].'/ai-runs/'.$run['id'];

    $this->deleteJson($url.'/sections/'.$run['sections'][0]['section_id'])->assertOk()->assertJsonPath('data.line_count', 2);
    $this->deleteJson($url.'/sections/'.$run['sections'][1]['section_id'])->assertOk()->assertJsonPath('data.line_count', 0)->assertJsonPath('data.sections', []);

    // The estimate is as it was: its own three lines, its one section.
    expect(EstimateLineItem::query()->where('estimate_id', $fx['estimate']->id)->count())->toBe(3)
        ->and(EstimateSection::query()->where('estimate_id', $fx['estimate']->id)->pluck('name')->all())->toBe(['Hall Bath'])
        ->and(EstimateAiDraft::query()->where('run_id', $run['id'])->pluck('status')->unique()->all())->toBe([EstimateAiDraft::DISCARDED]);

    $again = appAiReviewedRun($fx);
    $this->postJson($fx['url'].'/ai-runs/'.$again['id'].'/discard')->assertOk()->assertExactJson(['data' => ['discarded' => true, 'kept_combined' => 0]]);
    expect(EstimateLineItem::query()->where('estimate_id', $fx['estimate']->id)->count())->toBe(3);
    $this->getJson($fx['url'].'/ai-generator')->assertJsonPath('data.run', null);
});

it('drafts one section again with a note, keeping the rest of the draft', function () {
    $fx = appAiEstimate();
    $c = $fx['catalog'];
    $run = appAiReviewedRun($fx);
    $kitchen = $run['sections'][1];

    $fake = claudeDrafts([['S1', 'Kitchen', 'Kitchen with an island.', [[$c['Full Drywall'], 9], [$c['New GFCI Location'], 6]]]]);
    $again = $this->postJson($fx['url'].'/ai-runs/'.$run['id'].'/sections/'.$kitchen['section_id'].'/redo', ['note' => 'Full new kitchen with an island'])
        ->assertCreated()
        ->assertJsonPath('data.redo_of', $run['id'])
        ->json('data');

    expect($again['id'])->not->toBe($run['id'])
        ->and($fake->sentRequests[0]['messages'][0]['content'])->toContain('Full new kitchen with an island');

    // Its review is that one section, drafted anew in the section that was there; the bath's lines stand, kept.
    $this->getJson($fx['url'].'/ai-runs/'.$again['id'])->assertOk()
        ->assertJsonCount(1, 'data.sections')
        ->assertJsonPath('data.sections.0.section_id', $kitchen['section_id'])
        ->assertJsonPath('data.sections.0.lines.0.name', 'Full Drywall')
        ->assertJsonPath('data.sections.0.lines.0.quantity', 9);
    expect(EstimateLineItem::query()->where('section_id', $kitchen['section_id'])->pluck('name')->all())->toBe(['Full Drywall', 'New GFCI Location'])
        ->and(EstimateLineItem::query()->where('section_id', $fx['section']->id)->count())->toBe(5);

    // A section a signature holds is not drafted again.
    $this->postJson($fx['url'].'/ai-runs/'.$again['id'].'/sections/999999/redo')->assertStatus(422);
});

it('keeps the review closed while the draft is still being written, and can stop it', function () {
    Queue::fake();
    $fx = appAiEstimate();
    $run = $this->postJson($fx['url'].'/ai-runs', ['inquiry' => 'Retile the hall bath floor.'])->assertCreated()->json('data');
    $url = $fx['url'].'/ai-runs/'.$run['id'];

    $this->patchJson($url.'/lines/1', ['quantity' => 2])->assertStatus(422)->assertJsonPath('message', 'The draft is still being written.');
    $this->deleteJson($url.'/lines/1')->assertStatus(422);
    $this->deleteJson($url.'/sections/'.$fx['section']->id)->assertStatus(422);
    $this->postJson($url.'/sections/'.$fx['section']->id.'/redo')->assertStatus(422);
    $this->postJson($url.'/finish')->assertStatus(422);
    $this->postJson($url.'/discard')->assertStatus(422);

    $this->postJson($url.'/stop')->assertOk()->assertJsonPath('data.status', 'stopping')->assertJsonPath('data.running', true)->assertJsonPath('data.status_text', 'Stopping…');

    // The worker lets go without drafting anything.
    appAiWorkerDrafts($run['id']);
    $this->getJson($url)->assertOk()->assertJsonPath('data.status', 'stopped')->assertJsonPath('data.running', false)->assertJsonPath('data.error', 'The draft was stopped.');
});

it('writes off a draft nobody has heard from, so the screen does not wait on it for ever', function () {
    Queue::fake();
    $fx = appAiEstimate();
    $run = $this->postJson($fx['url'].'/ai-runs', ['inquiry' => 'Retile the hall bath floor.'])->assertCreated()->json('data');

    $this->travel(6)->minutes();

    $this->getJson($fx['url'].'/ai-runs/'.$run['id'])->assertOk()
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.running', false)
        ->assertJsonPath('data.error', 'The draft stopped before it finished. Try again.');
});

it('stops rather than guesses when a ticked section is no longer on the estimate', function () {
    Queue::fake();
    $fx = appAiEstimate();
    $other = Estimate::withoutGlobalScopes()->create(['project_id' => $fx['estimate']->project_id, 'belongs_to_vendor_id' => $fx['vendor']->id]);
    $foreign = EstimateSection::create(['estimate_id' => $other->id, 'name' => 'Someone Else\'s Kitchen', 'total' => 0]);

    $this->postJson($fx['url'].'/ai-runs', [
        'inquiry' => 'Add an outlet to that section.',
        'sections' => [['key' => 'section-'.$foreign->id, 'section_id' => $foreign->id, 'name' => 'Kitchen', 'include' => true]],
    ])->assertStatus(422)
        ->assertJsonPath('message', 'A ticked section is no longer on this estimate. The list has been refreshed: check it and generate again.')
        ->assertJsonPath('sections.0.section_id', $fx['section']->id);

    expect(EstimateAiRun::count())->toBe(0);
    Queue::assertNotPushed(DraftEstimateSections::class);
});

it('uses an earlier request again: its text, its sections and the rooms measured for them', function () {
    $fx = appAiEstimate();
    $c = $fx['catalog'];
    // A request made on the website from a room scan: the kitchen listed, the hall bath measured but left out.
    $earlier = EstimateAiRun::create([
        'vendor_id' => $fx['vendor']->id, 'estimate_id' => $fx['estimate']->id, 'user_id' => $fx['user']->id, 'status' => EstimateAiRun::FINISHED,
        'inquiry' => 'Remodel the kitchen, new floor.', 'finished_at' => now()->subDays(2),
        'plan' => [
            'listed' => true,
            'sections' => [['key' => 'S1', 'name' => 'Kitchen & Pantry', 'section_id' => null, 'on_estimate' => false, 'note' => 'island stays',
                'rooms' => [['name' => 'Kitchen', 'floor_sqft' => 234.4, 'dimensions' => "14' x 16'"]]]],
            'left_out' => [['name' => 'Hall Bath', 'rooms' => [['name' => 'Hall Bath', 'floor_sqft' => 45.0]]]],
        ],
        'floorplan' => ['floor_sqft' => 279.4],
    ]);

    $this->getJson($fx['url'].'/ai-generator')->assertJsonPath('data.earlier_requests.0.id', $earlier->id)
        ->assertJsonPath('data.earlier_requests.0.outcome', 'its draft is no longer on the estimate');

    $this->getJson($fx['url'].'/ai-requests/'.$earlier->id)->assertOk()->assertExactJson(['data' => [
        'id' => $earlier->id,
        'inquiry' => 'Remodel the kitchen, new floor.',
        'sections' => [
            ['key' => "saved-{$earlier->id}-S1", 'name' => 'Kitchen & Pantry', 'include' => true, 'note' => 'island stays', 'detail' => "234.4 sq.ft. · 14' x 16' · saved with the earlier request", 'sqft' => 234.4],
            ['key' => "saved-{$earlier->id}-L1", 'name' => 'Hall Bath', 'include' => false, 'note' => '', 'detail' => '45 sq.ft. · saved with the earlier request', 'sqft' => 45],
        ],
        'has_measurements' => true,
        'standing' => 0,
    ]]);

    $fake = claudeDrafts([['S1', 'Kitchen & Pantry', 'Kitchen.', [[$c['Floor Tile'], 234]]]]);
    $run = $this->postJson($fx['url'].'/ai-runs', [
        'inquiry' => 'Remodel the kitchen, new floor.',
        'reuse_run_id' => $earlier->id,
        'sections' => [
            ['key' => "saved-{$earlier->id}-S1", 'name' => 'Kitchen & Pantry', 'include' => true, 'note' => 'island stays'],
            // The bath is put into the kitchen's section this time.
            ['key' => "saved-{$earlier->id}-L1", 'name' => 'Hall Bath', 'include' => true, 'merged_into' => "saved-{$earlier->id}-S1"],
            // A row claiming another request's rooms brings none: only the request being used again is read.
            ['key' => 'saved-999-S1', 'name' => 'Attic', 'include' => true],
        ],
    ])->assertCreated()->json('data');

    $prompt = $fake->sentRequests[0]['messages'][0]['content'];
    // The two rooms drafted as one section: their measurements added up, and said to be two rooms.
    expect($prompt)->toContain('"scanned_as":"Kitchen + Hall Bath"')->toContain('"floor_sqft":279.4')->toContain('island stays');

    $plan = EstimateAiRun::find($run['id'])->plan;
    expect(array_column($plan['sections'], 'name'))->toBe(['Kitchen & Pantry', 'Attic'])
        ->and(array_column($plan['sections'][0]['rooms'], 'name'))->toBe(['Kitchen', 'Hall Bath'])
        ->and($plan['sections'][1]['rooms'])->toBe([])
        // No plan attached now: the measurements saved with the request stand.
        ->and(EstimateAiRun::find($run['id'])->floorplan)->toBe(['floor_sqft' => 279.4]);

    // Its draft now stands on the estimate: using it again warns, and the draft can be taken off first.
    $this->postJson($fx['url'].'/ai-runs/'.$run['id'].'/finish')->assertOk();
    $this->getJson($fx['url'].'/ai-requests/'.$run['id'])->assertOk()->assertJsonPath('data.standing', 1);
    $this->postJson($fx['url'].'/ai-requests/'.$run['id'].'/remove-draft')->assertOk()->assertExactJson(['data' => ['removed' => true, 'signed' => 0, 'kept_combined' => 0]]);
    expect(EstimateLineItem::query()->where('estimate_id', $fx['estimate']->id)->count())->toBe(3);
    $this->getJson($fx['url'].'/ai-requests/'.$run['id'])->assertJsonPath('data.standing', 0);
});

it('puts plan pictures aside at the tap and reads them in the job, not in the request', function () {
    Queue::fake();
    Storage::fake('local');
    config()->set('services.ocr_space.api_key', 'key');
    config()->set('services.ocr_space.endpoint', 'https://ocr.test/parse');
    Http::fake(['https://ocr.test/*' => Http::response(['ParsedResults' => [['ParsedText' => "KITCHEN 14' x 16'\nFloor area: 224 sq ft"]]])]);
    $fx = appAiEstimate();
    $c = $fx['catalog'];

    // Multipart: the rows travel as one JSON string beside the files.
    $run = $this->post($fx['url'].'/ai-runs', [
        'inquiry' => 'New floor in the kitchen from this plan.',
        'sections' => json_encode([['key' => 'new-1', 'name' => 'Kitchen', 'include' => true]]),
        'floorplans' => [UploadedFile::fake()->image('first-floor.jpg', 1200, 900)],
        'labels' => ['First floor'],
    ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.running', true)->json('data');

    // 2026-11-13: reading a picture inside the request held it for up to 20 seconds a file.
    Http::assertNothingSent();
    $saved = EstimateAiRun::find($run['id']);
    expect($saved->floorplan)->toBeNull()
        ->and($saved->plan['plans'])->toHaveCount(1)
        ->and($saved->plan['plans'][0])->toMatchArray(['label' => 'First floor', 'filename' => 'first-floor.jpg'])
        ->and(array_column($saved->plan['sections'], 'name'))->toBe(['Kitchen']);
    Storage::disk('local')->assertExists($saved->plan['plans'][0]['path']);

    // The worker has taken it and is reading: the screen says so.
    $saved->forceFill(['status' => EstimateAiRun::DRAFTING, 'started_at' => now(), 'heartbeat_at' => now()])->save();
    $this->getJson($fx['url'].'/ai-runs/'.$run['id'])->assertJsonPath('data.status_text', 'Reading the plan…');
    $saved->forceFill(['status' => EstimateAiRun::QUEUED])->save();

    $fake = claudeDrafts([['S1', 'Kitchen', 'Kitchen floor.', [[$c['Floor Tile'], 224]]]]);
    appAiWorkerDrafts($run['id']);

    Http::assertSentCount(1);
    $read = EstimateAiRun::find($run['id']);
    expect($read->status)->toBe(EstimateAiRun::FINISHED)
        ->and((float) $read->floorplan['floor_sqft'])->toBe(224.0)
        ->and($read->plan)->not->toHaveKey('plans')
        ->and($fake->sentRequests[0]['messages'][0]['content'])->toContain('224');
    // Read, and gone from the disk.
    Storage::disk('local')->assertMissing($saved->plan['plans'][0]['path']);
    expect(Storage::disk('local')->allFiles('estimate-ai-plans'))->toBe([]);
});

it('leaves no plan pictures behind when the draft is stopped or never starts', function () {
    Queue::fake();
    Storage::fake('local');
    $fx = appAiEstimate();
    $attach = fn () => ['inquiry' => 'New floor in the kitchen from this plan.', 'floorplans' => [UploadedFile::fake()->image('plan.png')]];

    // Stopped before a worker took it.
    $run = $this->post($fx['url'].'/ai-runs', $attach(), ['Accept' => 'application/json'])->assertCreated()->json('data');
    expect(Storage::disk('local')->allFiles('estimate-ai-plans'))->toHaveCount(1);
    $this->postJson($fx['url'].'/ai-runs/'.$run['id'].'/stop')->assertOk();
    appAiWorkerDrafts($run['id']);
    expect(Storage::disk('local')->allFiles('estimate-ai-plans'))->toBe([])
        ->and(EstimateAiRun::find($run['id'])->status)->toBe(EstimateAiRun::STOPPED);

    // Never taken by a worker: written off by whoever was following it.
    $run = $this->post($fx['url'].'/ai-runs', $attach(), ['Accept' => 'application/json'])->assertCreated()->json('data');
    $this->travel(6)->minutes();
    $this->getJson($fx['url'].'/ai-runs/'.$run['id'])->assertJsonPath('data.status', 'failed');
    expect(Storage::disk('local')->allFiles('estimate-ai-plans'))->toBe([]);

    // A second tap while a draft runs follows that draft: its own pictures are not kept.
    $this->travelBack();
    $first = $this->post($fx['url'].'/ai-runs', $attach(), ['Accept' => 'application/json'])->assertCreated()->json('data');
    $this->post($fx['url'].'/ai-runs', $attach(), ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.id', $first['id']);
    expect(Storage::disk('local')->allFiles('estimate-ai-plans'))->toHaveCount(1);
});

it('validates what is sent', function () {
    Queue::fake();
    $fx = appAiEstimate();

    $this->postJson($fx['url'].'/ai-runs', ['inquiry' => 'too short'])->assertStatus(422)->assertJsonValidationErrors('inquiry');
    $this->postJson($fx['url'].'/ai-runs', [
        'inquiry' => 'Retile the hall bath floor.',
        'sections' => [['key' => 'a', 'name' => str_repeat('n', 61)], ['key' => 'a', 'name' => 'Twice'], ['name' => 'No key', 'note' => str_repeat('x', 301)]],
    ])->assertStatus(422)->assertJsonValidationErrors(['sections.0.name', 'sections.0.key', 'sections.2.key', 'sections.2.note']);
    $this->post($fx['url'].'/ai-runs', ['inquiry' => 'Retile the hall bath floor.', 'floorplans' => [UploadedFile::fake()->create('plan.exe', 10)]], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors('floorplans.0');

    expect(EstimateAiRun::count())->toBe(0);
});

it('is for whoever may change the estimate, and only ever reaches that estimate\'s own runs', function () {
    $fx = appAiEstimate();
    $run = appAiReviewedRun($fx);
    $line = $run['sections'][0]['lines'][0]['id'];

    // Another estimate of the same company: the run is not its, whatever the address says.
    $other = Estimate::withoutGlobalScopes()->create(['project_id' => $fx['estimate']->project_id, 'belongs_to_vendor_id' => $fx['vendor']->id]);
    $otherUrl = '/api/app/v1/estimates/'.$other->id;
    $this->getJson($otherUrl.'/ai-runs/'.$run['id'])->assertNotFound();
    $this->getJson($otherUrl.'/ai-requests/'.$run['id'])->assertNotFound();
    $this->postJson($otherUrl.'/ai-requests/'.$run['id'].'/remove-draft')->assertNotFound();
    $this->patchJson($otherUrl.'/ai-runs/'.$run['id'].'/lines/'.$line, ['quantity' => 9])->assertNotFound();
    $this->postJson($otherUrl.'/ai-runs/'.$run['id'].'/discard')->assertNotFound();
    $this->postJson($otherUrl.'/ai-runs/'.$run['id'].'/finish')->assertNotFound();
    $this->postJson($otherUrl.'/ai-runs', ['inquiry' => 'Use that request again.', 'reuse_run_id' => $run['id'], 'sections' => [['key' => "saved-{$run['id']}-S1", 'name' => 'Hall Bath']]])->assertCreated();
    expect(EstimateAiRun::query()->where('estimate_id', $other->id)->sole()->plan['sections'][0]['rooms'])->toBe([]);

    // A Member may not change estimates, on the website or here.
    $member = User::factory()->create(['primary_vendor_id' => $fx['vendor']->id]);
    $fx['vendor']->users()->attach($member->id, ['role_id' => 2, 'is_employed' => 1]);
    Sanctum::actingAs($member->refresh(), ['app']);
    $this->getJson($fx['url'].'/ai-generator')->assertForbidden();
    $this->postJson($fx['url'].'/ai-runs', ['inquiry' => 'Retile the hall bath floor.'])->assertForbidden();
    $this->getJson($fx['url'].'/ai-runs/'.$run['id'])->assertForbidden();
    $this->deleteJson($fx['url'].'/ai-runs/'.$run['id'].'/lines/'.$line)->assertForbidden();
    $this->postJson($fx['url'].'/ai-runs/'.$run['id'].'/discard')->assertForbidden();
    $this->getJson($fx['url'].'/combine-items')->assertForbidden();
    $this->postJson($fx['url'].'/combine-items', ['keys' => ['1'], 'section_name' => 'Flooring'])->assertForbidden();
    expect(EstimateLineItem::find($line))->not->toBeNull();

    // Another company cannot see the estimate at all.
    $outsider = estimateAdmin(claudeEstimateFixture());
    Sanctum::actingAs($outsider['user'], ['app']);
    $this->getJson($fx['url'].'/ai-generator')->assertForbidden();
    $this->getJson($fx['url'].'/ai-runs/'.$run['id'])->assertForbidden();
});

it('leaves a signed contract\'s lines where they are', function () {
    $fx = appAiEstimate();
    $run = appAiReviewedRun($fx);
    $url = $fx['url'].'/ai-runs/'.$run['id'];
    $kitchen = $run['sections'][1];
    $this->postJson($url.'/finish')->assertOk();

    // Signed: the draft is now part of the contract.
    $this->travel(1)->hours();
    EstimateSignature::create([
        'estimate_id' => $fx['estimate']->id, 'user_id' => $fx['user']->id, 'signer_name' => 'Est Imator', 'signer_email' => $fx['user']->email,
        'signature_data' => 'data:image/png;base64,x', 'signature_type' => 'draw', 'ip_address' => '127.0.0.1', 'user_agent' => 'pest', 'document_hash' => 'h', 'signed_at' => now(),
    ]);
    expect(EstimateSection::find($kitchen['section_id'])->isLocked())->toBeTrue();

    $line = $kitchen['lines'][0]['id'];
    $this->patchJson($url.'/lines/'.$line, ['quantity' => 1])->assertNotFound();
    $this->deleteJson($url.'/lines/'.$line)->assertNotFound();
    $this->postJson($url.'/sections/'.$kitchen['section_id'].'/redo')->assertStatus(422);
    expect((float) EstimateLineItem::find($line)->quantity)->toBe(120.0);
});

it('combines the items the rooms share, a line per unit price, and never changes the total', function () {
    $fx = appAiEstimate();
    $c = $fx['catalog'];
    $run = appAiReviewedRun($fx);
    $this->postJson($fx['url'].'/ai-runs/'.$run['id'].'/finish')->assertOk()->assertJsonPath('data.repeated_item_ids', [$c['Floor Tile']->id]);

    // The kitchen's tile was priced by hand at $20; the bath has 45 + 40 sq.ft. at the catalog's $23.85.
    $kitchenTile = collect($run['sections'][1]['lines'])->firstWhere('name', 'Floor Tile')['id'];
    EstimateLineItem::query()->whereKey($kitchenTile)->update(['cost' => 20, 'total' => 2400]);
    $before = (float) EstimateLineItem::query()->where('estimate_id', $fx['estimate']->id)->sum('total');

    $offered = $this->getJson($fx['url'].'/combine-items')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
    expect($offered)->toMatchArray(['key' => (string) $c['Floor Tile']->id, 'line_item_id' => $c['Floor Tile']->id, 'name' => 'Floor Tile', 'category' => 'Tiles', 'unit_type' => 'sq.ft.', 'quantity' => 205, 'total' => 4427.25])
        ->and($offered['becomes'])->toEqual([['quantity' => 85, 'cost' => 23.85, 'total' => 2027.25], ['quantity' => 120, 'cost' => 20, 'total' => 2400]])
        ->and(array_column($offered['lines'], 'section'))->toBe(['Hall Bath', 'Hall Bath', 'Kitchen']);

    $this->postJson($fx['url'].'/combine-items', ['keys' => [], 'section_name' => ''])->assertStatus(422)->assertJsonValidationErrors(['keys', 'section_name']);
    $this->postJson($fx['url'].'/combine-items', ['keys' => ['999999'], 'section_name' => 'Flooring'])->assertStatus(422)->assertJsonPath('message', 'Those items can no longer be combined. Reload the list.');

    $result = $this->postJson($fx['url'].'/combine-items', ['keys' => [$offered['key'], '999999'], 'section_name' => 'Flooring'])->assertOk()->json('data');
    $flooring = EstimateSection::query()->where('estimate_id', $fx['estimate']->id)->where('name', 'Flooring')->sole();

    expect($result)->toBe(['section' => ['id' => $flooring->id, 'name' => 'Flooring'], 'combined' => 1, 'lines' => 2, 'removed' => 3, 'emptied' => []])
        ->and(EstimateLineItem::query()->where('section_id', $flooring->id)->orderBy('id')->get()->map(fn (EstimateLineItem $line) => [(float) $line->quantity, (float) $line->cost, (float) $line->total])->all())
        ->toBe([[85.0, 23.85, 2027.25], [120.0, 20.0, 2400.0]])
        ->and((float) EstimateLineItem::query()->where('estimate_id', $fx['estimate']->id)->sum('total'))->toEqualWithDelta($before, 0.001);

    $this->getJson($fx['url'].'/combine-items')->assertOk()->assertExactJson(['data' => []]);
});
