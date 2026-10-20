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

it('shows one Address row when the jobsite and billing addresses match, and both when they differ', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $address = ['address' => '12 Elm St', 'address_2' => null, 'city' => 'Arlington Heights', 'state' => 'IL', 'zip_code' => '60004'];
    $client = appEstimatesClient($vendor, $address);
    $sameProject = appEstimatesProject($vendor, $client, $address);
    $otherProject = appEstimatesProject($vendor, $client, ['address' => '400 Oak Ave', 'address_2' => 'Unit 2', 'city' => 'Palatine', 'state' => 'IL', 'zip_code' => '60067']);

    $same = $this->getJson('/api/app/v1/estimates/'.appEstimatesRow($vendor, $sameProject)->id)->assertOk();

    expect($same->json('data.addresses.same'))->toBeTrue()
        ->and($same->json('data.addresses.jobsite'))->toBe(['12 Elm St', 'Arlington Heights, IL 60004']);

    $different = $this->getJson('/api/app/v1/estimates/'.appEstimatesRow($vendor, $otherProject)->id)->assertOk();

    expect($different->json('data.addresses.same'))->toBeFalse()
        ->and($different->json('data.addresses.jobsite'))->toBe(['400 Oak Ave', 'UNIT 2', 'Palatine, IL 60067'])
        ->and($different->json('data.addresses.billing'))->toBe(['12 Elm St', 'Arlington Heights, IL 60004']);
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

it('refuses to permanently delete an estimate that is still active', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);

    $this->deleteJson("/api/app/v1/estimates/{$estimate->id}/permanent")->assertStatus(422);

    expect(Estimate::withTrashed()->find($estimate->id))->not->toBeNull();
});

it('permanently deletes a disabled estimate and its sections/line items/allowances, like the trashed branch of removeEstimate()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate);
    $catalogItem = appEstimatesCatalogItem();
    $lineItem = appEstimatesLineItemRow($estimate, $section, $catalogItem);
    $allowance = $lineItem->allowances()->create(['description' => 'Allowance', 'amount' => 20]);

    $this->deleteJson("/api/app/v1/estimates/{$estimate->id}")->assertOk();
    expect($estimate->fresh()->trashed())->toBeTrue();

    $this->deleteJson("/api/app/v1/estimates/{$estimate->id}/permanent")
        ->assertOk()
        ->assertJsonPath('data.deleted', true);

    expect(Estimate::withTrashed()->find($estimate->id))->toBeNull()
        ->and(EstimateSection::withTrashed()->find($section->id))->toBeNull()
        ->and(EstimateLineItem::withTrashed()->find($lineItem->id))->toBeNull()
        ->and(\App\Models\EstimateLineItemAllowance::withTrashed()->find($allowance->id))->toBeNull();
});

it('refuses a Member permanently deleting an estimate, like the web', function () {
    ['vendor' => $vendor, 'user' => $member] = appEstimatesTeamUser(role: 2);
    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $estimate->delete();

    Sanctum::actingAs($member, ['app']);

    $this->deleteJson("/api/app/v1/estimates/{$estimate->id}/permanent")->assertForbidden();

    expect(Estimate::withTrashed()->find($estimate->id))->not->toBeNull();
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

it('duplicates a section in place, copying its line items and allowances', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate, ['name' => 'Kitchen', 'total' => 125.00]);
    $catalogItem = appEstimatesCatalogItem();
    $lineItem = appEstimatesLineItemRow($estimate, $section, $catalogItem, ['total' => 125.00]);
    $lineItem->allowances()->create(['description' => 'Tile allowance', 'amount' => 50]);

    $response = $this->postJson("/api/app/v1/estimates/{$estimate->id}/sections/{$section->id}/duplicate")
        ->assertCreated();

    expect($response->json('data.name'))->toBe('Kitchen -Copy')
        ->and((float) $response->json('data.total'))->toBe(125.0);

    $newSectionId = $response->json('data.id');
    expect($newSectionId)->not->toBe($section->id);

    $newLineItem = EstimateLineItem::where('section_id', $newSectionId)->sole();
    expect($newLineItem->name)->toBe($catalogItem->name)
        ->and((float) $newLineItem->total)->toBe(125.0)
        ->and($newLineItem->allowances()->sole()->description)->toBe('Tile allowance');

    // The original section and its line item are untouched.
    expect(EstimateLineItem::where('section_id', $section->id)->count())->toBe(1);
});

it('refuses a Member duplicating a section, like the web', function () {
    ['vendor' => $vendor, 'user' => $member] = appEstimatesTeamUser(role: 2);
    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate);

    Sanctum::actingAs($member, ['app']);

    $this->postJson("/api/app/v1/estimates/{$estimate->id}/sections/{$section->id}/duplicate")->assertForbidden();
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

it('restores a disabled line item back into its section, re-syncing the total', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate);
    $catalogItem = appEstimatesCatalogItem();
    $lineItem = appEstimatesLineItemRow($estimate, $section, $catalogItem, ['quantity' => 10, 'cost' => 12.50, 'total' => 125.00]);
    $section->update(['total' => 125.00]);

    $this->deleteJson("/api/app/v1/estimates/{$estimate->id}/line-items/{$lineItem->id}")->assertOk();
    expect((float) $section->fresh()->total)->toBe(0.0);

    $response = $this->postJson("/api/app/v1/estimates/{$estimate->id}/line-items/{$lineItem->id}/restore")
        ->assertOk();

    expect((float) $response->json('data.total'))->toBe(125.0)
        ->and(EstimateLineItem::find($lineItem->id))->not->toBeNull()
        ->and((float) $section->fresh()->total)->toBe(125.0);
});

it('refuses a Member restoring a line item, like the web', function () {
    ['vendor' => $vendor, 'user' => $member] = appEstimatesTeamUser(role: 2);
    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate);

    // Seed the catalog item and line item as an Admin first, then switch.
    ['user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);
    $catalogItem = appEstimatesCatalogItem();
    $lineItem = appEstimatesLineItemRow($estimate, $section, $catalogItem);
    $lineItem->delete();

    Sanctum::actingAs($member, ['app']);

    $this->postJson("/api/app/v1/estimates/{$estimate->id}/line-items/{$lineItem->id}/restore")->assertForbidden();
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
        ->and($response->json('data.body'))->toContain('Acme Co')
        ->and($response->json('data.project_id'))->toBe($project->id);
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

it('mints a signed PDF url carrying the chosen type, like EstimateShow::create_pdf($type)', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);

    $response = $this->getJson("/api/app/v1/estimates/{$estimate->id}/pdf-url?type=invoice")->assertOk();
    expect($response->json('data.url'))->toContain('type=invoice');

    // An unrecognized type falls back to 'estimate' rather than erroring.
    $fallback = $this->getJson("/api/app/v1/estimates/{$estimate->id}/pdf-url?type=bogus")->assertOk();
    expect($fallback->json('data.url'))->toContain('type=estimate');
});

it('mints a fresh signed XLSX url, like EstimateShow::export_csv()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);

    $response = $this->getJson("/api/app/v1/estimates/{$estimate->id}/xlsx-url")->assertOk();

    expect($response->json('data.url'))->toContain("estimates/{$estimate->id}/xlsx/signed")
        ->and($response->json('data.url'))->toContain('signature=');
});

it('404s a signed-contract url when no signed contract is on file, and mints one once there is', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);

    $this->getJson("/api/app/v1/estimates/{$estimate->id}/signed-contract-url")->assertNotFound();

    $estimate->update(['signed_contract_path' => 'contracts/signed.pdf']);

    $response = $this->getJson("/api/app/v1/estimates/{$estimate->id}/signed-contract-url")->assertOk();
    expect($response->json('data.url'))->toContain("estimates/{$estimate->id}/signed-contract/signed");
});

it("sends the vendor's Send Invite email, mirroring EstimateShow::sendInvite()", function () {
    Illuminate\Support\Facades\Mail::fake();

    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $clientUser = User::factory()->create(['email' => 'client@example.test']);
    $client->users()->attach($clientUser->id);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project, ['options' => []]);

    $response = $this->postJson("/api/app/v1/estimates/{$estimate->id}/send-invite")->assertOk();

    expect($response->json('data.kind'))->toBe('welcome')
        ->and($response->json('data.sent'))->toBe(1);

    Illuminate\Support\Facades\Mail::assertSent(App\Mail\WelcomeClient::class, 1);
});

it('refuses a Member sending an estimate invite, like the web', function () {
    ['vendor' => $vendor, 'user' => $member] = appEstimatesTeamUser(role: 2);
    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);

    Sanctum::actingAs($member, ['app']);

    $this->postJson("/api/app/v1/estimates/{$estimate->id}/send-invite")->assertForbidden();
});

// --- Payment schedule ----------------------------------------------------

it("shows the payment schedule editor's prefill data", function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project, [
        'options' => ['payments' => [['description' => 'Deposit', 'amount' => '400']]],
    ]);
    appEstimatesSection($estimate, ['total' => 1000.00]);

    $response = $this->getJson("/api/app/v1/estimates/{$estimate->id}/payment-schedule")->assertOk();

    expect($response->json('data.payments.0.description'))->toBe('Deposit')
        ->and((float) $response->json('data.contract_total'))->toBe(1000.0);
});

it('saves the payment schedule and dates, like EstimateAccept::save()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    appEstimatesSection($estimate, ['total' => 1000.00]);

    $response = $this->putJson("/api/app/v1/estimates/{$estimate->id}/payment-schedule", [
        'payments' => [
            ['description' => 'Deposit', 'amount' => '400'],
            ['description' => 'Final Payment', 'amount' => '600'],
        ],
        'start_date' => '2027-01-15',
    ])->assertOk();

    expect((float) $response->json('data.payments_outstanding'))->toBe(0.0)
        ->and($response->json('data.start_date'))->toBe('2027-01-15');

    expect($estimate->fresh()->options['payments'])->toBe([
        ['description' => 'Deposit', 'amount' => '400'],
        ['description' => 'Final Payment', 'amount' => '600'],
    ]);
});

it('refuses an over-budget payment schedule with a 422, like the web', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    appEstimatesSection($estimate, ['total' => 500.00]);

    $this->putJson("/api/app/v1/estimates/{$estimate->id}/payment-schedule", [
        'payments' => [['description' => 'Deposit', 'amount' => '600']],
    ])->assertStatus(422);

    expect($estimate->fresh()->options['payments'] ?? null)->toBeNull();
});

it('refuses a Member viewing or saving the payment schedule, like the web', function () {
    ['vendor' => $vendor, 'user' => $member] = appEstimatesTeamUser(role: 2);
    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);

    Sanctum::actingAs($member, ['app']);

    $this->getJson("/api/app/v1/estimates/{$estimate->id}/payment-schedule")->assertForbidden();
    $this->putJson("/api/app/v1/estimates/{$estimate->id}/payment-schedule", ['payments' => []])->assertForbidden();
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

// --- Email: template switcher, extra attachments, showChanges -----------

it('queues the email with the chosen template name and showChanges, like EstimateEmail::send()', function () {
    Queue::fake();

    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);

    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => $admin->email, 'grant_id' => 'grant-1']);
    $template = EmailTemplate::create(['vendor_id' => $vendor->id, 'name' => 'Follow-up', 'type' => 'estimate', 'subject' => 'x', 'body' => 'y']);

    $this->postJson("/api/app/v1/estimates/{$estimate->id}/email", [
        'to' => ['client@example.test'],
        'from' => $admin->email,
        'subject' => 'Your estimate',
        'body' => 'Please review.',
        'template_id' => $template->id,
        'show_changes' => true,
    ])->assertOk();

    Queue::assertPushed(SendEstimateEmailJob::class, function (SendEstimateEmailJob $job) {
        $seen = (fn () => ['template' => $this->emailTemplateName, 'showChanges' => $this->showChanges])->call($job);

        return $seen['template'] === 'Follow-up' && $seen['showChanges'] === true;
    });
});

it('uploads extra attachments and hands their temp paths to the job, like EstimateEmail::send()', function () {
    Queue::fake();

    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);

    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => $admin->email, 'grant_id' => 'grant-1']);

    // A unique name per run — the real storage/app/temp dir (same one
    // EstimateEmail::send() writes to, not behind the Storage facade) is
    // shared across test runs and Queue::fake() means the job never runs
    // to clean its own file up, so a fixed name collides across runs.
    $filename = 'floorplan_'.uniqid().'.pdf';
    $file = Illuminate\Http\UploadedFile::fake()->create($filename, 50, 'application/pdf');

    $this->post("/api/app/v1/estimates/{$estimate->id}/email", [
        'to' => ['client@example.test'],
        'from' => $admin->email,
        'subject' => 'Your estimate',
        'body' => 'Please review.',
        'additional_attachments' => [$file],
    ], ['Accept' => 'application/json'])->assertOk();

    $capturedPaths = [];

    Queue::assertPushed(SendEstimateEmailJob::class, function (SendEstimateEmailJob $job) use (&$capturedPaths, $filename) {
        $paths = (fn () => $this->additionalAttachmentPaths)->call($job);
        $capturedPaths = $paths;

        return count($paths) === 1 && str_contains($paths[0], $filename) && file_exists($paths[0]);
    });

    // Clean up — SendEstimateEmailJob (never run here, Queue::fake()) is
    // what deletes this on the real send path.
    foreach ($capturedPaths as $path) {
        @unlink($path);
    }
});

it('refuses more than 5 extra attachments, like the web\'s WithFileUploads rule', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => $admin->email, 'grant_id' => 'grant-1']);

    $files = array_map(fn ($i) => Illuminate\Http\UploadedFile::fake()->create("file{$i}.pdf", 10), range(1, 6));

    $this->post("/api/app/v1/estimates/{$estimate->id}/email", [
        'to' => ['client@example.test'],
        'from' => $admin->email,
        'subject' => 'Your estimate',
        'body' => 'Please review.',
        'additional_attachments' => $files,
    ], ['Accept' => 'application/json'])->assertStatus(422);
});

// --- showChanges/showAllowances on PDF/XLSX exports ----------------------

it('carries show_changes and show_allowances into the signed PDF url', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);

    $response = $this->getJson("/api/app/v1/estimates/{$estimate->id}/pdf-url?show_changes=1&show_allowances=0")->assertOk();

    expect($response->json('data.url'))->toContain('show_changes=1')
        ->and($response->json('data.url'))->toContain('show_allowances=0');

    $default = $this->getJson("/api/app/v1/estimates/{$estimate->id}/pdf-url")->assertOk();
    expect($default->json('data.url'))->toContain('show_changes=0')
        ->and($default->json('data.url'))->toContain('show_allowances=1');
});

it('carries show_allowances into the signed XLSX url', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);

    $response = $this->getJson("/api/app/v1/estimates/{$estimate->id}/xlsx-url?show_allowances=0")->assertOk();

    expect($response->json('data.url'))->toContain('show_allowances=0');
});

it('reports recent changes since estimate creation, like the Show Changes toggle', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    appEstimatesSection($estimate, ['created_at' => now()->subDay()]);

    $response = $this->getJson("/api/app/v1/estimates/{$estimate->id}/recent-changes")->assertOk();

    expect($response->json('data'))->toHaveKeys(['sections', 'line_items', 'since']);
});

// --- Duplicate section to another estimate --------------------------------

it('lists the other estimates in the same project as duplicate targets', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate);
    $other = appEstimatesRow($vendor, $project);

    $response = $this->getJson("/api/app/v1/estimates/{$estimate->id}/sections/{$section->id}/duplicate-targets")->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$other->id]);
});

it('duplicates a section onto an existing estimate in the same project', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate, ['total' => 500]);
    $catalogItem = appEstimatesCatalogItem();
    appEstimatesLineItemRow($estimate, $section, $catalogItem, ['cost' => 50, 'quantity' => 10, 'total' => 500]);
    $target = appEstimatesRow($vendor, $project);

    $response = $this->postJson("/api/app/v1/estimates/{$estimate->id}/sections/{$section->id}/duplicate-to-estimate", [
        'target_estimate_id' => $target->id,
        'cost_mode' => 'exact',
        'include_allowances' => true,
    ])->assertCreated();

    expect($response->json('data.target_estimate_id'))->toBe($target->id);
    expect(EstimateSection::where('estimate_id', $target->id)->count())->toBe(1);
});

it('duplicates a section onto a brand new estimate when no target is given', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate, ['total' => 500]);

    $response = $this->postJson("/api/app/v1/estimates/{$estimate->id}/sections/{$section->id}/duplicate-to-estimate", [])
        ->assertCreated();

    $newEstimateId = $response->json('data.target_estimate_id');
    expect($newEstimateId)->not->toBe($estimate->id);
    expect(Estimate::withoutGlobalScopes()->find($newEstimateId)?->project_id)->toBe($project->id);
});

it('refuses duplicating a section onto its own estimate', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate);

    $this->postJson("/api/app/v1/estimates/{$estimate->id}/sections/{$section->id}/duplicate-to-estimate", [
        'target_estimate_id' => $estimate->id,
    ])->assertStatus(422);
});

// --- Contract settings: signers, templates, bid reassignment -------------

it('returns contract-settings options with templates, vendor users and bids', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate, ['total' => 1000]);
    EmailTemplate::create(['vendor_id' => $vendor->id, 'type' => 'contract', 'name' => 'Standard', 'subject' => 'x', 'body' => 'y']);

    $response = $this->getJson("/api/app/v1/estimates/{$estimate->id}/contract-settings")->assertOk();

    expect($response->json('data.contract_templates.0.name'))->toBe('Standard')
        ->and(collect($response->json('data.vendor_users'))->pluck('id'))->toContain($admin->id)
        ->and($response->json('data.bids'))->toHaveCount(1)
        ->and($response->json('data.sections.0.id'))->toBe($section->id);
});

it('saves signer ids, template ids and reassigns a section to a new bid', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate, ['total' => 1000]);
    $template = EmailTemplate::create(['vendor_id' => $vendor->id, 'type' => 'contract', 'name' => 'Standard', 'subject' => 'x', 'body' => 'y']);

    $originalBidId = $this->getJson("/api/app/v1/estimates/{$estimate->id}/contract-settings")->json('data.original_bid_id');

    $newBid = $this->postJson("/api/app/v1/estimates/{$estimate->id}/contract-settings/bids")->assertCreated();
    $newBidId = $newBid->json('data.id');

    $this->putJson("/api/app/v1/estimates/{$estimate->id}/contract-settings", [
        'required_vendor_signer_ids' => [$admin->id],
        'contract_template_ids' => [$template->id],
        'section_bids' => [(string) $section->id => $newBidId],
    ])->assertOk()
        ->assertJsonPath('data.required_vendor_signer_ids', [$admin->id])
        ->assertJsonPath('data.contract_template_ids', [$template->id]);

    expect($section->fresh()->bid_id)->toBe($newBidId)
        ->and((float) \App\Models\Bid::withoutGlobalScopes()->find($newBidId)->amount)->toBe(1000.0)
        ->and((float) \App\Models\Bid::withoutGlobalScopes()->find($originalBidId)->amount)->toBe(0.0);
});

it('refuses a Member reading or saving contract settings, like the web', function () {
    ['vendor' => $vendor, 'user' => $member] = appEstimatesTeamUser(role: 2);
    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);

    Sanctum::actingAs($member, ['app']);

    $this->getJson("/api/app/v1/estimates/{$estimate->id}/contract-settings")->assertForbidden();
    $this->putJson("/api/app/v1/estimates/{$estimate->id}/contract-settings", [])->assertForbidden();
    $this->postJson("/api/app/v1/estimates/{$estimate->id}/contract-settings/bids")->assertForbidden();
});

// --- Credit a locked line item to a change order --------------------------

/**
 * Locks $section the same way a signed contract does: isLocked() needs
 * isFullySigned() (vendor-signed is enough — appEstimatesClient() attaches
 * no client users, so 0 client signatures are required) and the section's
 * created_at at or before the earliest signature.
 */
function appEstimatesLockSection(Estimate $estimate, EstimateSection $section, User $vendorSigner): void
{
    $section->update(['created_at' => now()->subHour()]);

    \App\Models\EstimateSignature::create([
        'estimate_id' => $estimate->id,
        'user_id' => $vendorSigner->id,
        'signer_name' => $vendorSigner->first_name.' '.$vendorSigner->last_name,
        'signer_email' => $vendorSigner->email,
        'signer_phone' => '+12243334444',
        'signature_data' => 'vendor-signature',
        'signature_type' => 'draw',
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'document_hash' => 'hash-vendor',
        'signed_at' => now(),
    ]);
}

it('credits a line item locked into the signed contract, like EstimateLineItemCreate::creditToChangeOrder()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate, ['total' => 500]);
    $catalogItem = appEstimatesCatalogItem(['name' => 'Structural Header', 'cost' => 500]);
    $lineItem = appEstimatesLineItemRow($estimate, $section, $catalogItem, ['quantity' => 1, 'cost' => 500, 'total' => 500]);

    appEstimatesLockSection($estimate, $section, $admin);

    $response = $this->postJson("/api/app/v1/estimates/{$estimate->id}/line-items/{$lineItem->id}/credit")->assertCreated();

    expect($response->json('data.name'))->toBe('Credit: Structural Header')
        ->and((float) $response->json('data.total'))->toBe(-500.0)
        ->and((float) $response->json('data.cost'))->toBe(-500.0);

    $creditSection = EstimateSection::where('estimate_id', $estimate->id)->where('id', '!=', $section->id)->first();
    expect($creditSection)->not->toBeNull()
        ->and($creditSection->name)->toBe('Change Order')
        ->and((float) $creditSection->fresh()->total)->toBe(-500.0)
        // the original signed line item is untouched
        ->and((float) $lineItem->fresh()->total)->toBe(500.0);
});

it('refuses crediting a line item that is not locked', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate, ['total' => 500]);
    $catalogItem = appEstimatesCatalogItem(['cost' => 500]);
    $lineItem = appEstimatesLineItemRow($estimate, $section, $catalogItem, ['quantity' => 1, 'cost' => 500, 'total' => 500]);

    $this->postJson("/api/app/v1/estimates/{$estimate->id}/line-items/{$lineItem->id}/credit")->assertStatus(422);

    expect(EstimateLineItem::where('estimate_id', $estimate->id)->count())->toBe(1);
});

it('refuses a Member crediting a line item, like the web', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);
    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate, ['total' => 500]);
    $catalogItem = appEstimatesCatalogItem(['cost' => 500]);
    $lineItem = appEstimatesLineItemRow($estimate, $section, $catalogItem, ['quantity' => 1, 'cost' => 500, 'total' => 500]);
    appEstimatesLockSection($estimate, $section, $admin);

    ['user' => $member] = appEstimatesTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->postJson("/api/app/v1/estimates/{$estimate->id}/line-items/{$lineItem->id}/credit")->assertForbidden();
});

// --- Reordering sections and line items ------------------------------------

it('reorders sections, like EstimateShow::sort_sections()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $sectionA = appEstimatesSection($estimate, ['name' => 'A', 'order' => 0]);
    $sectionB = appEstimatesSection($estimate, ['name' => 'B', 'order' => 1]);

    $response = $this->patchJson("/api/app/v1/estimates/{$estimate->id}/sections/{$sectionA->id}/move", ['position' => 1])->assertOk();

    expect($response->json('data.order'))->toBe(1)
        ->and($sectionA->fresh()->order)->toBe(1)
        ->and($sectionB->fresh()->order)->toBe(0);
});

it('refuses a Member reordering sections, like the web', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);
    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate);

    ['user' => $member] = appEstimatesTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->patchJson("/api/app/v1/estimates/{$estimate->id}/sections/{$section->id}/move", ['position' => 0])->assertForbidden();
});

it('reorders a line item within its own section, like EstimateShow::sort_line_item()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate);
    $catalogItem = appEstimatesCatalogItem();
    $first = appEstimatesLineItemRow($estimate, $section, $catalogItem, ['order' => 0]);
    $second = appEstimatesLineItemRow($estimate, $section, $catalogItem, ['order' => 1]);

    $this->patchJson("/api/app/v1/estimates/{$estimate->id}/line-items/{$first->id}/move", ['position' => 1])->assertOk();

    expect($first->fresh()->order)->toBe(1)
        ->and($second->fresh()->order)->toBe(0);
});

it('moves a line item into a different section, rolling up both sections\' totals and bids', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $fromSection = appEstimatesSection($estimate, ['name' => 'From', 'order' => 0, 'total' => 100]);
    $toSection = appEstimatesSection($estimate, ['name' => 'To', 'order' => 1, 'total' => 0]);
    $catalogItem = appEstimatesCatalogItem(['cost' => 100]);
    $lineItem = appEstimatesLineItemRow($estimate, $fromSection, $catalogItem, ['quantity' => 1, 'cost' => 100, 'total' => 100, 'order' => 0]);

    $response = $this->patchJson("/api/app/v1/estimates/{$estimate->id}/line-items/{$lineItem->id}/move", [
        'position' => 0,
        'section_id' => $toSection->id,
    ])->assertOk();

    expect($response->json('data.section_id'))->toBe($toSection->id)
        ->and($lineItem->fresh()->section_id)->toBe($toSection->id)
        ->and((float) $fromSection->fresh()->total)->toBe(0.0)
        ->and((float) $toSection->fresh()->total)->toBe(100.0);
});

it('404s moving a line item into a section from a different estimate', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate);
    $catalogItem = appEstimatesCatalogItem();
    $lineItem = appEstimatesLineItemRow($estimate, $section, $catalogItem);

    $otherEstimate = appEstimatesRow($vendor, $project);
    $otherSection = appEstimatesSection($otherEstimate);

    $this->patchJson("/api/app/v1/estimates/{$estimate->id}/line-items/{$lineItem->id}/move", [
        'position' => 0,
        'section_id' => $otherSection->id,
    ])->assertNotFound();
});

// --- AI estimating rules ---------------------------------------------------

it('lists, adds, approves, dismisses and deletes AI estimating rules, like EstimateAIGenerator', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);

    $created = $this->postJson("/api/app/v1/estimates/{$estimate->id}/ai-rules", [
        'text' => 'Always price flooring per square foot, not per box.',
    ])->assertCreated();

    $ruleId = $created->json('data.id');
    expect($created->json('data.status'))->toBe('active');

    $list = $this->getJson("/api/app/v1/estimates/{$estimate->id}/ai-rules")->assertOk();
    expect(collect($list->json('data'))->pluck('id')->all())->toContain($ruleId);

    $this->patchJson("/api/app/v1/estimates/{$estimate->id}/ai-rules/{$ruleId}/dismiss")->assertOk()
        ->assertJsonPath('data.status', 'dismissed');

    $this->patchJson("/api/app/v1/estimates/{$estimate->id}/ai-rules/{$ruleId}/approve")->assertOk()
        ->assertJsonPath('data.status', 'active');

    $this->deleteJson("/api/app/v1/estimates/{$estimate->id}/ai-rules/{$ruleId}")->assertOk();

    expect(\App\Models\EstimateAiRule::find($ruleId))->toBeNull();
});

it('dismisses rather than deletes a proposed rule with a fingerprint, like EstimateAIGenerator::deleteRule()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);

    $rule = \App\Models\EstimateAiRule::create([
        'vendor_id' => $vendor->id,
        'text' => 'Pattern-sourced rule.',
        'status' => \App\Models\EstimateAiRule::ACTIVE,
        'source' => \App\Models\EstimateAiRule::FROM_PATTERN,
        'fingerprint' => 'abc123',
    ]);

    $this->deleteJson("/api/app/v1/estimates/{$estimate->id}/ai-rules/{$rule->id}")->assertOk();

    expect($rule->fresh()->status)->toBe(\App\Models\EstimateAiRule::DISMISSED);
});

it('refuses a Member managing AI estimating rules, like the web', function () {
    ['vendor' => $vendor, 'user' => $member] = appEstimatesTeamUser(role: 2);
    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);

    Sanctum::actingAs($member, ['app']);

    $this->getJson("/api/app/v1/estimates/{$estimate->id}/ai-rules")->assertForbidden();
    $this->postJson("/api/app/v1/estimates/{$estimate->id}/ai-rules", ['text' => 'Nope, not allowed.'])->assertForbidden();
});

// --- Discarding an AI draft -------------------------------------------------

it('discards an AI draft, force-deleting its line items and clearing the section scope', function () {
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

    $draftResponse = $this->postJson("/api/app/v1/estimates/{$estimate->id}/sections/{$section->id}/ai-draft", [
        'inquiry' => 'Demo the hall bathroom before retiling.',
    ])->assertCreated();

    $draftId = $draftResponse->json('data.draft_id');
    $draftedLineItemId = $draftResponse->json('data.line_items.0.id');

    $this->postJson("/api/app/v1/estimates/{$estimate->id}/ai-draft/{$draftId}/discard")->assertOk()
        ->assertJsonPath('data.discarded', true);

    expect(EstimateLineItem::withTrashed()->find($draftedLineItemId))->toBeNull()
        ->and($section->fresh()->ai_inquiry)->toBeNull()
        ->and(\App\Models\EstimateAiDraft::find($draftId)->status)->toBe(\App\Models\EstimateAiDraft::DISCARDED);
});

it('refuses a Member discarding an AI draft, like the web', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);
    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate);

    $draft = \App\Models\EstimateAiDraft::create([
        'vendor_id' => $vendor->id,
        'estimate_id' => $estimate->id,
        'section_id' => $section->id,
        'inquiry' => 'Demo the hall bathroom before retiling.',
        'drafted_items' => [],
        'status' => \App\Models\EstimateAiDraft::DRAFTED,
    ]);

    ['user' => $member] = appEstimatesTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->postJson("/api/app/v1/estimates/{$estimate->id}/ai-draft/{$draft->id}/discard")->assertForbidden();
});

// --- Floorplan image upload on the AI draft ---------------------------------

it('measures an uploaded floorplan image via OCR and records its metrics on the draft', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate);
    $catalogItem = appEstimatesCatalogItem(['name' => 'Demo Bathroom', 'cost' => 850]);

    config(['services.ocr_space.api_key' => 'test-key', 'services.ocr_space.endpoint' => 'https://api.ocr.space/parse/image']);
    \Illuminate\Support\Facades\Http::fake([
        'api.ocr.space/*' => \Illuminate\Support\Facades\Http::response([
            'ParsedResults' => [['ParsedText' => 'Floor Area: 400 sq ft. Wall Area: 900 sq ft.']],
        ]),
    ]);

    app()->instance(EstimateAIService::class, new FakeClaudeEstimateService([
        'text' => json_encode(['reasoning' => 'Hall bath demo.', 'line_items' => [
            ['line_item_id' => $catalogItem->id, 'quantity' => 1],
        ]]),
        'stop_reason' => 'end_turn',
    ]));

    $file = \Illuminate\Http\UploadedFile::fake()->image('floorplan.jpg');

    $response = $this->postJson("/api/app/v1/estimates/{$estimate->id}/sections/{$section->id}/ai-draft", [
        'inquiry' => 'Demo the hall bathroom before retiling.',
        'floorplans' => [$file],
    ])->assertCreated();

    $draft = \App\Models\EstimateAiDraft::findOrFail($response->json('data.draft_id'));

    expect($draft->floorplan)->not->toBeNull()
        ->and((float) $draft->floorplan['floor_sqft'])->toBe(400.0)
        ->and((float) $draft->floorplan['wall_sqft'])->toBe(900.0);
});

it('refuses more than 5 floorplan images on an AI draft', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEstimatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appEstimatesClient($vendor);
    $project = appEstimatesProject($vendor, $client);
    $estimate = appEstimatesRow($vendor, $project);
    $section = appEstimatesSection($estimate);

    $files = array_map(fn ($i) => \Illuminate\Http\UploadedFile::fake()->image("floor-{$i}.jpg"), range(1, 6));

    $this->postJson("/api/app/v1/estimates/{$estimate->id}/sections/{$section->id}/ai-draft", [
        'inquiry' => 'Demo the hall bathroom before retiling.',
        'floorplans' => $files,
    ])->assertStatus(422);
});
