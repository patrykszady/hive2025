<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * AppAuthTest's appTeamUser(). $role is the vendor_user pivot's role_id
 * (1 = Admin, 2 = Member) — TransactionBulkMatchPolicy gates everything on
 * it, the same as App\Livewire\Transactions\MatchVendor's own render().
 *
 * @return array{vendor: Vendor, user: User}
 */
function appBooksMatchTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

function appBooksMatchBankAccount(Vendor $vendor): BankAccount
{
    $bank = Bank::create(['name' => 'Bank of '.$vendor->id, 'vendor_id' => $vendor->id, 'plaid_ins_id' => 'ins_'.uniqid()]);

    return BankAccount::create([
        'vendor_id' => $vendor->id, 'bank_id' => $bank->id, 'account_number' => random_int(1000, 9999),
        'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'Checking',
    ]);
}

it('needs a token to see unmatched transactions', function () {
    $this->getJson('/api/app/v1/transactions/unmatched')->assertUnauthorized();
});

it('refuses a Member seeing unmatched transactions, like the web', function () {
    ['user' => $member] = appBooksMatchTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->getJson('/api/app/v1/transactions/unmatched')->assertForbidden();
});

it('only shows the signed-in company\'s own unmatched transactions and receipts, not another tenant\'s', function () {
    ['vendor' => $companyA, 'user' => $adminA] = appBooksMatchTeamUser(role: 1);
    $companyB = Vendor::factory()->create();

    $accountA = appBooksMatchBankAccount($companyA);
    $accountB = appBooksMatchBankAccount($companyB);

    Transaction::create([
        'transaction_date' => now()->subDay(), 'amount' => 12.00, 'bank_account_id' => $accountA->id,
        'plaid_merchant_name' => 'MINE CO', 'plaid_merchant_description' => 'MINE CO',
    ]);
    Transaction::create([
        'transaction_date' => now()->subDay(), 'amount' => 34.00, 'bank_account_id' => $accountB->id,
        'plaid_merchant_name' => 'THEIRS CO', 'plaid_merchant_description' => 'THEIRS CO',
    ]);

    $expenseA = Expense::withoutGlobalScopes()->create([
        'amount' => -50.00, 'date' => '2026-09-01', 'vendor_id' => 0,
        'belongs_to_vendor_id' => $companyA->id, 'created_by_user_id' => $adminA->id,
    ]);
    ExpenseReceipts::create([
        'expense_id' => $expenseA->id, 'receipt_filename' => 'a.pdf', 'receipt_html' => '<div></div>',
        'receipt_items' => ['items' => [], 'total' => '50.00', 'merchant_name' => 'MINE RECEIPT CO'],
    ]);

    Sanctum::actingAs($adminA, ['app']);
    $response = $this->getJson('/api/app/v1/transactions/unmatched')->assertOk();

    expect(collect($response->json('data.merchant_cards'))->pluck('descriptor')->all())->toBe(['MINE CO'])
        ->and(collect($response->json('data.expense_cards'))->pluck('merchant_name')->all())->toBe(['MINE RECEIPT CO']);
});

it('matches a merchant card to an existing vendor, writing a rule and matching the transaction', function () {
    ['vendor' => $company, 'user' => $admin] = appBooksMatchTeamUser(role: 1);
    $account = appBooksMatchBankAccount($company);

    $transaction = Transaction::create([
        'transaction_date' => now()->subDay(), 'amount' => 45.00, 'bank_account_id' => $account->id,
        'plaid_merchant_name' => 'Ace Hardware', 'plaid_merchant_description' => 'ACE HARDWARE #0412',
    ]);

    $retailer = Vendor::factory()->create(['business_name' => 'Ace Hardware', 'business_type' => 'Retail']);

    Sanctum::actingAs($admin, ['app']);
    $this->postJson('/api/app/v1/transactions/match', [
        'descriptor' => 'ACE HARDWARE #0412',
        'match_desc' => 'ACE HARDWARE',
        'vendor_id' => (string) $retailer->id,
    ])->assertOk();

    expect(VendorTransaction::query()->where('desc', 'ACE HARDWARE')->where('vendor_id', $retailer->id)->exists())->toBeTrue()
        ->and($company->vendors()->where('vendor_id', $retailer->id)->exists())->toBeTrue()
        ->and($transaction->refresh()->vendor_id)->toBe($retailer->id);
});

it('matches a merchant card to a brand-new retail vendor', function () {
    ['vendor' => $company, 'user' => $admin] = appBooksMatchTeamUser(role: 1);
    $account = appBooksMatchBankAccount($company);

    Transaction::create([
        'transaction_date' => now()->subDay(), 'amount' => 20.00, 'bank_account_id' => $account->id,
        'plaid_merchant_name' => 'New Shop', 'plaid_merchant_description' => 'NEW SHOP LLC',
    ]);

    Sanctum::actingAs($admin, ['app']);
    $response = $this->postJson('/api/app/v1/transactions/match', [
        'descriptor' => 'NEW SHOP LLC',
        'match_desc' => 'New Shop LLC',
        'vendor_id' => 'NEW',
    ])->assertOk();

    $createdVendorId = $response->json('data.vendor.id');

    expect($createdVendorId)->not->toBeNull()
        ->and(Vendor::withoutGlobalScopes()->find($createdVendorId)->business_name)->toContain('New Shop')
        ->and($company->vendors()->where('vendor_id', $createdVendorId)->exists())->toBeTrue();
});

it('matches a merchant card to a non-vendor code like Deposit', function () {
    ['vendor' => $company, 'user' => $admin] = appBooksMatchTeamUser(role: 1);
    appBooksMatchBankAccount($company);

    Sanctum::actingAs($admin, ['app']);
    $this->postJson('/api/app/v1/transactions/match', [
        'descriptor' => 'MOBILE DEPOSIT',
        'match_desc' => 'MOBILE DEPOSIT',
        'vendor_id' => 'DEPOSIT',
    ])->assertOk()
        ->assertJsonPath('data.vendor', null);

    expect(VendorTransaction::query()->where('desc', 'MOBILE DEPOSIT')->where('deposit_check', 1)->whereNull('vendor_id')->exists())->toBeTrue();
});

it('matches a receipt-expense card to an existing vendor', function () {
    ['vendor' => $company, 'user' => $admin] = appBooksMatchTeamUser(role: 1);

    $expense = Expense::withoutGlobalScopes()->create([
        'amount' => -50.00, 'date' => '2026-09-01', 'vendor_id' => 0,
        'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => $admin->id,
    ]);
    ExpenseReceipts::create([
        'expense_id' => $expense->id, 'receipt_filename' => 'a.pdf', 'receipt_html' => '<div></div>',
        'receipt_items' => ['items' => [], 'total' => '50.00', 'merchant_name' => 'Home Depot'],
    ]);

    $retailer = Vendor::factory()->create(['business_name' => 'Home Depot', 'business_type' => 'Retail']);

    Sanctum::actingAs($admin, ['app']);
    $this->postJson('/api/app/v1/transactions/match-expense', [
        'merchant_name' => 'Home Depot',
        'match_desc' => 'Home Depot',
        'vendor_id' => (string) $retailer->id,
    ])->assertOk();

    expect($expense->refresh()->vendor_id)->toBe($retailer->id)
        ->and($company->vendors()->where('vendor_id', $retailer->id)->exists())->toBeTrue();
});

it('404s matching an expense card that does not exist', function () {
    ['user' => $admin] = appBooksMatchTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $retailer = Vendor::factory()->create();

    $this->postJson('/api/app/v1/transactions/match-expense', [
        'merchant_name' => 'Nobody Home',
        'match_desc' => 'Nobody Home',
        'vendor_id' => (string) $retailer->id,
    ])->assertNotFound();
});

it('refuses a Member matching a transaction, like the web', function () {
    ['user' => $member] = appBooksMatchTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->postJson('/api/app/v1/transactions/match', [
        'descriptor' => 'X',
        'match_desc' => 'X',
        'vendor_id' => 'DEPOSIT',
    ])->assertForbidden();
});

it('lists, searches, creates, updates and deletes bulk-match rules', function () {
    ['user' => $admin] = appBooksMatchTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $vendor = Vendor::factory()->create(['business_name' => 'Costco']);

    $create = $this->postJson('/api/app/v1/vendor-transactions', [
        'desc' => 'COSTCO WHSE',
        'vendor_id' => $vendor->id,
    ])->assertCreated();

    $ruleId = $create->json('data.id');

    $list = $this->getJson('/api/app/v1/vendor-transactions?search=Costco')->assertOk();
    expect(collect($list->json('data'))->pluck('id')->all())->toContain($ruleId);

    $this->putJson("/api/app/v1/vendor-transactions/{$ruleId}", [
        'desc' => 'COSTCO WHOLESALE',
        'vendor_id' => $vendor->id,
    ])->assertOk();

    expect(VendorTransaction::find($ruleId)->desc)->toBe('COSTCO WHOLESALE');

    $this->deleteJson("/api/app/v1/vendor-transactions/{$ruleId}")->assertOk();
    expect(VendorTransaction::find($ruleId))->toBeNull();
});

it('refuses creating a duplicate bulk-match rule', function () {
    ['user' => $admin] = appBooksMatchTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $vendor = Vendor::factory()->create();

    VendorTransaction::create([
        'desc' => 'SAME RULE',
        'vendor_id' => $vendor->id,
        'options' => json_encode('/i'),
    ]);

    $this->postJson('/api/app/v1/vendor-transactions', [
        'desc' => 'SAME RULE',
        'vendor_id' => $vendor->id,
    ])->assertStatus(422);
});

it('refuses a Member managing bulk-match rules, like the web', function () {
    ['user' => $member] = appBooksMatchTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->getJson('/api/app/v1/vendor-transactions')->assertForbidden();
});
