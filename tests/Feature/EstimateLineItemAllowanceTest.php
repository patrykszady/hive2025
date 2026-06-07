<?php

use App\Livewire\LineItems\EstimateLineItemCreate;
use App\Livewire\LineItems\LineItemCreate;
use App\Livewire\LineItems\LineItemsIndex;
use App\Models\Estimate;
use App\Models\EstimateLineItem;
use App\Models\EstimateLineItemAllowance;
use App\Models\EstimateSection;
use App\Models\LineItem;
use App\Models\LineItemAllowance;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function actingAsVendorUser(): User
{
    $vendor = Vendor::factory()->create();

    $user = User::query()->create([
        'first_name' => 'Test',
        'last_name' => 'Admin',
        'email' => 'allowance-' . Str::random(8) . '@example.test',
        'cell_phone' => '7' . random_int(100000000, 999999999),
        'password' => bcrypt('password'),
        'remember_token' => Str::random(10),
        'primary_vendor_id' => $vendor->id,
    ]);

    $user->vendors()->attach($vendor->id, ['role_id' => 1]);

    test()->actingAs($user);

    return $user;
}

/**
 * Create an estimate line item (referencing the given global line item) with a
 * single allowance, mirroring how allowances are recorded on real estimates.
 */
function makeEstimateAllowanceFor(LineItem $lineItem, string $description, ?float $unitAmount = null, float $amount = 0, string $pricingMode = 'per_unit'): EstimateLineItemAllowance
{
    $estimate = Estimate::create(['belongs_to_vendor_id' => 1]);
    $section = EstimateSection::create(['estimate_id' => $estimate->id, 'name' => 'Main', 'order' => 0, 'total' => 0]);

    $estimateLineItem = EstimateLineItem::create([
        'estimate_id' => $estimate->id,
        'line_item_id' => $lineItem->id,
        'section_id' => $section->id,
        'name' => $lineItem->name,
        'category' => $lineItem->category,
        'sub_category' => $lineItem->sub_category,
        'unit_type' => $lineItem->unit_type,
        'quantity' => 1,
        'cost' => $lineItem->cost,
        'total' => $lineItem->cost,
    ]);

    return $estimateLineItem->allowances()->create([
        'description' => $description,
        'pricing_mode' => $pricingMode,
        'unit_amount' => $unitAmount,
        'amount' => $amount,
    ]);
}

it('exposes distinct previous allowances for the selected line item', function () {
    actingAsVendorUser();

    $floorTile = LineItem::create(['name' => 'Floor Tile', 'category' => 'Flooring', 'unit_type' => 'sq.ft.', 'cost' => 10]);
    $paint = LineItem::create(['name' => 'Paint', 'category' => 'Finishes', 'unit_type' => 'no_unit', 'cost' => 5]);

    makeEstimateAllowanceFor($floorTile, 'Tile material budget', 30, 600);
    makeEstimateAllowanceFor($floorTile, 'Grout upgrade', null, 75);
    makeEstimateAllowanceFor($paint, 'Premium paint', null, 120);

    $estimate = Estimate::create(['belongs_to_vendor_id' => 1]);

    Livewire::test(EstimateLineItemCreate::class, ['estimate' => $estimate])
        ->set('line_item_id', $floorTile->id)
        ->assertSet('line_item_id', $floorTile->id)
        ->tap(function ($component) {
            $previous = $component->instance()->previousAllowances();

            $descriptions = $previous->pluck('description');

            expect($descriptions->all())
                ->toContain('Tile Material Budget')
                ->toContain('Grout Upgrade')
                ->not->toContain('Premium Paint');

            expect($descriptions->filter(fn ($description) => $description === 'Tile Material Budget'))->toHaveCount(1);

            $tile = $previous->firstWhere('description', 'Tile Material Budget');
            expect((float) $tile['unit_amount'])->toBe(30.0);
        });
});

it('hides already selected allowances from the dropdown suggestions', function () {
    actingAsVendorUser();

    $floorTile = LineItem::create(['name' => 'Floor Tile', 'category' => 'Flooring', 'unit_type' => 'sq.ft.', 'cost' => 10]);

    LineItemAllowance::create(['line_item_id' => $floorTile->id, 'description' => 'Tile Material Budget', 'pricing_mode' => 'per_unit', 'unit_amount' => 30, 'amount' => 600]);
    LineItemAllowance::create(['line_item_id' => $floorTile->id, 'description' => 'Grout Upgrade', 'pricing_mode' => 'lump_sum', 'amount' => 75]);
    LineItemAllowance::create(['line_item_id' => $floorTile->id, 'description' => 'Sealant', 'pricing_mode' => 'lump_sum', 'amount' => 20]);

    $estimate = Estimate::create(['belongs_to_vendor_id' => 1]);

    Livewire::test(EstimateLineItemCreate::class, ['estimate' => $estimate])
        ->set('line_item_id', $floorTile->id)
        ->set('form.allowances.0.description', 'Tile Material Budget')
        ->set('form.allowances.1.description', 'Grout Upgrade')
        ->tap(function ($component) {
            $previous = $component->instance()->previousAllowancesForRow(2);

            expect($previous->pluck('description')->all())
                ->toContain('Sealant')
                ->not->toContain('Tile Material Budget')
                ->not->toContain('Grout Upgrade');
        });
});

it('fills the amount and per-unit amount when a previous allowance is chosen', function () {
    actingAsVendorUser();

    $floorTile = LineItem::create(['name' => 'Floor Tile', 'category' => 'Flooring', 'unit_type' => 'sq.ft.', 'cost' => 10]);

    makeEstimateAllowanceFor($floorTile, 'Tile material budget', 30, 600);

    $estimate = Estimate::create(['belongs_to_vendor_id' => 1]);

    Livewire::test(EstimateLineItemCreate::class, ['estimate' => $estimate])
        ->set('line_item_id', $floorTile->id)
        ->set('form.quantity', 20)
        ->call('addAllowance')
        ->set('form.allowances.0.description', 'Tile Material Budget')
        ->assertSet('form.allowances.0.unit_amount', '30.00')
        ->assertSet('form.allowances.0.amount', '600.00');
});

it('fills a lump-sum total from the curated global catalog in the estimate modal', function () {
    actingAsVendorUser();

    $floorTile = LineItem::create(['name' => 'Floor Tile', 'category' => 'Flooring', 'unit_type' => 'sq.ft.', 'cost' => 10]);

    LineItemAllowance::create([
        'line_item_id' => $floorTile->id,
        'description' => 'Grout',
        'pricing_mode' => 'lump_sum',
        'amount' => 60,
    ]);

    $estimate = Estimate::create(['belongs_to_vendor_id' => 1]);

    Livewire::test(EstimateLineItemCreate::class, ['estimate' => $estimate])
        ->set('line_item_id', $floorTile->id)
        ->set('form.quantity', 20)
        ->call('addAllowance')
        ->set('form.allowances.0.description', 'Grout')
        ->assertSet('form.allowances.0.pricing_mode', 'lump_sum')
        ->assertSet('form.allowances.0.unit_amount', '')
        ->assertSet('form.allowances.0.amount', '60.00');
});

it('collapses a legacy free-text allowance onto the global catalog when editing', function () {
    actingAsVendorUser();

    $floorTile = LineItem::create(['name' => 'Floor Tile', 'category' => 'Flooring', 'unit_type' => 'sq.ft.', 'cost' => 10, 'desc' => 'Floor tile material', 'belongs_to_vendor_id' => 1]);

    // Canonical global catalog entry.
    $global = LineItemAllowance::create(['line_item_id' => $floorTile->id, 'description' => 'Tile', 'pricing_mode' => 'per_unit', 'unit_amount' => 5, 'amount' => 105]);

    // Estimate line item carrying a stale free-text allowance with the rate baked into the text.
    $estimate = Estimate::create(['belongs_to_vendor_id' => 1]);
    $section = EstimateSection::create(['estimate_id' => $estimate->id, 'name' => 'Main', 'order' => 0, 'total' => 0]);
    $estimateLineItem = EstimateLineItem::create([
        'estimate_id' => $estimate->id,
        'line_item_id' => $floorTile->id,
        'section_id' => $section->id,
        'name' => $floorTile->name,
        'category' => $floorTile->category,
        'unit_type' => $floorTile->unit_type,
        'quantity' => 30,
        'cost' => $floorTile->cost,
        'total' => 300,
        'desc' => $floorTile->desc,
    ]);
    $legacy = $estimateLineItem->allowances()->create([
        'line_item_allowance_id' => null,
        'description' => 'Tile: $5/sqft',
        'pricing_mode' => 'per_unit',
        'unit_amount' => null,
        'amount' => 150,
    ]);

    Livewire::test(EstimateLineItemCreate::class, ['estimate' => $estimate])
        ->call('editOnEstimate', $estimateLineItem->id)
        ->assertSet('form.allowances.0.description', 'Tile')
        ->assertSet('form.allowances.0.pricing_mode', 'per_unit')
        ->assertSet('form.allowances.0.unit_amount', '5.00')
        ->assertSet('form.allowances.0.amount', '150.00')
        ->call('edit')
        ->assertHasNoErrors();

    $legacy->refresh();
    expect($legacy->description)->toBe('Tile');
    expect($legacy->line_item_allowance_id)->toBe($global->id);
    expect($legacy->pricing_mode)->toBe('per_unit');
    expect((float) $legacy->unit_amount)->toBe(5.0);
    expect((float) $legacy->amount)->toBe(150.0);
});

it('reconciles a legacy wall tile allowance to a vendor-wide global tile catalog entry', function () {
    $user = actingAsVendorUser();
    $vendorId = $user->primary_vendor_id;

    $floorTile = LineItem::create([
        'name' => 'Floor Tile',
        'category' => 'Flooring',
        'unit_type' => 'sq.ft.',
        'cost' => 10,
        'desc' => 'Floor tile material',
        'belongs_to_vendor_id' => $vendorId,
    ]);

    $global = LineItemAllowance::create([
        'line_item_id' => $floorTile->id,
        'description' => 'Tile',
        'pricing_mode' => 'per_unit',
        'unit_amount' => 5,
        'amount' => 105,
        'belongs_to_vendor_id' => $vendorId,
    ]);

    $wallTile = LineItem::create([
        'name' => 'Wall Tile',
        'category' => 'Tile',
        'unit_type' => 'sq.ft.',
        'cost' => 10,
        'desc' => 'Wall tile material',
        'belongs_to_vendor_id' => $vendorId,
    ]);

    $estimate = Estimate::create(['belongs_to_vendor_id' => $vendorId]);
    $section = EstimateSection::create(['estimate_id' => $estimate->id, 'name' => 'Main', 'order' => 0, 'total' => 0]);
    $estimateLineItem = EstimateLineItem::create([
        'estimate_id' => $estimate->id,
        'line_item_id' => $wallTile->id,
        'section_id' => $section->id,
        'name' => $wallTile->name,
        'category' => $wallTile->category,
        'unit_type' => $wallTile->unit_type,
        'quantity' => 88,
        'cost' => $wallTile->cost,
        'total' => 880,
        'desc' => $wallTile->desc,
    ]);
    $legacy = $estimateLineItem->allowances()->create([
        'line_item_allowance_id' => null,
        'description' => 'Tile: $5/sqft',
        'pricing_mode' => 'per_unit',
        'unit_amount' => null,
        'amount' => 400,
    ]);

    Livewire::test(EstimateLineItemCreate::class, ['estimate' => $estimate])
        ->tap(function ($component) use ($estimateLineItem) {
            $reflection = new ReflectionClass($component->instance()->form);
            $method = $reflection->getMethod('globalAllowancesFor');
            $method->setAccessible(true);

            $globals = $method->invoke($component->instance()->form, $estimateLineItem->fresh(['allowances']));

            expect($globals->pluck('description')->all())->toContain('Tile');
        })
        ->call('editOnEstimate', $estimateLineItem->id)
        ->assertSet('form.allowances.0.description', 'Tile')
        ->assertSet('form.allowances.0.pricing_mode', 'per_unit')
        ->assertSet('form.allowances.0.unit_amount', '5.00')
        ->assertSet('form.allowances.0.amount', '440.00')
        ->call('edit')
        ->assertHasNoErrors();

    $legacy->refresh();
    expect($legacy->description)->toBe('Tile');
    expect($legacy->line_item_allowance_id)->toBe($global->id);
    expect($legacy->pricing_mode)->toBe('per_unit');
    expect((float) $legacy->unit_amount)->toBe(5.0);
    expect((float) $legacy->amount)->toBe(440.0);
    expect(LineItemAllowance::where('line_item_id', $wallTile->id)->count())->toBe(0);
});

it('creates and links a global allowance when an estimate line item is saved', function () {
    actingAsVendorUser();

    $estimate = Estimate::create(['belongs_to_vendor_id' => 1]);
    $section = EstimateSection::create(['estimate_id' => $estimate->id, 'name' => 'Main', 'order' => 0, 'total' => 0]);

    $floorTile = LineItem::create(['name' => 'Floor Tile', 'category' => 'Flooring', 'unit_type' => 'sq.ft.', 'cost' => 10, 'desc' => 'Floor tile material']);

    $component = Livewire::test(EstimateLineItemCreate::class, ['estimate' => $estimate])
        ->set('section_id', $section->id)
        ->set('line_item_id', $floorTile->id)
        ->set('form.quantity', 20)
        ->call('addAllowance')
        ->set('form.allowances.0.description', 'Tile material budget')
        ->set('form.allowances.0.unit_amount', 25)
        ->call('save')
        ->assertHasNoErrors();

    $global = LineItemAllowance::where('line_item_id', $floorTile->id)
        ->where('description', 'Tile material budget')
        ->first();

    expect($global)->not->toBeNull();
    expect((float) $global->amount)->toBe(500.0);
    expect((float) $global->unit_amount)->toBe(25.0);

    $estimateAllowance = EstimateLineItemAllowance::where('line_item_allowance_id', $global->id)->first();

    expect($estimateAllowance)->not->toBeNull();
    expect($estimateAllowance->description)->toBe('Tile material budget');

    $global = LineItemAllowance::where('line_item_id', $floorTile->id)
        ->where('description', 'Tile material budget')
        ->first();

    expect($global)->not->toBeNull();
    expect((float) $global->amount)->toBe(500.0);
    expect((float) $global->unit_amount)->toBe(25.0);

    $estimateAllowance = EstimateLineItemAllowance::where('line_item_allowance_id', $global->id)->first();

    expect($estimateAllowance)->not->toBeNull();
    expect($estimateAllowance->description)->toBe('Tile material budget');
});

it('adds a brand-new allowance not in the dropdown and saves it to the global catalog when editing', function () {
    actingAsVendorUser();

    $floorTile = LineItem::create(['name' => 'Floor Tile', 'category' => 'Flooring', 'unit_type' => 'sq.ft.', 'cost' => 10, 'desc' => 'Floor tile material', 'belongs_to_vendor_id' => 1]);

    // Existing catalog entry that already appears in the dropdown.
    LineItemAllowance::create(['line_item_id' => $floorTile->id, 'description' => 'Existing Budget', 'pricing_mode' => 'per_unit', 'unit_amount' => 25, 'amount' => 500]);

    // An estimate line item that already references the existing allowance.
    $estimate = Estimate::create(['belongs_to_vendor_id' => 1]);
    $section = EstimateSection::create(['estimate_id' => $estimate->id, 'name' => 'Main', 'order' => 0, 'total' => 0]);
    $estimateLineItem = EstimateLineItem::create([
        'estimate_id' => $estimate->id,
        'line_item_id' => $floorTile->id,
        'section_id' => $section->id,
        'name' => $floorTile->name,
        'category' => $floorTile->category,
        'unit_type' => $floorTile->unit_type,
        'quantity' => 20,
        'cost' => $floorTile->cost,
        'total' => 200,
        'desc' => $floorTile->desc,
    ]);
    $existing = $estimateLineItem->allowances()->create([
        'line_item_allowance_id' => null,
        'description' => 'Existing Budget',
        'pricing_mode' => 'per_unit',
        'unit_amount' => 25,
        'amount' => 500,
    ]);

    Livewire::test(EstimateLineItemCreate::class, ['estimate' => $estimate])
        ->call('editOnEstimate', $estimateLineItem->id)
        ->tap(function ($component) {
            expect($component->get('form.allowances'))->toHaveCount(1);
        })
        ->call('addAllowance')
        ->set('form.allowances.1.description', 'Brand New Allowance')
        ->set('form.allowances.1.pricing_mode', 'lump_sum')
        ->set('form.allowances.1.amount', '125.00')
        ->call('edit')
        ->assertHasNoErrors();

    $new = LineItemAllowance::where('line_item_id', $floorTile->id)->where('description', 'Brand New Allowance')->first();
    expect($new)->not->toBeNull();
    expect($new->pricing_mode)->toBe('lump_sum');
    expect((float) $new->amount)->toBe(125.0);

    // Existing catalog entry remains intact.
    expect(LineItemAllowance::where('line_item_id', $floorTile->id)->where('description', 'Existing Budget')->count())->toBe(1);

    // The estimate line item now has both allowances, the new one linked to the global catalog.
    $estimateNew = EstimateLineItemAllowance::where('description', 'Brand New Allowance')->first();
    expect($estimateNew)->not->toBeNull();
    expect($estimateNew->line_item_allowance_id)->toBe($new->id);
});

it('calculates the allowance amount from the per-unit amount and borrowed quantity', function () {
    actingAsVendorUser();

    $estimate = Estimate::create(['belongs_to_vendor_id' => 1]);
    $section = EstimateSection::create(['estimate_id' => $estimate->id, 'name' => 'Main', 'order' => 0, 'total' => 0]);

    $floorTile = LineItem::create(['name' => 'Floor Tile', 'category' => 'Flooring', 'unit_type' => 'sq.ft.', 'cost' => 10]);

    Livewire::test(EstimateLineItemCreate::class, ['estimate' => $estimate])
        ->set('line_item_id', $floorTile->id)
        ->set('form.quantity', 20)
        ->call('addAllowance')
        ->set('form.allowances.0.unit_amount', 25)
        ->assertSet('form.allowances.0.amount', '500.00')
        ->set('form.quantity', 10)
        ->assertSet('form.allowances.0.amount', '250.00');
});

it('derives the per-unit amount from the total when per-unit is re-enabled', function () {
    actingAsVendorUser();

    $estimate = Estimate::create(['belongs_to_vendor_id' => 1]);

    $floorTile = LineItem::create(['name' => 'Floor Tile', 'category' => 'Flooring', 'unit_type' => 'sq.ft.', 'cost' => 10]);

    Livewire::test(EstimateLineItemCreate::class, ['estimate' => $estimate])
        ->set('line_item_id', $floorTile->id)
        ->set('form.quantity', 21)
        ->call('addAllowance')
        ->set('form.allowances.0.unit_amount', 5)
        ->assertSet('form.allowances.0.amount', '105.00')
        ->call('toggleAllowancePerUnit', 0)
        ->assertSet('form.allowances.0.pricing_mode', 'lump_sum')
        ->assertSet('form.allowances.0.unit_amount', '')
        ->call('toggleAllowancePerUnit', 0)
        ->assertSet('form.allowances.0.pricing_mode', 'per_unit')
        ->assertSet('form.allowances.0.unit_amount', '5.00')
        ->assertSet('form.allowances.0.amount', '105.00');
});

it('keeps a lump-sum allowance total flat when the quantity changes', function () {
    actingAsVendorUser();

    $estimate = Estimate::create(['belongs_to_vendor_id' => 1]);

    $floorTile = LineItem::create(['name' => 'Floor Tile', 'category' => 'Flooring', 'unit_type' => 'sq.ft.', 'cost' => 10]);

    Livewire::test(EstimateLineItemCreate::class, ['estimate' => $estimate])
        ->set('line_item_id', $floorTile->id)
        ->set('form.quantity', 30)
        ->call('addAllowance')
        ->set('form.allowances.0.unit_amount', 5)
        ->assertSet('form.allowances.0.amount', '150.00')
        ->set('form.allowances.0.pricing_mode', 'lump_sum')
        ->assertSet('form.allowances.0.unit_amount', '')
        ->set('form.allowances.0.amount', '36.30')
        ->set('form.quantity', 50)
        ->assertSet('form.allowances.0.amount', '36.30');
});

it('saves a lump-sum allowance with no per-unit amount', function () {
    actingAsVendorUser();

    $estimate = Estimate::create(['belongs_to_vendor_id' => 1]);
    $section = EstimateSection::create(['estimate_id' => $estimate->id, 'name' => 'Main', 'order' => 0, 'total' => 0]);

    $floorTile = LineItem::create(['name' => 'Floor Tile', 'category' => 'Flooring', 'unit_type' => 'sq.ft.', 'cost' => 10, 'desc' => 'Floor tile material']);

    Livewire::test(EstimateLineItemCreate::class, ['estimate' => $estimate])
        ->set('section_id', $section->id)
        ->set('line_item_id', $floorTile->id)
        ->set('form.quantity', 30)
        ->call('addAllowance')
        ->set('form.allowances.0.description', 'Grout')
        ->set('form.allowances.0.pricing_mode', 'lump_sum')
        ->set('form.allowances.0.amount', '36.30')
        ->call('save')
        ->assertHasNoErrors();

    $allowance = EstimateLineItemAllowance::where('description', 'Grout')->first();

    expect($allowance)->not->toBeNull();
    expect($allowance->pricing_mode)->toBe('lump_sum');
    expect($allowance->unit_amount)->toBeNull();
    expect((float) $allowance->amount)->toBe(36.30);

    $global = LineItemAllowance::where('line_item_id', $floorTile->id)->where('description', 'Grout')->first();
    expect($global->pricing_mode)->toBe('lump_sum');
    expect($global->unit_amount)->toBeNull();
});

it('renders allowances inline beneath line items in the index table', function () {
    actingAsVendorUser();

    $floorTile = LineItem::create([
        'name' => 'Floor Tile',
        'category' => 'Flooring',
        'unit_type' => 'sq.ft.',
        'cost' => 10,
    ]);

    LineItemAllowance::create([
        'line_item_id' => $floorTile->id,
        'description' => 'Tile Material Budget',
        'pricing_mode' => 'per_unit',
        'unit_amount' => 30,
        'amount' => 600,
    ]);

    LineItemAllowance::create([
        'line_item_id' => $floorTile->id,
        'description' => 'Grout Upgrade',
        'pricing_mode' => 'lump_sum',
        'amount' => 75,
    ]);

    Livewire::test(LineItemsIndex::class)
        ->assertSee('Floor Tile')
        ->assertSee('Allowance')
        ->assertSee('Tile Material Budget')
        ->assertSee('Grout Upgrade')
        ->assertSee('600.00')
        ->assertSee('75.00');
});

it('renders historical estimate allowances inline when no global allowance catalog exists', function () {
    actingAsVendorUser();

    $wallTile = LineItem::create([
        'name' => 'Wall Tile',
        'category' => 'Tile',
        'unit_type' => 'sq.ft.',
        'cost' => 10,
    ]);

    // Historical estimate allowance only (no line_item_allowances rows).
    makeEstimateAllowanceFor($wallTile, 'Tile: $5/sqft', null, 400);

    expect(LineItemAllowance::where('line_item_id', $wallTile->id)->count())->toBe(0);

    Livewire::test(LineItemsIndex::class)
        ->assertSee('Wall Tile')
        ->assertSee('Allowance')
        ->assertSee('Tile')
        ->assertSee('/ sq.ft.');
});

it('returns no previous allowances when no line item is selected', function () {
    $estimate = Estimate::create(['belongs_to_vendor_id' => 1]);

    Livewire::test(EstimateLineItemCreate::class, ['estimate' => $estimate])
        ->tap(function ($component) {
            expect($component->instance()->previousAllowances())->toBeEmpty();
        });
});

it('does not render a separate allowances tab on the line items index', function () {
    actingAsVendorUser();

    Livewire::test(LineItemsIndex::class)
        ->assertDontSee('name="allowances"')
        ->assertSee('Line Items');
});

it('seeds the editable allowance catalog from past estimates in the line item modal', function () {
    actingAsVendorUser();

    $floorTile = LineItem::create(['name' => 'Floor Tile', 'category' => 'Flooring', 'unit_type' => 'sq.ft.', 'cost' => 10]);

    makeEstimateAllowanceFor($floorTile, 'Tile material budget', 30, 600);
    makeEstimateAllowanceFor($floorTile, 'Grout upgrade', null, 75, 'lump_sum');

    Livewire::test(LineItemCreate::class)
        ->call('editItem', $floorTile)
        ->tap(function ($component) {
            $allowances = collect($component->get('form.allowances'));
            $descriptions = $allowances->pluck('description');

            expect($descriptions)->toContain('Tile Material Budget');
            expect($descriptions)->toContain('Grout Upgrade');

            $tile = $allowances->firstWhere('description', 'Tile Material Budget');
            expect($tile['pricing_mode'])->toBe('per_unit');
            expect((float) $tile['unit_amount'])->toBe(30.0);

            $grout = $allowances->firstWhere('description', 'Grout Upgrade');
            expect($grout['pricing_mode'])->toBe('lump_sum');
        });
});

it('saves edited global allowances from the line item modal', function () {
    actingAsVendorUser();

    $floorTile = LineItem::create(['name' => 'Floor Tile', 'category' => 'Flooring', 'unit_type' => 'sq.ft.', 'cost' => 10, 'desc' => 'Floor tile material', 'belongs_to_vendor_id' => 1]);

    Livewire::test(LineItemCreate::class)
        ->call('editItem', $floorTile)
        ->call('addAllowance')
        ->set('form.allowances.0.description', 'Tile Purchase')
        ->set('form.allowances.0.pricing_mode', 'per_unit')
        ->set('form.allowances.0.unit_amount', '5.00')
        ->call('addAllowance')
        ->set('form.allowances.1.description', 'Grout')
        ->set('form.allowances.1.pricing_mode', 'lump_sum')
        ->set('form.allowances.1.amount', '60.00')
        ->call('edit')
        ->assertHasNoErrors();

    $tile = LineItemAllowance::where('line_item_id', $floorTile->id)->where('description', 'Tile Purchase')->first();
    expect($tile)->not->toBeNull();
    expect($tile->pricing_mode)->toBe('per_unit');
    expect((float) $tile->unit_amount)->toBe(5.0);

    $grout = LineItemAllowance::where('line_item_id', $floorTile->id)->where('description', 'Grout')->first();
    expect($grout)->not->toBeNull();
    expect($grout->pricing_mode)->toBe('lump_sum');
    expect($grout->unit_amount)->toBeNull();
    expect((float) $grout->amount)->toBe(60.0);
});

it('removes a deleted allowance from the global catalog on save', function () {
    actingAsVendorUser();

    $floorTile = LineItem::create(['name' => 'Floor Tile', 'category' => 'Flooring', 'unit_type' => 'sq.ft.', 'cost' => 10, 'desc' => 'Floor tile material', 'belongs_to_vendor_id' => 1]);

    $keep = LineItemAllowance::create(['line_item_id' => $floorTile->id, 'description' => 'Tile Purchase', 'pricing_mode' => 'per_unit', 'unit_amount' => 5]);
    $remove = LineItemAllowance::create(['line_item_id' => $floorTile->id, 'description' => 'Old Allowance', 'pricing_mode' => 'lump_sum', 'amount' => 25]);

    Livewire::test(LineItemCreate::class)
        ->call('editItem', $floorTile)
        ->tap(function ($component) {
            expect($component->get('form.allowances'))->toHaveCount(2);
        })
        ->call('removeAllowance', 1)
        ->call('edit')
        ->assertHasNoErrors();

    expect(LineItemAllowance::find($keep->id))->not->toBeNull();
    expect(LineItemAllowance::find($remove->id))->toBeNull();
    expect(LineItemAllowance::withTrashed()->find($remove->id)->trashed())->toBeTrue();
});
