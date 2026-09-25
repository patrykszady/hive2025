<?php

use App\Livewire\Transactions\MatchVendor;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function sec2m_admin(): array
{
    $vendor = Vendor::factory()->create();
    $admin = new User();
    $admin->forceFill([
        'first_name' => 'Admin', 'last_name' => 'User',
        'email' => 'sec2m-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'password' => bcrypt('password'), 'primary_vendor_id' => $vendor->id,
    ]);
    $admin->save();
    $vendor->users()->attach($admin->id, ['role_id' => 1]);

    return [$vendor, $admin];
}

function sec2m_bankAccount(Vendor $vendor): BankAccount
{
    $bank = Bank::create(['name' => 'Bank of '.$vendor->id, 'vendor_id' => $vendor->id, 'plaid_ins_id' => 'ins_'.$vendor->id]);

    return BankAccount::create([
        'vendor_id' => $vendor->id, 'bank_id' => $bank->id, 'account_number' => '000'.$vendor->id,
        'plaid_account_id' => 'acc_'.$vendor->id, 'type' => 'checking',
    ]);
}

// Finding 8: merchantCards()/expenseCards() dropped Transaction/Expense's
// tenant scope entirely — every company's unmatched bank activity and
// receipts must be scoped back to the signed-in company's own bank accounts.
it('only shows the signed-in company\'s own unmatched transactions, not another tenant\'s', function () {
    [$companyA, $adminA] = sec2m_admin();
    [$companyB] = sec2m_admin();
    $accountA = sec2m_bankAccount($companyA);
    $accountB = sec2m_bankAccount($companyB);

    Transaction::create([
        'transaction_date' => now()->subDay(), 'amount' => 12.00, 'bank_account_id' => $accountA->id,
        'plaid_merchant_name' => 'MINE CO', 'plaid_merchant_description' => 'MINE CO',
    ]);
    Transaction::create([
        'transaction_date' => now()->subDay(), 'amount' => 34.00, 'bank_account_id' => $accountB->id,
        'plaid_merchant_name' => 'THEIRS CO', 'plaid_merchant_description' => 'THEIRS CO',
    ]);

    test()->actingAs($adminA);
    $component = new MatchVendor();

    expect($component->merchantCards()->keys()->all())->toBe(['MINE CO']);
});

it('only shows the signed-in company\'s own unmatched receipt expenses, not another tenant\'s', function () {
    [$companyA, $adminA] = sec2m_admin();
    [$companyB, $adminB] = sec2m_admin();

    $expenseA = Expense::withoutGlobalScopes()->create([
        'amount' => -50.00, 'date' => '2026-09-01', 'vendor_id' => 0,
        'belongs_to_vendor_id' => $companyA->id, 'created_by_user_id' => $adminA->id,
    ]);
    ExpenseReceipts::create([
        'expense_id' => $expenseA->id, 'receipt_filename' => 'a.pdf', 'receipt_html' => '<div></div>',
        'receipt_items' => ['items' => [], 'total' => '50.00', 'merchant_name' => 'MINE RECEIPT CO'],
    ]);

    $expenseB = Expense::withoutGlobalScopes()->create([
        'amount' => -75.00, 'date' => '2026-09-01', 'vendor_id' => 0,
        'belongs_to_vendor_id' => $companyB->id, 'created_by_user_id' => $adminB->id,
    ]);
    ExpenseReceipts::create([
        'expense_id' => $expenseB->id, 'receipt_filename' => 'b.pdf', 'receipt_html' => '<div></div>',
        'receipt_items' => ['items' => [], 'total' => '75.00', 'merchant_name' => 'THEIRS RECEIPT CO'],
    ]);

    test()->actingAs($adminA);
    $component = new MatchVendor();

    expect($component->expenseCards()->keys()->all())->toBe(['MINE RECEIPT CO']);
});

// Finding 5/9: linkVendorToCompany() (used by applySuggestion() and
// store()/store_expense_vendors()) must never attach another tenant's own
// registered company to the signed-in company's vendor list.
it('does not link another tenant\'s registered company when applying a match', function () {
    [$companyA, $adminA] = sec2m_admin();
    [$companyB] = sec2m_admin();
    $companyB->forceFill(['registration' => ['registered' => true]])->save();

    test()->actingAs($adminA);
    $component = new MatchVendor();
    $ref = new ReflectionClass($component);
    $method = $ref->getMethod('linkVendorToCompany');
    $method->setAccessible(true);
    $method->invoke($component, $companyB->id);

    expect(\Illuminate\Support\Facades\DB::table('vendors_vendor')
        ->where('belongs_to_vendor_id', $companyA->id)
        ->where('vendor_id', $companyB->id)
        ->exists())->toBeFalse();
});

it('still links an unregistered retail vendor when applying a match', function () {
    [$company, $admin] = sec2m_admin();
    $retailer = Vendor::factory()->create(['business_type' => 'Retail']);

    test()->actingAs($admin);
    $component = new MatchVendor();
    $ref = new ReflectionClass($component);
    $method = $ref->getMethod('linkVendorToCompany');
    $method->setAccessible(true);
    $method->invoke($component, $retailer->id);

    expect(\Illuminate\Support\Facades\DB::table('vendors_vendor')
        ->where('belongs_to_vendor_id', $company->id)
        ->where('vendor_id', $retailer->id)
        ->exists())->toBeTrue();
});
