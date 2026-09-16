<?php

/**
 * Shared fixtures for the AI estimate generator's tests: the faked Claude
 * call, a company with a catalog and one past section, and the generator
 * component open on it.
 */

use App\Livewire\Estimates\EstimateAIGenerator;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateLineItem;
use App\Models\EstimateSection;
use App\Models\LineItem;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use App\Services\EstimateAIService;
use Livewire\Livewire;

if (class_exists('FakeClaudeEstimateService')) {
    return;
}

/**
 * The estimate generator on Claude. The SDK call is the one thing faked:
 * everything up to it (what would be sent) and after it (what the estimator
 * sees) runs for real.
 */
class FakeClaudeEstimateService extends EstimateAIService
{
    public array $sentRequests = [];

    /** Text pieces handed to the caller when the reply was streamed. */
    public array $streamedChunks = [];

    public function __construct(public array $reply = ['text' => '{"reasoning":"","line_items":[]}', 'stop_reason' => 'end_turn'])
    {
        parent::__construct();
    }

    protected function complete(array $request, ?callable $onText = null): array
    {
        $this->sentRequests[] = $request;

        if ($onText !== null) {
            // The reply arrives the way the API streams it: in small pieces that
            // split objects, keys and numbers wherever they fall.
            foreach (str_split($this->reply['text'], 7) as $chunk) {
                $this->streamedChunks[] = $chunk;
                $onText($chunk);
            }
        }

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

/**
 * Runs the callback with Livewire's streamed output captured. Livewire flushes
 * each streamed chunk into the next output buffer, so two buffers are needed
 * to catch it here.
 */
function capturingLivewireStream(callable $callback): string
{
    ob_start();
    ob_start();
    try {
        $callback();
    } finally {
        ob_end_flush();
        $output = ob_get_clean();
    }

    return (string) $output;
}

/**
 * A company admin with the generator open on the fixture estimate, and Claude
 * faked to draft a demo and forty square feet of floor tile.
 */
function draftingGenerator(): array
{
    $fx = claudeEstimateFixture();
    $c = $fx['catalog'];
    // Only a company admin may change an estimate.
    $fx['user']->vendors()->attach($fx['vendor']->id, ['role_id' => 1, 'is_employed' => 1]);
    $fx['user']->refresh();

    app()->instance(EstimateAIService::class, new FakeClaudeEstimateService([
        'text' => json_encode(['reasoning' => 'Hall bath.', 'line_items' => [
            ['line_item_id' => $c['Demo Bathroom']->id, 'quantity' => 1],
            ['line_item_id' => $c['Floor Tile']->id, 'quantity' => 40],
        ]]),
        'stop_reason' => 'end_turn',
    ]));

    $component = Livewire::test(EstimateAIGenerator::class, ['estimate' => $fx['estimate']])
        ->set('sectionId', $fx['section']->id)
        ->set('inquiry', 'Hall bath: new tile floor and shower.');

    return [$fx, $component];
}

function draftedLines(array $fx)
{
    return EstimateLineItem::query()->where('section_id', $fx['section']->id)->where('id', '>', 3)->orderBy('id')->get();
}

