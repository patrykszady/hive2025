<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * checks:delete removes a check entered twice the way the check page does:
 * the check and the expense it created are soft-deleted, its bank payment is
 * unlinked. Cash check id 3563 duplicated id 3564 (2026-10-14).
 */
function duplicateCheckFixture(): array
{
    config(['scout.driver' => 'null']);
    $company = Vendor::factory()->create(['business_type' => 'GC', 'business_name' => 'GS Construction']);
    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $company->id, 'plaid_ins_id' => 'ins_5']);
    $checking = BankAccount::create(['vendor_id' => $company->id, 'bank_id' => $bank->id, 'account_number' => '4903', 'plaid_account_id' => 'chk_'.uniqid(), 'type' => 'Checking']);
    $check = Check::create(['check_type' => 'Cash', 'date' => '2025-06-07', 'bank_account_id' => $checking->id, 'amount' => 160, 'vendor_id' => $company->id, 'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => 0]);
    $expense = Expense::create(['amount' => 160, 'date' => '2025-06-07', 'vendor_id' => $company->id, 'check_id' => $check->id, 'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => 0]);
    $atm = Transaction::create([
        'transaction_date' => '2025-06-07', 'posted_date' => '2025-06-07', 'amount' => 160, 'bank_account_id' => $checking->id, 'check_id' => $check->id,
        'plaid_merchant_description' => 'Citibank ATM Withdrawal 4842', 'check_number' => '2020202', 'plaid_transaction_id' => uniqid('tx_'),
    ]);

    return compact('check', 'expense', 'atm');
}

it('deletes the check and the expense it created, and unlinks its bank payment', function (): void {
    $f = duplicateCheckFixture();

    $this->artisan('checks:delete', ['ids' => [$f['check']->id, 999999]])
        ->expectsOutputToContain('Deleted 1 of 2.')
        ->assertSuccessful();

    expect(Check::withoutGlobalScopes()->find($f['check']->id)->deleted_at)->not->toBeNull()
        ->and(Expense::withoutGlobalScopes()->withTrashed()->find($f['expense']->id)->deleted_at)->not->toBeNull()
        ->and(Transaction::withoutGlobalScopes()->find($f['atm']->id)->check_id)->toBeNull();
});

it('changes nothing on a dry run', function (): void {
    $f = duplicateCheckFixture();

    $this->artisan('checks:delete', ['ids' => [$f['check']->id], '--dry-run' => true])
        ->expectsOutputToContain('Would delete 1 of 1.')
        ->assertSuccessful();

    expect(Check::withoutGlobalScopes()->find($f['check']->id)->deleted_at)->toBeNull()
        ->and((int) Transaction::withoutGlobalScopes()->find($f['atm']->id)->check_id)->toBe($f['check']->id);
});
