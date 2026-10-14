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
 * transactions:record-payment records bank payments the way the "Create
 * Expense from Transaction" form does: a check of the transaction's kind, an
 * expense for the payee on it, the transaction linked to the check. The
 * 2025-26 audit listed Zelles, paper checks and ATM withdrawals by the dozen.
 */
function recordPaymentFixture(): array
{
    config(['scout.driver' => 'null']);
    $company = Vendor::factory()->create(['business_type' => 'GC', 'business_name' => 'GS Construction']);
    $sub = Vendor::factory()->create(['business_type' => 'Sub', 'business_name' => 'Morenos Drywall']);
    $office = Distribution::create(['vendor_id' => $company->id, 'name' => 'OFFICE']);
    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $company->id, 'plaid_ins_id' => 'ins_5']);
    $checking = BankAccount::create(['vendor_id' => $company->id, 'bank_id' => $bank->id, 'account_number' => '4903', 'plaid_account_id' => 'chk_'.uniqid(), 'type' => 'Checking']);
    $tx = fn (float $amount, string $description, ?string $checkNumber) => Transaction::create([
        'transaction_date' => '2026-07-17', 'posted_date' => '2026-07-17', 'amount' => $amount, 'bank_account_id' => $checking->id,
        'plaid_merchant_description' => $description, 'check_number' => $checkNumber, 'plaid_transaction_id' => uniqid('tx_'),
    ]);

    return compact('company', 'sub', 'office', 'checking', 'tx');
}

it('records a Zelle as a Transfer check with an expense for the payee, and links the transaction', function (): void {
    $f = recordPaymentFixture();
    $zelle = ($f['tx'])(2050, 'ZELLE DEBIT PAY ID CTIkZ54dxUvj ORG ID JPM NAME MORENOS', '1010101');

    $this->artisan('transactions:record-payment', ['ids' => [$zelle->id], '--vendor' => $f['sub']->id])
        ->expectsOutputToContain('Recorded 1 of 1.')
        ->assertSuccessful();

    $zelle = Transaction::withoutGlobalScopes()->find($zelle->id);
    $check = Check::withoutGlobalScopes()->find($zelle->check_id);
    $expense = Expense::withoutGlobalScopes()->where('check_id', $check->id)->first();
    expect($check->check_type)->toBe('Transfer')
        ->and((int) $check->vendor_id)->toBe($f['sub']->id)
        ->and((int) $check->bank_account_id)->toBe($f['checking']->id)
        ->and((int) $check->belongs_to_vendor_id)->toBe($f['company']->id)
        ->and((float) $check->amount)->toBe(2050.0)
        ->and((int) $expense->vendor_id)->toBe($f['sub']->id)
        ->and($expense->project_id)->toBeNull()
        ->and((float) $expense->amount)->toBe(2050.0)
        ->and($zelle->expense_id)->toBeNull()
        ->and((int) $zelle->vendor_id)->toBe($f['sub']->id);
});

it('records a paper check under its number, and leaves one whose number Hive already has', function (): void {
    $f = recordPaymentFixture();
    $paper = ($f['tx'])(2330, 'CHECK 2610', '2610');
    $known = ($f['tx'])(41.79, 'CHECK 2627', '2627');
    Check::create(['check_type' => 'Check', 'check_number' => '2627', 'date' => '2026-07-10', 'bank_account_id' => $f['checking']->id, 'amount' => 41.79, 'vendor_id' => $f['sub']->id, 'belongs_to_vendor_id' => $f['company']->id, 'created_by_user_id' => 0]);

    $this->artisan('transactions:record-payment', ['ids' => [$paper->id, $known->id], '--vendor' => $f['sub']->id])
        ->expectsOutputToContain('Recorded 1 of 2.')
        ->assertSuccessful();

    $check = Check::withoutGlobalScopes()->find(Transaction::withoutGlobalScopes()->find($paper->id)->check_id);
    expect($check->check_type)->toBe('Check')
        ->and((string) $check->check_number)->toBe('2610')
        ->and(Transaction::withoutGlobalScopes()->find($known->id)->check_id)->toBeNull();
});

it('records an ATM withdrawal as a Cash check to the company, on the OFFICE distribution', function (): void {
    $f = recordPaymentFixture();
    $atm = ($f['tx'])(500, 'Citibank ATM Withdrawal 4849 1828 N ARLNGTN HTS RD', '2020202');

    $this->artisan('transactions:record-payment', ['ids' => [$atm->id], '--vendor' => $f['company']->id, '--distribution' => $f['office']->id])
        ->expectsOutputToContain('Recorded 1 of 1.')
        ->assertSuccessful();

    $atm = Transaction::withoutGlobalScopes()->find($atm->id);
    $expense = Expense::withoutGlobalScopes()->where('check_id', $atm->check_id)->first();
    expect(Check::withoutGlobalScopes()->find($atm->check_id)->check_type)->toBe('Cash')
        ->and((int) $expense->distribution_id)->toBe($f['office']->id)
        ->and($expense->project_id)->toBeNull()
        ->and($atm->vendor_id)->toBeNull();
});

it('skips what is recorded, removed or not a payment, and changes nothing on a dry run', function (): void {
    $f = recordPaymentFixture();
    $purchase = ($f['tx'])(84.12, 'HOME DEPOT #1920', null);
    $removed = ($f['tx'])(300, 'ZELLE DEBIT PAY ID CTIa ORG ID JPM NAME MORENOS', '1010101');
    $removed->delete();
    $zelle = ($f['tx'])(350, 'ZELLE DEBIT PAY ID CTIb ORG ID JPM NAME MORENOS', '1010101');

    $this->artisan('transactions:record-payment', ['ids' => [$purchase->id, $removed->id, $zelle->id], '--vendor' => $f['sub']->id, '--dry-run' => true])
        ->expectsOutputToContain('Would record 1 of 3.')
        ->assertSuccessful();

    expect(Check::withoutGlobalScopes()->count())->toBe(0)
        ->and(Expense::withoutGlobalScopes()->count())->toBe(0)
        ->and(Transaction::withoutGlobalScopes()->find($zelle->id)->check_id)->toBeNull();
});
