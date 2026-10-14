<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * checks:add-expense accounts for what a check paid beyond its contents:
 * paper check #2529 paid Grzegorz $772.28 twice, recorded as a "Greg - Home"
 * expense on the check (2026-10-14).
 */
function addExpenseFixture(): array
{
    config(['scout.driver' => 'null']);
    $company = Vendor::factory()->create(['business_type' => 'GC', 'business_name' => 'GS Construction']);
    $home = Distribution::create(['vendor_id' => $company->id, 'name' => 'Greg - Home']);
    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $company->id, 'plaid_ins_id' => 'ins_5']);
    $checking = BankAccount::create(['vendor_id' => $company->id, 'bank_id' => $bank->id, 'account_number' => '4903', 'plaid_account_id' => 'chk_'.uniqid(), 'type' => 'Checking']);
    $check = Check::create(['check_type' => 'Check', 'check_number' => '2529', 'date' => '2025-11-26', 'bank_account_id' => $checking->id, 'amount' => 200, 'vendor_id' => $company->id, 'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => 0]);
    Expense::create(['amount' => 200, 'date' => '2025-11-26', 'vendor_id' => $company->id, 'check_id' => $check->id, 'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => 0]);
    $check->recalculateAmount();
    Transaction::create(['transaction_date' => '2025-11-28', 'posted_date' => '2025-11-28', 'amount' => 972.28, 'bank_account_id' => $checking->id, 'check_id' => $check->id, 'plaid_merchant_description' => 'CHECK 2529', 'check_number' => '2529', 'plaid_transaction_id' => uniqid('tx_')]);

    return compact('company', 'home', 'check');
}

it('adds the expense under the distribution and the check then matches the bank', function (): void {
    $f = addExpenseFixture();

    $this->artisan('checks:add-expense', ['check' => $f['check']->id, 'amount' => '772.28', '--distribution' => $f['home']->id, '--note' => 'Paid twice'])
        ->expectsOutputToContain('now totals $972.28')
        ->assertSuccessful();

    $expense = Expense::withoutGlobalScopes()->where('check_id', $f['check']->id)->where('distribution_id', $f['home']->id)->first();
    expect((float) $expense->amount)->toBe(772.28)
        ->and((int) $expense->vendor_id)->toBe($f['company']->id)
        ->and($expense->date->toDateString())->toBe('2025-11-26')
        ->and($expense->note)->toBe('Paid twice')
        ->and((float) Check::withoutGlobalScopes()->find($f['check']->id)->amount)->toBe(972.28);
});

it('needs a distribution or a job, and changes nothing on a dry run', function (): void {
    $f = addExpenseFixture();

    $this->artisan('checks:add-expense', ['check' => $f['check']->id, 'amount' => '772.28'])->assertFailed();
    $this->artisan('checks:add-expense', ['check' => $f['check']->id, 'amount' => '772.28', '--distribution' => $f['home']->id, '--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(Expense::withoutGlobalScopes()->where('check_id', $f['check']->id)->count())->toBe(1)
        ->and((float) Check::withoutGlobalScopes()->find($f['check']->id)->amount)->toBe(200.0);
});
