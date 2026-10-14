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
 * transactions:link-check links a bank payment to the check it paid when the
 * amounts differ: Grzegorz's $1,035.50 Zelle paid timesheet check #3800 for
 * $1,032.50, and --adjust records the $3 as an OFFICE adjustment expense.
 */
function linkCheckFixture(float $checkAmount, float $paid): array
{
    config(['scout.driver' => 'null']);
    $company = Vendor::factory()->create(['business_type' => 'GC', 'business_name' => 'GS Construction']);
    $payee = Vendor::factory()->create(['business_type' => 'Sub', 'business_name' => 'Payee LLC']);
    $office = Distribution::create(['vendor_id' => $company->id, 'name' => 'OFFICE']);
    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $company->id, 'plaid_ins_id' => 'ins_5']);
    $checking = BankAccount::create(['vendor_id' => $company->id, 'bank_id' => $bank->id, 'account_number' => '4903', 'plaid_account_id' => 'chk_'.uniqid(), 'type' => 'Checking']);
    $check = Check::create(['check_type' => 'Transfer', 'date' => '2026-05-15', 'bank_account_id' => $checking->id, 'amount' => $checkAmount, 'vendor_id' => $payee->id, 'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => 0]);
    Expense::create(['amount' => $checkAmount, 'date' => '2026-05-15', 'vendor_id' => $payee->id, 'check_id' => $check->id, 'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => 0]);
    $zelle = Transaction::create([
        'transaction_date' => '2026-05-15', 'posted_date' => '2026-05-15', 'amount' => $paid, 'bank_account_id' => $checking->id,
        'plaid_merchant_description' => 'ZELLE DEBIT PAY ID CTIjbk43u19u ORG ID CTI NAME GRZEGORZ', 'check_number' => '1010101', 'plaid_transaction_id' => uniqid('tx_'),
    ]);

    return compact('company', 'office', 'check', 'zelle');
}

it('links the payment and records what the bank paid on top as an OFFICE adjustment', function (): void {
    $f = linkCheckFixture(1032.50, 1035.50);

    $this->artisan('transactions:link-check', ['transaction' => $f['zelle']->id, 'check' => $f['check']->id, '--adjust' => true])
        ->expectsOutputToContain('now totals $1,035.50')
        ->assertSuccessful();

    $adjustment = Expense::withoutGlobalScopes()->where('check_id', $f['check']->id)->where('distribution_id', $f['office']->id)->first();
    expect((int) Transaction::withoutGlobalScopes()->find($f['zelle']->id)->check_id)->toBe($f['check']->id)
        ->and((float) $adjustment->amount)->toBe(3.0)
        ->and((int) $adjustment->vendor_id)->toBe($f['company']->id)
        ->and($adjustment->note)->toContain('Adjustment')
        ->and((float) Check::withoutGlobalScopes()->find($f['check']->id)->amount)->toBe(1035.5);
});

it('refuses a payment larger than the check without --adjust, and changes nothing', function (): void {
    $f = linkCheckFixture(1032.50, 1035.50);

    $this->artisan('transactions:link-check', ['transaction' => $f['zelle']->id, 'check' => $f['check']->id])
        ->assertFailed();

    expect(Transaction::withoutGlobalScopes()->find($f['zelle']->id)->check_id)->toBeNull()
        ->and(Expense::withoutGlobalScopes()->where('check_id', $f['check']->id)->count())->toBe(1);
});

it('links a partial payment as it is, without an adjustment', function (): void {
    $f = linkCheckFixture(5525.00, 5000.00);

    $this->artisan('transactions:link-check', ['transaction' => $f['zelle']->id, 'check' => $f['check']->id])
        ->expectsOutputToContain('without a bank payment')
        ->assertSuccessful();

    expect((int) Transaction::withoutGlobalScopes()->find($f['zelle']->id)->check_id)->toBe($f['check']->id)
        ->and(Expense::withoutGlobalScopes()->where('check_id', $f['check']->id)->count())->toBe(1)
        ->and((float) Check::withoutGlobalScopes()->find($f['check']->id)->amount)->toBe(5525.0);
});

it('changes nothing on a dry run', function (): void {
    $f = linkCheckFixture(1032.50, 1035.50);

    $this->artisan('transactions:link-check', ['transaction' => $f['zelle']->id, 'check' => $f['check']->id, '--adjust' => true, '--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(Transaction::withoutGlobalScopes()->find($f['zelle']->id)->check_id)->toBeNull()
        ->and(Expense::withoutGlobalScopes()->where('check_id', $f['check']->id)->count())->toBe(1);
});

it('records a shortfall as a negative OFFICE adjustment when the last payment completes the check', function (): void {
    $f = linkCheckFixture(5525.00, 5000.00);
    $first = Transaction::create([
        'transaction_date' => '2026-08-09', 'posted_date' => '2026-08-09', 'amount' => 500, 'bank_account_id' => $f['zelle']->bank_account_id,
        'plaid_merchant_description' => 'ZELLE DEBIT PAY ID CTIa ORG ID CTI NAME GRZEGORZ', 'check_number' => '1010101', 'plaid_transaction_id' => uniqid('tx_'),
    ]);

    $this->artisan('transactions:link-check', ['transaction' => $first->id, 'check' => $f['check']->id])->assertSuccessful();
    $this->artisan('transactions:link-check', ['transaction' => $f['zelle']->id, 'check' => $f['check']->id, '--adjust' => true])
        ->expectsOutputToContain('now totals $5,500.00')
        ->assertSuccessful();

    $adjustment = Expense::withoutGlobalScopes()->where('check_id', $f['check']->id)->where('distribution_id', $f['office']->id)->first();
    expect((float) $adjustment->amount)->toBe(-25.0)
        ->and($adjustment->note)->toContain('$5,500.00 for this $5,525.00')
        ->and((float) Check::withoutGlobalScopes()->find($f['check']->id)->amount)->toBe(5500.0)
        ->and(Transaction::withoutGlobalScopes()->where('check_id', $f['check']->id)->count())->toBe(2);
});
