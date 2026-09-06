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

/**
 * The estimate generator on Claude. The SDK call is the one thing faked:
 * everything up to it (what would be sent) and after it (what the estimator
 * sees) runs for real.
 */
class FakeClaudeEstimateService extends EstimateAIService
{
    public array $sentRequests = [];

    public function __construct(public array $reply = ['text' => '{"reasoning":"","line_items":[]}', 'stop_reason' => 'end_turn'])
    {
        parent::__construct();
    }

    protected function complete(array $request): array
    {
        $this->sentRequests[] = $request;

        return [
            'text' => $this->reply['text'],
            'stop_reason' => $this->reply['stop_reason'],
            'model' => 'claude-opus-5',
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 300, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 900],
        ];
    }
}

function claudeEstimateFixture(): array
{
    $vendor = Vendor::query()->create([
        'business_name' => 'GS Construction', 'business_type' => 'Sub', 'business_email' => 'gc@example.test',
        'address' => '123 Main St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);
    $user = User::query()->create([
        'first_name' => 'Est', 'last_name' => 'Imator', 'email' => 'claude-est-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224666####'), 'password' => bcrypt('password'),
    ]);
    $user->forceFill(['primary_vendor_id' => $vendor->id])->saveQuietly();
    test()->actingAs($user);

    $catalog = [];
    foreach ([
        ['Demo Bathroom', 'Demolition', 'Demo', 'no_unit', 850],
        ['Shower Rough Plumbing', 'Plumbing', 'Rough', 'pieces', 1050],
        ['New GFCI Location', 'Electrical', 'Rough', 'pieces', 230],
        ['Floor Tile', 'Tiles', 'Floor', 'sq.ft.', 23.85],
        ['Full Drywall', 'Drywall', 'Install', 'pieces', 205],
        ['Roof Shingles', 'Roofing', 'Install', 'sq.ft.', 12],
        ['Cement Boards', 'Tiles', 'Prep', 'pieces', 38],
    ] as [$name, $category, $sub, $unit, $cost]) {
        $catalog[$name] = LineItem::withoutGlobalScopes()->create([
            'name' => $name, 'category' => $category, 'sub_category' => $sub, 'unit_type' => $unit, 'cost' => $cost,
            'desc' => "Catalog description of {$name}", 'belongs_to_vendor_id' => $vendor->id,
        ]);
    }

    // One real past Hall Bath section — the example the model should be shown.
    $project = Project::query()->create([
        'project_name' => 'Hall Bath',
        'client_id' => Client::query()->create(['business_name' => 'Owner', 'address' => '1 Oak St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601'])->id,
        'address' => '1 Oak St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);
    $estimate = Estimate::withoutGlobalScopes()->create(['project_id' => $project->id, 'belongs_to_vendor_id' => $vendor->id]);
    $section = EstimateSection::create(['estimate_id' => $estimate->id, 'name' => 'Hall Bath', 'total' => 0]);
    foreach ([['Demo Bathroom', 1], ['Floor Tile', 45], ['New GFCI Location', 2]] as [$name, $qty]) {
        EstimateLineItem::create([
            'estimate_id' => $estimate->id, 'section_id' => $section->id, 'line_item_id' => $catalog[$name]->id,
            'name' => $name, 'category' => $catalog[$name]->category, 'sub_category' => $catalog[$name]->sub_category,
            'unit_type' => $catalog[$name]->unit_type, 'quantity' => $qty, 'cost' => $catalog[$name]->cost, 'total' => $qty * $catalog[$name]->cost,
        ]);
    }

    return compact('vendor', 'user', 'catalog', 'estimate', 'section');
}

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

    // The catalog offered is the bathroom-relevant slice, and the schema's enum is exactly that slice.
    $enum = $req['outputConfig']['format']['schema']['properties']['line_items']['items']['properties']['line_item_id']['enum'];
    expect($enum)->toContain($fx['catalog']['Floor Tile']->id)
        ->and($enum)->toContain($fx['catalog']['Shower Rough Plumbing']->id)
        ->and($enum)->not->toContain($fx['catalog']['Roof Shingles']->id)
        ->and($userText)->not->toContain('Roof Shingles');

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
        ->and(collect($result['line_items'])->pluck('name')->all())->toBe(['Demo Bathroom', 'Floor Tile'])
        // Lump sum → quantity 1 whatever the model said.
        ->and($result['line_items'][0]['quantity'])->toBe(1.0)
        ->and($result['line_items'][0]['cost'])->toBe(850.0)
        ->and($result['line_items'][1]['quantity'])->toBe(48.5)
        ->and($result['line_items'][1]['cost'])->toBe(23.85)
        ->and($result['line_items'][1]['desc'])->toBe('Porcelain 12x24 on the floor')
        ->and($result['line_items'][1]['notes'])->toBe('confirm sq ft');
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
        ->and($created[0]->desc)->toBe('Ceiling and one wall')
        ->and((float) $section->fresh()->total)->toBe(820.0);
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

it('never copies the catalog\'s internal notes onto the estimate', function () {
    $fx = claudeEstimateFixture();
    $c = $fx['catalog'];
    $c['Full Drywall']->forceFill(['notes' => 'INTERNAL: crew of two, order 10% extra'])->save();
    $section = EstimateSection::create(['estimate_id' => $fx['estimate']->id, 'name' => 'Powder Room', 'total' => 0]);

    $created = (new EstimateAIService())->applyToEstimate($fx['estimate'], $section, [
        ['line_item_id' => $c['Full Drywall']->id, 'quantity' => 2, 'notes' => null],
        ['line_item_id' => $c['Full Drywall']->id, 'quantity' => 1, 'notes' => 'Ceiling only'],
    ]);

    expect($created[0]->notes)->toBeNull()
        ->and($created[1]->notes)->toBe('Ceiling only');
});

it('redacts lowercase, all-caps and hyphenated-number addresses too', function () {
    expect(EstimateAIService::redact('meet at 1463 w winnetka st tomorrow'))->toBe('meet at [address] tomorrow')
        ->and(EstimateAIService::redact('1463-65 W WINNETKA ST, two flat'))->toBe('[address], two flat')
        ->and(EstimateAIService::redact('300-4 pieces of drywall needed'))->toBe('300-4 pieces of drywall needed');
});

