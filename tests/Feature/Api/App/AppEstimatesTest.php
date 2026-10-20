<?php

use App\Jobs\SendEstimateEmailJob;
use App\Models\Client;
use App\Models\CompanyEmail;
use App\Models\EmailTemplate;
use App\Models\Estimate;
use App\Models\EstimateLineItem;
use App\Models\EstimateSection;
use App\Models\LineItem;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use App\Services\EstimateAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/estimate-ai-fixtures.php';

beforeEach(function () {
    // No real Meilisearch round trips from fixture setup — same reasoning as
    // AppPaymentsTest.
    config(['scout.driver' => null]);
});

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * AppAuthTest's appTeamUser(). $role is the vendor_user pivot's role_id
 * (1 = Admin, 2 = Member) — EstimatePolicy and LineItemPolicy both gate
 * everything on it.
 *
 * @return array{vendor: Vendor, user: User}
 */
function appEstimatesTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction', 'business_type' => 'Sub']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

/** A client linked to $vendor through client_vendor (ClientScope). */
function appEstimatesClient(Vendor $vendor, array $overrides = []): Client
{
    $client = Client::factory()->create($overrides);
    $client->vendors()->attach($vendor->id);

    return $client;
}

/** A project owned by $vendor, for $client, linked through project_vendor (ProjectScope). */
function appEstimatesProject(Vendor $vendor, Client $client, array $overrides = []): Project
{
    $project = Project::withoutEvents(fn () => Project::create(array_merge([
        'project_name' => 'Kitchen Remodel',
        'client_id' => $client->id,
        'address' => '123 Main St',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => '60062',
        'belongs_to_vendor_id' => $vendor->id,
    ], $overrides)));

    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    return $project;
}

/** A blank estimate (no sections), same shape App\Livewire\Estimates\EstimateCreate produces. */
function appEstimatesRow(Vendor $vendor, Project $project, array $overrides = []): Estimate
{
    return Estimate::create(array_merge([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
    ], $overrides));
}

function appEstimatesSection(Estimate $estimate, array $overrides = []): EstimateSection
{
    return EstimateSection::create(array_merge([
        'estimate_id' => $estimate->id,
        'order' => 0,
        'name' => 'Kitchen',
        'total' => 0,
    ], $overrides));
}

/**
 * A catalog line item for $vendor. Must run after Sanctum::actingAs() for
 * that vendor's admin — LineItemObserver::creating() stamps
 * belongs_to_vendor_id from auth()->user()->vendor, same as the web.
 */
function appEstimatesCatalogItem(array $overrides = []): LineItem
{
    return LineItem::create(array_merge([
        'name' => 'Floor Tile',
        'desc' => 'Porcelain floor tile, installed',
        'notes' => null,
        'category' => 'Tiles',
        'sub_category' => 'Floor',
        'unit_type' => 'sq.ft.',
        'cost' => 12.50,
    ], $overrides));
}

function appEstimatesLineItemRow(Estimate $estimate, EstimateSection $section, LineItem $catalogItem, array $overrides = []): EstimateLineItem
{
    return EstimateLineItem::create(array_merge([
        'estimate_id' => $estimate->id,
        'section_id' => $section->id,
        'line_item_id' => $catalogItem->id,
        'name' => $catalogItem->name,
        'category' => $catalogItem->category,
        'sub_category' => $catalogItem->sub_category,
        'unit_type' => $catalogItem->unit_type,
        'quantity' => 10,
        'cost' => $catalogItem->cost,
        'total' => 125.00,
        'desc' => $catalogItem->desc,
        'order' => 1,
    ], $overrides));
}

// --- Auth -------------------------------------------------------------

it('needs a token to list estimates', function () {
    $this->getJson('/api/app/v1/estimates')->assertUnauthorized();
});

it('refuses a Member viewing the estimates list, like the web', function () {
    ['user' => $member] = appEstimatesTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->getJson('/api/app/v1/estimates')->assertForbidden();
});

// --- Index / show -------------------------------------------------------

it('allows an Admin to list estimates with totals and client/project refs', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate);
    $catalogItem = appEstimatesCatalogItem();
    appEstimatesLineItemRow($estimate, $section, $catalogItem, ['total' => 125.00]);
    $section->update(['total' => 125.00]);

    $response = $this->getJson('/api/app/v1/estimates')->assertOk();

    expect($response->json('data.0.id'))->toBe($estimate->id)
        ->and((float) $response->json('data.0.total'))->toBe(125.00)
        ->and($response->json('data.0.status'))->toBe('Active')
        ->and($response->json('data.0.project.id'))->toBe($project->id)
        ->and($response->json('data.0.client.id'))->toBe($client->id);
});

it('filters the estimates list by project_id', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $projectA = appEstimatesProject($vendor, $client, ['project_name' => 'A']);
    $projectB = appEstimatesProject($vendor, $client, ['project_name' => 'B']);
    $estimateA = appEstimatesRow($vendor, $projectA);
    appEstimatesRow($vendor, $projectB);

    $response = $this->getJson("/api/app/v1/estimates?project_id={$projectA->id}")->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$estimateA->id]);
});

it('404s an estimate that does not exist', function () {
    ['user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $this->getJson('/api/app/v1/estimates/999999')->assertNotFound();
});

it('refuses a Member viewing a single estimate, like the web', function () {
    ['vendor' => $vendor, 'user' => $member] = appEstimatesTeamUser(role: 2);
    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);

    Sanctum::actingAs($member, ['app']);

    $this->getJson("/api/app/v1/estimates/{$estimate->id}")->assertForbidden();
});

it('403s another vendor\'s estimate', function () {
    ['user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $otherVendor = Vendor::factory()->create(['business_type' => 'Sub']);
    $otherClient = Client::factory()->create();
    $otherClient->vendors()->attach($otherVendor->id);
    $otherProject = appEstimatesProject($otherVendor, $otherClient);
    $foreignEstimate = Estimate::withoutGlobalScopes()->create([
        'project_id' => $otherProject->id,
        'belongs_to_vendor_id' => $otherVendor->id,
    ]);

    $this->getJson("/api/app/v1/estimates/{$foreignEstimate->id}")->assertForbidden();
});

it('shows an estimate\'s sections, line items, allowances and signing status', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate, ['name' => 'Kitchen', 'order' => 0]);
    $catalogItem = appEstimatesCatalogItem();
    $lineItem = appEstimatesLineItemRow($estimate, $section, $catalogItem, ['quantity' => 10, 'cost' => 12.50, 'total' => 125.00]);
    $lineItem->allowances()->create(['description' => 'Tile allowance', 'pricing_mode' => 'lump_sum', 'amount' => 500]);
    $section->update(['total' => 125.00]);

    $response = $this->getJson("/api/app/v1/estimates/{$estimate->id}")->assertOk();

    expect($response->json('data.sections.0.id'))->toBe($section->id)
        ->and((float) $response->json('data.sections.0.total'))->toBe(125.00)
        ->and($response->json('data.sections.0.line_items.0.id'))->toBe($lineItem->id)
        ->and($response->json('data.sections.0.line_items.0.allowances.0.description'))->toBe('Tile allowance')
        ->and($response->json('data.signing.is_signed'))->toBeFalse()
        ->and($response->json('data.signing.is_fully_signed'))->toBeFalse();
});

// --- Create / destroy / restore ----------------------------------------

it('creates a blank estimate with its first section, like EstimateCreate + EstimateShow::mount()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);

    $response = $this->postJson('/api/app/v1/estimates', ['project_id' => $project->id])->assertCreated();

    $estimateId = $response->json('data.id');
    expect(Estimate::query()->whereKey($estimateId)->exists())->toBeTrue()
        ->and($response->json('data.sections'))->toHaveCount(1);
});

it('refuses a Member creating an estimate, like the web', function () {
    ['vendor' => $vendor, 'user' => $member] = appEstimatesTeamUser(role: 2);
    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);

    Sanctum::actingAs($member, ['app']);

    $this->postJson('/api/app/v1/estimates', ['project_id' => $project->id])->assertForbidden();

    expect(Estimate::withoutGlobalScopes()->where('project_id', $project->id)->exists())->toBeFalse();
});

it('disables (soft-deletes) and restores an estimate', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);

    $this->deleteJson("/api/app/v1/estimates/{$estimate->id}")->assertOk();
    expect($estimate->fresh()->trashed())->toBeTrue();

    $this->postJson("/api/app/v1/estimates/{$estimate->id}/restore")->assertOk();
    expect($estimate->fresh()->trashed())->toBeFalse();
});

// --- Duplicate -----------------------------------------------------------

it('duplicates a whole estimate with its sections, line items and allowances', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate);
    $catalogItem = appEstimatesCatalogItem();
    $lineItem = appEstimatesLineItemRow($estimate, $section, $catalogItem, ['quantity' => 4, 'cost' => 10, 'total' => 40]);
    $lineItem->allowances()->create(['description' => 'Allowance', 'amount' => 20]);
    $section->update(['total' => 40]);

    $response = $this->postJson("/api/app/v1/estimates/{$estimate->id}/duplicate", [
        'project_id' => $project->id,
    ])->assertCreated();

    $newEstimateId = $response->json('data.id');
    expect($newEstimateId)->not->toBe($estimate->id)
        ->and($response->json('data.sections'))->toHaveCount(1)
        ->and((float) $response->json('data.sections.0.total'))->toBe(40.0)
        ->and($response->json('data.sections.0.line_items.0.allowances'))->toHaveCount(1);

    $newEstimate = Estimate::query()->findOrFail($newEstimateId);
    expect($newEstimate->estimate_line_items()->count())->toBe(1);
});

// --- Sections --------------------------------------------------------------

it('adds, renames, disables and restores a section', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);

    $created = $this->postJson("/api/app/v1/estimates/{$estimate->id}/sections", ['name' => 'Bathroom'])
        ->assertCreated()
        ->json('data');

    $sectionId = $created['id'];
    expect($created['name'])->toBe('Bathroom');

    $this->putJson("/api/app/v1/estimates/{$estimate->id}/sections/{$sectionId}", ['name' => 'Primary Bath'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Primary Bath');

    $this->deleteJson("/api/app/v1/estimates/{$estimate->id}/sections/{$sectionId}")->assertOk();
    expect(EstimateSection::withTrashed()->findOrFail($sectionId)->trashed())->toBeTrue();

    $this->postJson("/api/app/v1/estimates/{$estimate->id}/sections/{$sectionId}/restore")->assertOk();
    expect(EstimateSection::findOrFail($sectionId)->trashed())->toBeFalse();
});

it('refuses a Member adding a section, like the web', function () {
    ['vendor' => $vendor, 'user' => $member] = appEstimatesTeamUser(role: 2);
    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);

    Sanctum::actingAs($member, ['app']);

    $this->postJson("/api/app/v1/estimates/{$estimate->id}/sections", ['name' => 'Nope'])->assertForbidden();
});

// --- Line items ----------------------------------------------------------

it('adds a line item from the catalog with allowances, computing the total server-side', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate);
    $catalogItem = appEstimatesCatalogItem();

    $response = $this->postJson("/api/app/v1/estimates/{$estimate->id}/line-items", [
        'section_id' => $section->id,
        'line_item_id' => $catalogItem->id,
        'category' => $catalogItem->category,
        'unit_type' => $catalogItem->unit_type,
        'quantity' => 20,
        'cost' => 12.50,
        'desc' => 'Kitchen floor tile',
        'allowances' => [
            ['description' => 'Tile allowance', 'pricing_mode' => 'lump_sum', 'amount' => 300],
        ],
    ])->assertCreated();

    expect((float) $response->json('data.total'))->toBe(250.0)
        ->and($response->json('data.allowances.0.description'))->toBe('Tile allowance');

    expect($section->fresh()->total)->toEqual(250.00);
});

it('refuses a Member adding a line item (gated by the catalog, not the estimate), like the web', function () {
    ['vendor' => $vendor, 'user' => $member] = appEstimatesTeamUser(role: 2);
    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate);

    // Seed the catalog item as an Admin first, then switch to the Member.
    ['user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);
    $catalogItem = appEstimatesCatalogItem();

    Sanctum::actingAs($member, ['app']);

    $this->postJson("/api/app/v1/estimates/{$estimate->id}/line-items", [
        'section_id' => $section->id,
        'line_item_id' => $catalogItem->id,
        'category' => $catalogItem->category,
        'unit_type' => $catalogItem->unit_type,
        'quantity' => 1,
        'cost' => 1,
        'desc' => 'Nope description',
    ])->assertForbidden();

    expect(EstimateLineItem::where('section_id', $section->id)->exists())->toBeFalse();
});

it('updates and removes a line item, keeping the section total in sync', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate);
    $catalogItem = appEstimatesCatalogItem();
    $lineItem = appEstimatesLineItemRow($estimate, $section, $catalogItem, ['quantity' => 10, 'cost' => 12.50, 'total' => 125.00]);
    $section->update(['total' => 125.00]);

    $this->putJson("/api/app/v1/estimates/{$estimate->id}/line-items/{$lineItem->id}", [
        'section_id' => $section->id,
        'category' => $catalogItem->category,
        'unit_type' => $catalogItem->unit_type,
        'quantity' => 5,
        'cost' => 12.50,
        'desc' => 'Updated description',
    ])->assertOk()
        ->assertJsonPath('data.total', 62.5);

    expect((float) $section->fresh()->total)->toBe(62.5);

    $this->deleteJson("/api/app/v1/estimates/{$estimate->id}/line-items/{$lineItem->id}")->assertOk();
    expect(EstimateLineItem::find($lineItem->id))->toBeNull();
});

it('404s a section outside this estimate when adding a line item', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $otherEstimate = appEstimatesRow($vendor, $project);
    $otherSection = appEstimatesSection($otherEstimate);
    $catalogItem = appEstimatesCatalogItem();

    $this->postJson("/api/app/v1/estimates/{$estimate->id}/line-items", [
        'section_id' => $otherSection->id,
        'line_item_id' => $catalogItem->id,
        'category' => $catalogItem->category,
        'unit_type' => $catalogItem->unit_type,
        'quantity' => 1,
        'cost' => 1,
        'desc' => 'Wrong section',
    ])->assertNotFound();
});

// --- Email -----------------------------------------------------------------

it('returns email compose options with recipients, from addresses and a filled-in template', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor, ['business_name' => 'Acme Co']);
    $clientUser = User::factory()->create(['email' => 'client@example.test']);
    $client->users()->attach($clientUser->id);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);

    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => $admin->email, 'grant_id' => 'grant-1']);
    EmailTemplate::create(['vendor_id' => $vendor->id, 'name' => 'Default', 'type' => 'estimate', 'subject' => 'Estimate for {{project_name}}', 'body' => 'Hi {{client_name}}']);

    $response = $this->getJson("/api/app/v1/estimates/{$estimate->id}/email-options")->assertOk();

    expect($response->json('data.to'))->toContain('client@example.test')
        ->and($response->json('data.subject'))->toContain('Kitchen Remodel')
        ->and($response->json('data.body'))->toContain('Acme Co');
});

it('queues SendEstimateEmailJob exactly like the web\'s EstimateEmail::send()', function () {
    Queue::fake();

    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);

    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => $admin->email, 'grant_id' => 'grant-1']);

    $this->postJson("/api/app/v1/estimates/{$estimate->id}/email", [
        'to' => ['client@example.test'],
        'from' => $admin->email,
        'subject' => 'Your estimate',
        'body' => 'Please review.',
        'include_estimate_pdf' => true,
    ])->assertOk();

    Queue::assertPushed(SendEstimateEmailJob::class, 1);
});

it('refuses a Member emailing an estimate when they cannot even view it, like the web', function () {
    ['vendor' => $vendor, 'user' => $member] = appEstimatesTeamUser(role: 2);
    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);

    Sanctum::actingAs($member, ['app']);

    $this->getJson("/api/app/v1/estimates/{$estimate->id}/email-options")->assertForbidden();
});

// --- PDF ---------------------------------------------------------------

it('mints a fresh signed PDF url', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);

    $response = $this->getJson("/api/app/v1/estimates/{$estimate->id}/pdf-url")->assertOk();

    expect($response->json('data.url'))->toContain("estimates/{$estimate->id}/pdf/signed")
        ->and($response->json('data.url'))->toContain('signature=');
});

// --- AI draft ------------------------------------------------------------

it('drafts line items with the AI generator and persists them on the section', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate);
    $catalogItem = appEstimatesCatalogItem(['name' => 'Demo Bathroom', 'cost' => 850]);

    app()->instance(EstimateAIService::class, new FakeClaudeEstimateService([
        'text' => json_encode(['reasoning' => 'Hall bath demo.', 'line_items' => [
            ['line_item_id' => $catalogItem->id, 'quantity' => 1],
        ]]),
        'stop_reason' => 'end_turn',
    ]));

    $response = $this->postJson("/api/app/v1/estimates/{$estimate->id}/sections/{$section->id}/ai-draft", [
        'inquiry' => 'Demo the hall bathroom before retiling.',
    ])->assertCreated();

    expect($response->json('data.line_items'))->toHaveCount(1)
        ->and($response->json('data.line_items.0.line_item_id'))->toBe($catalogItem->id)
        ->and((float) $response->json('data.section.total'))->toBe(850.0);

    expect(EstimateLineItem::where('section_id', $section->id)->count())->toBe(1);
});

it('refuses a Member drafting with AI, like the web', function () {
    ['vendor' => $vendor, 'user' => $member] = appEstimatesTeamUser(role: 2);
    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate);

    Sanctum::actingAs($member, ['app']);

    $this->postJson("/api/app/v1/estimates/{$estimate->id}/sections/{$section->id}/ai-draft", [
        'inquiry' => 'Demo the hall bathroom before retiling.',
    ])->assertForbidden();
});
