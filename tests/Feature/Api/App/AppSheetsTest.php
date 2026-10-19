<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Expense;
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
 * (1 = Admin, 2 = Member) — SheetPolicy gates everything on it.
 *
 * @return array{vendor: Vendor, user: User}
 */
function appBooksSheetsTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

function appBooksSheetBankAccount(Vendor $vendor, array $overrides = []): BankAccount
{
    $bank = Bank::create([
        'name' => 'Test Credit Union',
        'vendor_id' => $vendor->id,
        'plaid_access_token' => 'access-'.uniqid(),
        'plaid_ins_id' => 'ins_'.uniqid(),
    ]);

    return BankAccount::create(array_merge([
        'bank_id' => $bank->id,
        'vendor_id' => $vendor->id,
        'account_number' => random_int(1000, 9999),
        'plaid_account_id' => 'acc_'.uniqid(),
        'type' => 'Checking',
    ], $overrides));
}

it('needs a token to see the sheets filter options', function () {
    $this->getJson('/api/app/v1/sheets')->assertUnauthorized();
});

it('refuses a Member viewing sheets, like the web', function () {
    ['user' => $member] = appBooksSheetsTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->getJson('/api/app/v1/sheets')->assertForbidden();
});

it('lists the bank accounts a sheet can be run against', function () {
    ['vendor' => $vendor, 'user' => $admin] = appBooksSheetsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    appBooksSheetBankAccount($vendor, ['type' => 'Checking']);
    appBooksSheetBankAccount($vendor, ['type' => 'Credit Card']); // not Checking/Savings — excluded

    $response = $this->getJson('/api/app/v1/sheets')->assertOk();

    expect(collect($response->json('data'))->flatMap(fn ($bank) => $bank['accounts'])->pluck('type')->all())
        ->toBe(['Checking']);
});

it('requires at least one bank account to run a sheet', function () {
    ['user' => $admin] = appBooksSheetsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $this->getJson('/api/app/v1/sheets/show')->assertStatus(422);
});

it('computes revenue, cost of materials and net operating income for the date range', function () {
    ['vendor' => $vendor, 'user' => $admin] = appBooksSheetsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $account = appBooksSheetBankAccount($vendor);

    $materialsVendor = Vendor::factory()->create(['business_name' => 'Home Depot', 'sheets_type' => 'Materials']);
    $vendor->vendors()->attach($materialsVendor->id);

    Expense::create([
        'amount' => 300,
        'date' => '2026-06-01',
        'vendor_id' => $materialsVendor->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $admin->id,
    ]);

    $response = $this->getJson('/api/app/v1/sheets/show?'.http_build_query([
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'bank_account_ids' => [$account->id],
    ]))->assertOk();

    expect((float) $response->json('data.revenue'))->toBe(0.0)
        ->and((float) $response->json('data.cost_of_goods_sold.materials.total'))->toBe(300.0)
        ->and((float) $response->json('data.cost_of_goods_sold.total'))->toBe(300.0)
        ->and((float) $response->json('data.net_operating_income'))->toBe(-300.0);
});

it('returns the trailing 12-month payments/expenses trend', function () {
    ['user' => $admin] = appBooksSheetsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson('/api/app/v1/sheets/monthly')->assertOk();

    expect($response->json('data'))->toHaveCount(12)
        ->and(array_keys($response->json('data.0')))->toBe(['month_year', 'this_year_payments', 'last_year_payments', 'monthly_total_expenses']);
});

it('refuses a Member viewing the monthly trend, like the web', function () {
    ['user' => $member] = appBooksSheetsTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->getJson('/api/app/v1/sheets/monthly')->assertForbidden();
});
