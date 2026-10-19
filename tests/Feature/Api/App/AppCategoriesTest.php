<?php

use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/** @return array{vendor: Vendor, user: User} */
function appCategoriesTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

/**
 * A shared/directory Retail vendor attached to vendor 1's own tree — real
 * retail vendors only enter a company's Categories list once attached to
 * `vendors()` (VendorScope, a global scope on every Vendor query including
 * CategoriesIndex's own, limits results to the signed-in company's tree).
 */
function appCategoriesRetailVendor(array $overrides = []): Vendor
{
    $vendor = Vendor::factory()->create(array_merge(['business_type' => 'Retail'], $overrides));
    // withoutGlobalScopes(): VendorScope reads auth()->user()->vendor->vendors,
    // and resolving THAT relation runs its own VendorScope-applied query — a
    // genuine circular read in the existing scope. Once Sanctum::actingAs()
    // has already run (as it has by the time a test calls this helper), the
    // plain scoped Vendor::find(1) recurses into that cycle; bypassing the
    // scope here (this is fixture setup, not a tenant-sensitive read) avoids it.
    Vendor::withoutGlobalScopes()->find(1)?->vendors()->syncWithoutDetaching([$vendor->id]);

    return $vendor;
}

/** category_id is not in Expense::$fillable (only reachable via a query-builder update(), as the app's own services do) — forceFill() sets it here for fixtures. */
function appCategoriesExpense(Vendor $vendor, array $overrides = []): Expense
{
    $categoryId = $overrides['category_id'] ?? null;
    unset($overrides['category_id']);

    $expense = Expense::create(array_merge([
        'amount' => 10,
        'date' => '2026-05-01',
        'vendor_id' => $vendor->id,
        'belongs_to_vendor_id' => 1,
        'created_by_user_id' => User::factory()->create(['primary_vendor_id' => 1])->id,
    ], $overrides));

    if ($categoryId !== null) {
        $expense->forceFill(['category_id' => $categoryId])->save();
    }

    return $expense;
}

it('needs a token to list category vendors', function () {
    $this->getJson('/api/app/v1/categories/vendors')->assertUnauthorized();
});

it('refuses a Member, like VendorPolicy::viewOptions on the web', function () {
    ['user' => $member] = appCategoriesTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->getJson('/api/app/v1/categories/vendors')->assertForbidden();
});

it('only lists retail vendors that have expenses, like CategoriesIndex', function () {
    ['user' => $admin] = appCategoriesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $withExpense = appCategoriesRetailVendor(['business_name' => 'Home Depot']);
    appCategoriesExpense($withExpense, ['amount' => 42]);

    $withoutExpense = appCategoriesRetailVendor(['business_name' => 'No Expenses Inc']);
    $sub = Vendor::factory()->create(['business_type' => 'Sub', 'business_name' => 'Some Sub']);

    $response = $this->getJson('/api/app/v1/categories/vendors')->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($withExpense->id)
        ->and($ids)->not->toContain($withoutExpense->id, $sub->id)
        ->and($response->json('data.0.expense_count'))->toBe(1);
});

it('sets a vendor\'s sheets_type', function () {
    ['user' => $admin] = appCategoriesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);
    $vendor = appCategoriesRetailVendor();

    $response = $this->putJson("/api/app/v1/categories/vendors/{$vendor->id}/sheets-type", [
        'sheets_type' => 'materials',
    ])->assertOk();

    expect($response->json('data.sheets_type'))->toBe('materials')
        ->and($vendor->refresh()->sheets_type)->toBe('materials');
});

it('sets a vendor\'s category and recategorizes its expenses in the given year', function () {
    ['user' => $admin] = appCategoriesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);
    $vendor = appCategoriesRetailVendor();
    $category = Category::create(['primary' => 'materials', 'friendly_primary' => 'Materials', 'detailed' => 'lumber', 'friendly_detailed' => 'Lumber', 'icon_url' => '']);

    $inYear = appCategoriesExpense($vendor, ['amount' => 10, 'date' => '2026-05-01']);
    $outOfYear = appCategoriesExpense($vendor, ['amount' => 20, 'date' => '2024-05-01']);

    $response = $this->putJson("/api/app/v1/categories/vendors/{$vendor->id}/category", [
        'category_id' => $category->id,
        'year' => '2026',
    ])->assertOk();

    expect($response->json('data.category.id'))->toBe($category->id)
        ->and($response->json('expense_count_updated'))->toBe(1)
        ->and($inYear->refresh()->category_id)->toBe($category->id)
        ->and($outOfYear->refresh()->category_id)->toBeNull();
});

it('reassigns a vendor\'s expenses from one category to another', function () {
    ['user' => $admin] = appCategoriesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);
    $vendor = appCategoriesRetailVendor();
    $from = Category::create(['primary' => 'a', 'friendly_primary' => 'A', 'detailed' => 'a', 'friendly_detailed' => 'A', 'icon_url' => '']);
    $to = Category::create(['primary' => 'b', 'friendly_primary' => 'B', 'detailed' => 'b', 'friendly_detailed' => 'B', 'icon_url' => '']);

    $expense = appCategoriesExpense($vendor, ['category_id' => $from->id]);

    $this->postJson("/api/app/v1/categories/vendors/{$vendor->id}/reassign", [
        'from_category_id' => $from->id,
        'to_category_id' => $to->id,
    ])->assertOk()->assertJsonPath('data.reassigned', 1);

    expect($expense->refresh()->category_id)->toBe($to->id);
});
