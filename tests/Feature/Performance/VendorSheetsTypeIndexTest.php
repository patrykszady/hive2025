<?php

use App\Livewire\Vendors\VendorSheetsTypeIndex;
use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * /vendors/sheet_types used to eager-load EVERY expense for EVERY retail
 * vendor as public component state (17MB page). These pin: the page renders
 * correct per-category counts from an aggregate SQL query, never ships raw
 * expense rows, and the rendered payload doesn't grow with expense volume.
 */
function perf_sheetsTypeActor(): User
{
    // Not 'Retail': VendorFactory randomizes business_type, and a Retail
    // auth vendor with zero expenses of its own would hit a pre-existing,
    // unrelated blade quirk (an undefined $category_id for a vendor with no
    // expense-category groups at all) — out of scope for this perf fix.
    $vendor = Vendor::factory()->create(['business_type' => 'GC']);

    $user = User::query()->create([
        'first_name' => 'Sheets',
        'last_name' => 'Admin',
        'email' => 'sheets-admin-'.uniqid().'@example.test',
        'cell_phone' => '224'.rand(1000000, 9999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);

    return $user;
}

function perf_retailVendorFor(User $user): Vendor
{
    $retailVendor = Vendor::factory()->create(['business_type' => 'Retail']);
    // VendorScope only shows vendors linked via vendors_vendor to the
    // acting user's company.
    $user->vendor->vendors()->attach($retailVendor->id);

    return $retailVendor;
}

function perf_makeSheetsExpenses(User $user, Vendor $retailVendor, ?int $categoryId, int $count, string $invoicePrefix): void
{
    for ($i = 0; $i < $count; $i++) {
        Expense::forceCreate([
            'amount' => 9.99,
            'date' => now()->subDay(),
            'vendor_id' => $retailVendor->id,
            'category_id' => $categoryId,
            'belongs_to_vendor_id' => $user->vendor->id,
            'created_by_user_id' => $user->id,
            'invoice' => "{$invoicePrefix}-{$i}",
        ]);
    }
}

it('shows correct per-category counts computed by one aggregate query, not a raw expenses eager load', function () {
    $user = perf_sheetsTypeActor();
    $this->actingAs($user);

    $retailVendor = perf_retailVendorFor($user);
    $category = Category::create([
        'primary' => 'HOME_IMPROVEMENT', 'detailed' => 'HARDWARE',
        'friendly_primary' => 'Home Improvement', 'friendly_detailed' => 'Hardware',
        'icon_url' => '',
    ]);

    perf_makeSheetsExpenses($user, $retailVendor, $category->id, 3, 'catA');
    perf_makeSheetsExpenses($user, $retailVendor, null, 2, 'noCat');

    DB::enableQueryLog();
    $component = Livewire::test(VendorSheetsTypeIndex::class);
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::flushQueryLog();
    DB::disableQueryLog();

    // Counts render correctly from the aggregate.
    $component->assertSee('Home Improvement')
        ->assertSee('Hardware')
        ->assertSee('3') // categorized count
        ->assertSee('2'); // uncategorized count

    // The expenses query is a single grouped aggregate — never a bare
    // `select * from expenses` (that was the 17MB eager load).
    $expenseQueries = $queries->filter(fn ($sql) => str_contains($sql, 'expenses'));
    expect($expenseQueries)->toHaveCount(1);
    expect($expenseQueries->first())->toContain('group by')
        ->and($expenseQueries->first())->not->toContain('select *');
});

it('keeps the rendered payload flat as expense volume grows (no more raw expense rows shipped)', function () {
    $user = perf_sheetsTypeActor();
    $this->actingAs($user);

    $retailVendor = perf_retailVendorFor($user);
    $category = Category::create([
        'primary' => 'HOME_IMPROVEMENT', 'detailed' => 'HARDWARE',
        'friendly_primary' => 'Home Improvement', 'friendly_detailed' => 'Hardware',
        'icon_url' => '',
    ]);

    perf_makeSheetsExpenses($user, $retailVendor, $category->id, 3, 'small-batch');
    $smallHtml = Livewire::test(VendorSheetsTypeIndex::class)->html();

    // Blow the expense count up 50x — the page must not ship one row of
    // markup/state per expense (it used to dehydrate every expense model).
    perf_makeSheetsExpenses($user, $retailVendor, $category->id, 150, 'big-batch');
    $bigComponent = Livewire::test(VendorSheetsTypeIndex::class);
    $bigHtml = $bigComponent->html();

    // The aggregate count updates (proves the query re-ran and is correct)...
    $bigComponent->assertSee('153');
    // ...but none of the 150 new invoice numbers were ever dehydrated into
    // the page — only the aggregate count is shown.
    expect($bigHtml)->not->toContain('big-batch-0')
        ->and($bigHtml)->not->toContain('big-batch-149');

    // Payload size stays close to flat rather than growing with row count.
    expect(strlen($bigHtml))->toBeLessThan(strlen($smallHtml) + 2000);
});

it('saves categorized expenses via a scoped query instead of a pre-loaded relation', function () {
    $user = perf_sheetsTypeActor();
    $this->actingAs($user);

    $retailVendor = perf_retailVendorFor($user);
    $oldCategory = Category::create([
        'primary' => 'OLD', 'detailed' => 'OLD', 'friendly_primary' => 'Old', 'friendly_detailed' => 'Old', 'icon_url' => '',
    ]);
    $newCategory = Category::create([
        'primary' => 'NEW', 'detailed' => 'NEW', 'friendly_primary' => 'New', 'friendly_detailed' => 'New', 'icon_url' => '',
    ]);

    perf_makeSheetsExpenses($user, $retailVendor, $oldCategory->id, 2, 'move-me');

    // Livewire's Eloquent-collection synthesizer forbids setting nested
    // properties on a model through ->set() (legacy_model_binding is off),
    // same as the real wire:model bindings on this page — exercise the
    // component method directly instead, same as a real request would after
    // Livewire hydrates it.
    $component = Livewire::test(VendorSheetsTypeIndex::class);
    $instance = $component->instance();
    $vendorIndex = 0;
    $instance->vendors[$vendorIndex]->category_id = $newCategory->id;
    $instance->vendors[$vendorIndex]->categories = [(string) $oldCategory->id => true];
    $instance->save_vendor_categories($vendorIndex);

    expect(Expense::where('vendor_id', $retailVendor->id)->where('category_id', $newCategory->id)->count())->toBe(2)
        ->and(Expense::where('vendor_id', $retailVendor->id)->where('category_id', $oldCategory->id)->count())->toBe(0);
});

it('renders when the newest retail vendor has no expenses yet', function () {
    $user = perf_sheetsTypeActor();
    $this->actingAs($user);

    perf_retailVendorFor($user);

    Livewire::test(VendorSheetsTypeIndex::class)
        ->assertOk()
        ->assertSee('going forward');
});
