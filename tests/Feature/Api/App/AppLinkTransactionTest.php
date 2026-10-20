<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/** @return array{vendor: Vendor, admin: User, member: User} */
function appLinkTxnTeam(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);

    $admin = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($admin->id, ['role_id' => 1]);

    $member = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($member->id, ['role_id' => 2]);

    return compact('vendor', 'admin', 'member');
}

function appLinkTxnAccount(Vendor $vendor): BankAccount
{
    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $vendor->id, 'plaid_ins_id' => 'ins_'.uniqid()]);

    return BankAccount::create([
        'vendor_id' => $vendor->id, 'bank_id' => $bank->id, 'account_number' => '4903',
        'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'Checking',
    ]);
}

function appLinkTxnExpense(Vendor $vendor, array $overrides = []): Expense
{
    $merchant = Vendor::factory()->create(['business_name' => 'Schwake Stone']);

    return Expense::withoutSyncingToSearch(fn () => Expense::create(array_merge([
        'amount' => 498.03,
        'date' => '2026-09-24',
        'vendor_id' => $merchant->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => 1,
    ], $overrides)));
}

function appLinkTxnTransaction(BankAccount $account, array $overrides = []): Transaction
{
    return Transaction::withoutSyncingToSearch(fn () => Transaction::create(array_merge([
        'bank_account_id' => $account->id,
        'amount' => 497.23,
        'transaction_date' => '2026-08-19',
        'posted_date' => '2026-08-19',
        'plaid_merchant_description' => 'SCHWAKE STONE',
        'plaid_transaction_id' => 'txn_'.uniqid(),
    ], $overrides)));
}

it('needs a token for link-transaction-options', function () {
    $this->getJson('/api/app/v1/expenses/1/link-transaction-options')->assertUnauthorized();
});

it('offers unlinked charges near the expense date, amount or vendor', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appLinkTxnTeam();
    Sanctum::actingAs($admin, ['app']);

    $account = appLinkTxnAccount($vendor);
    $expense = appLinkTxnExpense($vendor, ['amount' => 498.03]);
    $near = appLinkTxnTransaction($account, ['amount' => 497.23, 'vendor_id' => $expense->vendor_id]);
    $alreadyLinked = appLinkTxnTransaction($account, ['amount' => 498.03, 'expense_id' => 999]);
    $tooOld = appLinkTxnTransaction($account, ['amount' => 498.03, 'transaction_date' => '2020-01-01', 'posted_date' => '2020-01-01']);

    $response = $this->getJson("/api/app/v1/expenses/{$expense->id}/link-transaction-options")->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($near->id)
        ->and($ids)->not->toContain($alreadyLinked->id)
        ->and($ids)->not->toContain($tooOld->id);
});

it('links a transaction to an expense, keeping the expense\'s own amount, like the web', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appLinkTxnTeam();
    Sanctum::actingAs($admin, ['app']);

    $account = appLinkTxnAccount($vendor);
    $expense = appLinkTxnExpense($vendor, ['amount' => 498.03]);
    $transaction = appLinkTxnTransaction($account, ['amount' => 497.23]);

    $response = $this->postJson("/api/app/v1/expenses/{$expense->id}/link-transaction", [
        'transaction_id' => $transaction->id,
    ])->assertOk();

    expect($transaction->fresh()->expense_id)->toBe($expense->id)
        ->and((float) $expense->fresh()->amount)->toBe(498.03)
        ->and((float) $response->json('data.amount'))->toBe(498.03);
});

it('404s linking a transaction that is already linked or on another bank', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appLinkTxnTeam();
    Sanctum::actingAs($admin, ['app']);

    $account = appLinkTxnAccount($vendor);
    $expense = appLinkTxnExpense($vendor);
    $alreadyLinked = appLinkTxnTransaction($account, ['expense_id' => 999]);

    $this->postJson("/api/app/v1/expenses/{$expense->id}/link-transaction", [
        'transaction_id' => $alreadyLinked->id,
    ])->assertNotFound();
});

it('refuses a Member linking a transaction, like the web (ExpensePolicy::update is Admin-only)', function () {
    ['vendor' => $vendor, 'member' => $member] = appLinkTxnTeam();

    $account = appLinkTxnAccount($vendor);
    $expense = appLinkTxnExpense($vendor);
    $transaction = appLinkTxnTransaction($account);

    Sanctum::actingAs($member, ['app']);

    $this->getJson("/api/app/v1/expenses/{$expense->id}/link-transaction-options")->assertForbidden();
    $this->postJson("/api/app/v1/expenses/{$expense->id}/link-transaction", [
        'transaction_id' => $transaction->id,
    ])->assertForbidden();

    expect($transaction->fresh()->expense_id)->toBeNull();
});

it('shows can_link_transaction on the expense detail, mirroring the web\'s @if', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appLinkTxnTeam();
    Sanctum::actingAs($admin, ['app']);

    $expense = appLinkTxnExpense($vendor);

    $response = $this->getJson("/api/app/v1/expenses/{$expense->id}")->assertOk();
    expect($response->json('data.can_link_transaction'))->toBeTrue();

    $account = appLinkTxnAccount($vendor);
    $transaction = appLinkTxnTransaction($account, ['amount' => (float) $expense->amount, 'expense_id' => $expense->id]);

    $response = $this->getJson("/api/app/v1/expenses/{$expense->id}")->assertOk();
    expect($response->json('data.can_link_transaction'))->toBeFalse();
});
