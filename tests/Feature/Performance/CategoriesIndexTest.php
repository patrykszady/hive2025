<?php

use App\Livewire\Categories\CategoriesIndex;
use App\Livewire\Categories\VendorCategoryCard;
use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * /vendors/categories used to run one `SELECT COUNT(*) FROM expenses WHERE
 * vendor_id = ?` per vendor card (25 extra queries), and had no
 * authorization at all — any Member could re-categorize a company's
 * expenses. These pin: the count comes from one grouped withCount, and
 * writes require the Admin role.
 */
function perf_categoriesUser(int $roleId = 1): User
{
    $vendor = Vendor::factory()->create(['business_type' => 'GC']);

    $user = User::query()->create([
        'first_name' => 'Cat',
        'last_name' => $roleId === 1 ? 'Admin' : 'Member',
        'email' => 'cat-user-'.uniqid().'@example.test',
        'cell_phone' => '224'.rand(1000000, 9999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->vendors()->attach($vendor->id, ['role_id' => $roleId]);

    return $user;
}

function perf_categoriesRetailVendor(User $user, string $name): Vendor
{
    $retailVendor = Vendor::factory()->create(['business_type' => 'Retail', 'business_name' => $name]);
    $user->vendor->vendors()->attach($retailVendor->id);

    return $retailVendor;
}

/**
 * Same as perf_categoriesRetailVendor, but via raw DB inserts. VendorScope
 * reads `$user->vendor->vendors` as a cached property — attaching several
 * vendors through Eloquent in a loop while that relation is already
 * resolved (e.g. by an earlier scoped Vendor:: query in the same test)
 * leaves the cache stale at whatever it was after the first attach. Building
 * every fixture row first, then resolving the vendor relation exactly once,
 * avoids that entirely; this is a pre-existing VendorScope characteristic,
 * not something introduced by this fix.
 */
function perf_categoriesRetailVendorRaw(Vendor $companyVendor, string $name): int
{
    $id = DB::table('vendors')->insertGetId([
        'business_name' => $name,
        'business_type' => 'Retail',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('vendors_vendor')->insert([
        'belongs_to_vendor_id' => $companyVendor->id,
        'vendor_id' => $id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function perf_categoriesExpense(User $user, Vendor $retailVendor, ?int $categoryId = null): Expense
{
    return Expense::forceCreate([
        'amount' => 5,
        'date' => now()->subDay(),
        'vendor_id' => $retailVendor->id,
        'category_id' => $categoryId,
        'belongs_to_vendor_id' => $user->vendor->id,
        'created_by_user_id' => $user->id,
    ]);
}

// CategoriesIndex's blade also calls $this->availableYears(), which uses a
// raw MySQL YEAR() SQL function unsupported on the sqlite test DB — a
// pre-existing, unrelated incompatibility (same category as the REGEXP
// failures noted in COMMON.md). These tests call mount()/render() directly
// instead of going through Livewire's HTML render so that pre-existing,
// unrelated limitation doesn't block testing the actual fix.
it('computes every card\'s expense count from one grouped query, flat as vendor count grows', function () {
    $user = perf_categoriesUser();
    $companyVendor = $user->vendor;

    // 8 retail vendors, each with a couple of expenses — the OLD code ran
    // one `count(*)` query per card on top of this. Fixtures are built
    // (and actingAs called) before any scoped Vendor:: query runs — see
    // perf_categoriesRetailVendorRaw().
    for ($i = 0; $i < 8; $i++) {
        $retailVendorId = perf_categoriesRetailVendorRaw($companyVendor, "Retail Vendor {$i}");
        Expense::forceCreate(['amount' => 5, 'date' => now()->subDay(), 'vendor_id' => $retailVendorId, 'belongs_to_vendor_id' => $companyVendor->id, 'created_by_user_id' => $user->id]);
        Expense::forceCreate(['amount' => 5, 'date' => now()->subDay(), 'vendor_id' => $retailVendorId, 'belongs_to_vendor_id' => $companyVendor->id, 'created_by_user_id' => $user->id]);
    }

    $this->actingAs($user);

    DB::enableQueryLog();
    $component = new CategoriesIndex();
    $component->mount();
    $view = $component->render();
    $vendors = $view->getData()['vendors'];
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::flushQueryLog();
    DB::disableQueryLog();

    expect($vendors)->toHaveCount(8);
    foreach ($vendors as $v) {
        expect($v->expense_count)->toBe(2);
    }

    $countQueries = $queries->filter(fn ($sql) => str_contains($sql, 'count(*)') && str_contains($sql, 'expenses'));
    // The withCount subquery is still literally a count(*), but there's
    // exactly ONE per distinct expense_count select — not one per card.
    expect($countQueries->unique()->count())->toBeLessThanOrEqual(1);
});

it('blocks a Member from viewing the categories page', function () {
    $user = perf_categoriesUser(roleId: 2);
    $this->actingAs($user);

    Livewire::test(CategoriesIndex::class)->assertForbidden();
});

it('lets an Admin view the categories page', function () {
    $user = perf_categoriesUser();
    $this->actingAs($user);

    $component = new CategoriesIndex();
    $component->mount(); // does not throw for an Admin
    $view = $component->render();

    expect($view->getData()['vendors'])->not->toBeNull();
});

it('blocks a Member from writing vendor category changes', function () {
    $user = perf_categoriesUser(roleId: 2);
    $retailVendor = perf_categoriesRetailVendor($user, 'Card Vendor');
    $category = Category::create([
        'primary' => 'A', 'detailed' => 'A', 'friendly_primary' => 'A', 'friendly_detailed' => 'A', 'icon_url' => '',
    ]);
    $this->actingAs($user);

    Livewire::test(VendorCategoryCard::class, ['vendor' => $retailVendor])
        ->call('updateSheetsType', 'Materials')
        ->assertForbidden();

    Livewire::test(VendorCategoryCard::class, ['vendor' => $retailVendor])
        ->call('updateVendorCategory', (string) $category->id)
        ->assertForbidden();

    Livewire::test(VendorCategoryCard::class, ['vendor' => $retailVendor])
        ->call('clearVendorCategory')
        ->assertForbidden();

    expect($retailVendor->fresh()->sheets_type)->toBeNull()
        ->and($retailVendor->fresh()->category_id)->toBeNull();
});

it('lets an Admin write vendor category changes using the pre-loaded count', function () {
    $user = perf_categoriesUser();
    $retailVendor = perf_categoriesRetailVendor($user, 'Card Vendor');
    $category = Category::create([
        'primary' => 'A', 'detailed' => 'A', 'friendly_primary' => 'A', 'friendly_detailed' => 'A', 'icon_url' => '',
    ]);
    perf_categoriesExpense($user, $retailVendor);
    perf_categoriesExpense($user, $retailVendor);
    perf_categoriesExpense($user, $retailVendor);
    $this->actingAs($user);

    Livewire::test(VendorCategoryCard::class, ['vendor' => $retailVendor, 'initialExpenseCount' => 3])
        ->assertSee('3') // uses the pre-loaded count, not its own query
        ->call('updateSheetsType', 'Materials')
        ->assertOk();

    expect($retailVendor->fresh()->sheets_type)->toBe('Materials');

    Livewire::test(VendorCategoryCard::class, ['vendor' => $retailVendor, 'initialExpenseCount' => 3])
        ->call('updateVendorCategory', (string) $category->id)
        ->assertOk();

    expect($retailVendor->fresh()->category_id)->toBe($category->id)
        ->and(Expense::where('vendor_id', $retailVendor->id)->where('category_id', $category->id)->count())->toBe(3);
});
