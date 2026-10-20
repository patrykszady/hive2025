<?php

use App\Models\LineItem;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * AppAuthTest's appTeamUser(). $role is the vendor_user pivot's role_id
 * (1 = Admin, 2 = Member) — LineItemPolicy gates everything on it.
 *
 * @return array{vendor: Vendor, user: User}
 */
function appLineItemsTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction', 'business_type' => 'Sub']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

/**
 * Must run after Sanctum::actingAs() for that vendor's admin —
 * LineItemObserver::creating() stamps belongs_to_vendor_id from
 * auth()->user()->vendor, same as the web.
 */
function appLineItemsRow(array $overrides = []): LineItem
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

it('needs a token to list the catalog', function () {
    $this->getJson('/api/app/v1/line-items')->assertUnauthorized();
});

it('refuses a Member browsing the catalog, like the web', function () {
    ['user' => $member] = appLineItemsTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->getJson('/api/app/v1/line-items')->assertForbidden();
});

it('lists the catalog newest first, scoped to the vendor, with allowances', function () {
    ['user' => $admin] = appLineItemsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $older = appLineItemsRow(['name' => 'Baseboard', 'desc' => 'Baseboard trim', 'created_at' => now()->subMinute()]);
    $newer = appLineItemsRow(['name' => 'Crown Molding', 'desc' => 'Crown molding trim']);
    $newer->allowances()->create(['description' => 'Standard', 'pricing_mode' => 'per_unit', 'unit_amount' => 3, 'belongs_to_vendor_id' => $newer->belongs_to_vendor_id]);

    $response = $this->getJson('/api/app/v1/line-items')->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$newer->id, $older->id])
        ->and($response->json('data.0.allowances.0.description'))->toBe('Standard');
});

it('carries curated allowances through as inline_allowances, like the web list card', function () {
    ['user' => $admin] = appLineItemsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $item = appLineItemsRow(['name' => 'Crown Molding']);
    $item->allowances()->create(['description' => 'Standard', 'pricing_mode' => 'per_unit', 'unit_amount' => 3, 'belongs_to_vendor_id' => $item->belongs_to_vendor_id]);

    $response = $this->getJson('/api/app/v1/line-items')->assertOk();

    $row = collect($response->json('data'))->firstWhere('id', $item->id);
    expect($row['inline_allowances'])->toHaveCount(1)
        ->and($row['inline_allowances'][0]['description'])->toBe('Standard')
        ->and((float) $row['inline_allowances'][0]['unit_amount'])->toBe(3.0);
});

it('falls back to a historical allowance aggregated from past estimates when the catalog item has none, like LineItemsIndex::inlineAllowances()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appLineItemsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $catalogItem = appLineItemsRow(['name' => 'Wine Cellar Cooler', 'unit_type' => 'pieces']);

    $client = \App\Models\Client::factory()->create();
    $client->vendors()->attach($vendor->id);
    $project = \App\Models\Project::withoutEvents(fn () => \App\Models\Project::create([
        'project_name' => 'Kitchen Remodel',
        'client_id' => $client->id,
        'address' => '123 Main St',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => '60062',
        'belongs_to_vendor_id' => $vendor->id,
    ]));
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    $estimate = \App\Models\Estimate::create(['project_id' => $project->id, 'belongs_to_vendor_id' => $vendor->id]);
    $section = \App\Models\EstimateSection::create(['estimate_id' => $estimate->id, 'order' => 0, 'name' => 'Kitchen', 'total' => 0]);
    $estimateLineItem = \App\Models\EstimateLineItem::create([
        'estimate_id' => $estimate->id,
        'section_id' => $section->id,
        'line_item_id' => $catalogItem->id,
        'name' => $catalogItem->name,
        'category' => $catalogItem->category,
        'sub_category' => $catalogItem->sub_category,
        'unit_type' => $catalogItem->unit_type,
        'quantity' => 1,
        'cost' => $catalogItem->cost,
        'total' => $catalogItem->cost,
        'desc' => $catalogItem->desc,
        'order' => 1,
    ]);
    \App\Models\EstimateLineItemAllowance::create([
        'estimate_line_item_id' => $estimateLineItem->id,
        'description' => 'Tile allowance $5/sqft',
        'pricing_mode' => 'per_unit',
        'unit_amount' => 5,
        'amount' => 0,
    ]);

    $response = $this->getJson('/api/app/v1/line-items')->assertOk();

    $row = collect($response->json('data'))->firstWhere('id', $catalogItem->id);
    expect($row['allowances'])->toBe([])
        ->and($row['inline_allowances'])->toHaveCount(1)
        ->and((float) $row['inline_allowances'][0]['unit_amount'])->toBe(5.0);
});

it('searches the catalog by name, description and notes', function () {
    ['user' => $admin] = appLineItemsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $tile = appLineItemsRow(['name' => 'Floor Tile']);
    appLineItemsRow(['name' => 'Drywall', 'desc' => 'Drywall install']);

    $response = $this->getJson('/api/app/v1/line-items?search=tile')->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$tile->id]);
});

it('never surfaces another vendor\'s line items — the catalog has no tenant scope of its own', function () {
    ['user' => $admin] = appLineItemsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);
    $own = appLineItemsRow(['name' => 'Mine']);

    $otherVendor = Vendor::factory()->create(['business_type' => 'Sub']);
    $otherAdmin = User::factory()->create(['primary_vendor_id' => $otherVendor->id]);
    $otherVendor->users()->attach($otherAdmin->id, ['role_id' => 1]);
    Sanctum::actingAs($otherAdmin, ['app']);
    $foreign = appLineItemsRow(['name' => 'Theirs']);

    Sanctum::actingAs($admin, ['app']);
    $response = $this->getJson('/api/app/v1/line-items')->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$own->id]);

    $this->getJson("/api/app/v1/line-items/{$foreign->id}")->assertForbidden();
});

it('creates a catalog line item with allowances, stamping the vendor from the session', function () {
    ['vendor' => $vendor, 'user' => $admin] = appLineItemsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $response = $this->postJson('/api/app/v1/line-items', [
        'name' => 'Shower Rough Plumbing',
        'desc' => 'Rough plumbing for a shower',
        'category' => 'Plumbing',
        'unit_type' => 'pieces',
        'cost' => 1050,
        'allowances' => [
            ['description' => 'Fixture allowance', 'pricing_mode' => 'lump_sum', 'amount' => 400],
        ],
    ])->assertCreated();

    expect($response->json('data.name'))->toBe('Shower Rough Plumbing')
        ->and($response->json('data.allowances.0.description'))->toBe('Fixture allowance');

    $created = LineItem::findOrFail($response->json('data.id'));
    expect((int) $created->belongs_to_vendor_id)->toBe($vendor->id);
});

it('refuses a Member creating a catalog item, like the web', function () {
    ['user' => $member] = appLineItemsTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->postJson('/api/app/v1/line-items', [
        'name' => 'Nope',
        'desc' => 'Should not be created',
        'category' => 'Nope',
        'unit_type' => 'pieces',
        'cost' => 1,
    ])->assertForbidden();

    expect(LineItem::where('name', 'Nope')->exists())->toBeFalse();
});

it('updates a catalog item, syncing allowances', function () {
    ['user' => $admin] = appLineItemsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $lineItem = appLineItemsRow(['name' => 'Floor Tile', 'cost' => 12.50]);
    $allowance = $lineItem->allowances()->create(['description' => 'Old', 'pricing_mode' => 'lump_sum', 'amount' => 100, 'belongs_to_vendor_id' => $lineItem->belongs_to_vendor_id]);

    $response = $this->putJson("/api/app/v1/line-items/{$lineItem->id}", [
        'name' => 'Floor Tile',
        'desc' => $lineItem->desc,
        'category' => $lineItem->category,
        'unit_type' => $lineItem->unit_type,
        'cost' => 15.00,
        'allowances' => [
            ['id' => $allowance->id, 'description' => 'Updated', 'pricing_mode' => 'lump_sum', 'amount' => 150],
        ],
    ])->assertOk()
        ->assertJsonPath('data.allowances.0.description', 'Updated');

    expect((float) $response->json('data.cost'))->toBe(15.0)
        ->and((float) $lineItem->fresh()->cost)->toBe(15.0);
});

it('updates only the scope fields when name and allowances are resubmitted unchanged, like EstimateLineItemCreate::updateGlobalLineItem()', function () {
    // The app's "Update main line item" action (from an estimate line
    // item's edit screen) reuses this same PUT endpoint rather than a
    // dedicated one — EstimateLineItemCreate::updateGlobalLineItem() only
    // ever touches desc/notes/category/sub_category/unit_type/cost itself
    // (its modal has no name field and never edits allowances), so the app
    // gets the same effect by resubmitting the catalog item's current
    // name and allowances alongside the edited fields.
    ['user' => $admin] = appLineItemsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $lineItem = appLineItemsRow(['name' => 'Structural Header', 'category' => 'Framing', 'cost' => 2850]);
    $allowance = $lineItem->allowances()->create(['description' => 'Keep me', 'pricing_mode' => 'lump_sum', 'amount' => 100, 'belongs_to_vendor_id' => $lineItem->belongs_to_vendor_id]);

    $this->putJson("/api/app/v1/line-items/{$lineItem->id}", [
        'name' => $lineItem->name,
        'desc' => 'Updated scope text',
        'notes' => 'New note',
        'category' => 'Rough Carpentry',
        'sub_category' => $lineItem->sub_category,
        'unit_type' => $lineItem->unit_type,
        'cost' => 3100,
        'allowances' => [
            ['id' => $allowance->id, 'description' => $allowance->description, 'pricing_mode' => $allowance->pricing_mode, 'amount' => (float) $allowance->amount],
        ],
    ])->assertOk();

    $fresh = $lineItem->fresh();
    expect($fresh->name)->toBe('Structural Header')
        ->and($fresh->desc)->toBe('Updated scope text')
        ->and($fresh->notes)->toBe('New note')
        ->and($fresh->category)->toBe('Rough Carpentry')
        ->and((float) $fresh->cost)->toBe(3100.0)
        ->and($fresh->allowances()->count())->toBe(1)
        ->and($fresh->allowances()->first()->description)->toBe('Keep me');
});

it('validation rejects a cost with more than 2 decimal places, like the web', function () {
    ['user' => $admin] = appLineItemsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/line-items', [
        'name' => 'Bad Cost',
        'desc' => 'Bad cost format',
        'category' => 'Misc',
        'unit_type' => 'pieces',
        'cost' => '12.999',
    ])->assertStatus(422)->assertJsonValidationErrors(['cost']);
});
