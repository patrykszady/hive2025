<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\Expense;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/** @return array{vendor: Vendor, admin: User, member: User} */
function appExpenseAssocTeam(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);

    $admin = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($admin->id, ['role_id' => 1]);

    $member = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($member->id, ['role_id' => 2]);

    return compact('vendor', 'admin', 'member');
}

function appExpenseAssocExpense(Vendor $vendor, array $overrides = []): Expense
{
    $merchant = Vendor::factory()->create(['business_name' => 'Home Depot']);

    return Expense::create(array_merge([
        'amount' => 100.00,
        'date' => now()->format('Y-m-d'),
        'vendor_id' => $merchant->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => 1,
    ], $overrides));
}

function appExpenseAssocCheck(Vendor $vendor, array $overrides = []): Check
{
    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $vendor->id, 'plaid_ins_id' => 'ins_'.uniqid()]);
    $account = BankAccount::create([
        'vendor_id' => $vendor->id, 'bank_id' => $bank->id, 'account_number' => '4903',
        'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'checking',
    ]);

    return Check::create(array_merge([
        'check_type' => 'Check',
        'check_number' => random_int(1000, 999999),
        'date' => now()->format('Y-m-d'),
        'bank_account_id' => $account->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => 1,
        'amount' => 0,
    ], $overrides));
}

// --- associate-options ---------------------------------------------------

it('needs a token for associate-options', function () {
    $this->getJson('/api/app/v1/expenses/1/associate-options')->assertUnauthorized();
});

it('suggests the same vendor or same-amount-within-3-months expenses when search is blank', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseAssocTeam();
    Sanctum::actingAs($admin, ['app']);

    $owner = appExpenseAssocExpense($vendor, ['amount' => 1500.00, 'date' => '2026-10-07']);
    $sameAmountNearby = appExpenseAssocExpense($vendor, ['amount' => 1500.00, 'date' => '2026-09-20']);
    $tooFarInThePast = appExpenseAssocExpense($vendor, ['amount' => 1500.00, 'date' => '2026-01-01']);

    $response = $this->getJson("/api/app/v1/expenses/{$owner->id}/associate-options")->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($sameAmountNearby->id)
        ->and($ids)->not->toContain($tooFarInThePast->id)
        ->and($ids)->not->toContain($owner->id);
});

it('searches by id, amount, vendor or invoice once a term is typed', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseAssocTeam();
    Sanctum::actingAs($admin, ['app']);

    $owner = appExpenseAssocExpense($vendor, ['amount' => 1500.00, 'date' => '2026-10-07']);
    $sixt = Vendor::factory()->create(['business_name' => 'Sixt Car Rental']);
    $refund = appExpenseAssocExpense($vendor, ['amount' => -44.99, 'date' => '2026-10-06', 'vendor_id' => $sixt->id, 'invoice' => 'SX-7781']);

    foreach (['Sixt', 'SX-7781', '44.99', '-44.99'] as $term) {
        $response = $this->getJson("/api/app/v1/expenses/{$owner->id}/associate-options?search=".urlencode($term))->assertOk();
        expect(collect($response->json('data'))->pluck('id'))->toContain($refund->id);
    }
});

it('refuses associate-options for an expense the user cannot view', function () {
    $otherVendor = Vendor::factory()->create();
    $otherExpense = appExpenseAssocExpense($otherVendor);

    ['member' => $member] = appExpenseAssocTeam();
    Sanctum::actingAs($member, ['app']);

    $this->getJson("/api/app/v1/expenses/{$otherExpense->id}/associate-options")->assertForbidden();
});

// --- associate -------------------------------------------------------------

it('groups two expenses together, the same way the web\'s Link Expenses does', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseAssocTeam();
    Sanctum::actingAs($admin, ['app']);

    $owner = appExpenseAssocExpense($vendor);
    $other = appExpenseAssocExpense($vendor);

    $response = $this->postJson("/api/app/v1/expenses/{$owner->id}/associate", [
        'associate_expense_id' => $other->id,
    ])->assertOk();

    expect($owner->fresh()->parent_expense_id)->toBe($other->id)
        ->and(collect($response->json('data.associated_expenses'))->pluck('id'))->toContain($other->id);
});

it('404s associating with an expense outside the tenant', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseAssocTeam();
    Sanctum::actingAs($admin, ['app']);

    $owner = appExpenseAssocExpense($vendor);

    $otherVendor = Vendor::factory()->create();
    $foreign = appExpenseAssocExpense($otherVendor);

    $this->postJson("/api/app/v1/expenses/{$owner->id}/associate", [
        'associate_expense_id' => $foreign->id,
    ])->assertNotFound();
});

// --- removeFromCheck ---------------------------------------------------

it('detaches an expense from a check and recalculates the check amount', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseAssocTeam();
    Sanctum::actingAs($admin, ['app']);

    $check = appExpenseAssocCheck($vendor, ['amount' => 100]);
    $expense = appExpenseAssocExpense($vendor, ['amount' => 100, 'check_id' => $check->id]);

    $response = $this->deleteJson("/api/app/v1/expenses/{$expense->id}/checks/{$check->id}")->assertOk();

    expect($expense->fresh()->check_id)->toBeNull()
        ->and((float) $check->fresh()->amount)->toBe(0.0)
        ->and($response->json('data.checks'))->toBe([]);
});

it('detaches an expense linked through the check_expense pivot too', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseAssocTeam();
    Sanctum::actingAs($admin, ['app']);

    $check = appExpenseAssocCheck($vendor, ['amount' => 100]);
    $expense = appExpenseAssocExpense($vendor, ['amount' => 100]);
    $expense->checks()->attach($check->id);

    $this->deleteJson("/api/app/v1/expenses/{$expense->id}/checks/{$check->id}")->assertOk();

    expect($expense->checks()->where('checks.id', $check->id)->exists())->toBeFalse()
        ->and((float) $check->fresh()->amount)->toBe(0.0);
});

it('refuses a Member removing an expense from a check, like the web', function () {
    ['vendor' => $vendor, 'member' => $member] = appExpenseAssocTeam();

    $check = appExpenseAssocCheck($vendor, ['amount' => 100]);
    $expense = appExpenseAssocExpense($vendor, ['amount' => 100, 'check_id' => $check->id, 'paid_by' => $member->id]);

    Sanctum::actingAs($member, ['app']);

    $this->deleteJson("/api/app/v1/expenses/{$expense->id}/checks/{$check->id}")->assertForbidden();

    expect($expense->fresh()->check_id)->toBe($check->id);
});

it('404s removing from a check that does not exist', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseAssocTeam();
    Sanctum::actingAs($admin, ['app']);

    $expense = appExpenseAssocExpense($vendor);

    $this->deleteJson("/api/app/v1/expenses/{$expense->id}/checks/999999")->assertNotFound();
});

// --- show() surfaces the new fields ----------------------------------------

it('shows linked checks and same-check siblings on the expense detail', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseAssocTeam();
    Sanctum::actingAs($admin, ['app']);

    $check = appExpenseAssocCheck($vendor, ['check_number' => 4821, 'amount' => 250]);
    $expense = appExpenseAssocExpense($vendor, ['amount' => 150, 'check_id' => $check->id]);
    $sibling = appExpenseAssocExpense($vendor, ['amount' => 100, 'check_id' => $check->id]);

    $response = $this->getJson("/api/app/v1/expenses/{$expense->id}")->assertOk();

    expect(collect($response->json('data.checks'))->pluck('id')->all())->toBe([$check->id])
        ->and((int) $response->json('data.checks.0.check_number'))->toBe(4821)
        ->and(collect($response->json('data.check_associated_expenses'))->pluck('id')->all())->toBe([$sibling->id]);
});
