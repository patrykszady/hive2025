<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function setupConflictFixture(): array
{
    $ownerVendor = Vendor::factory()->create([
        'business_name' => 'Gs Construction',
        'business_type' => 'Retail',
    ]);

    $github = Vendor::factory()->create([
        'business_name' => 'Github',
        'business_type' => 'Retail',
    ]);

    $bank = Bank::create([
        'name' => 'Test Bank',
        'vendor_id' => $ownerVendor->id,
        'plaid_ins_id' => 'ins_fix_conflict',
    ]);

    $bankAccount = BankAccount::create([
        'vendor_id' => $ownerVendor->id,
        'bank_id' => $bank->id,
        'account_number' => '4903',
        'plaid_account_id' => 'acc_fix_conflict_'.uniqid(),
        'type' => 'checking',
    ]);

    $expense = Expense::create([
        'amount' => 100.00,
        'date' => now()->toDateString(),
        'project_id' => null,
        'distribution_id' => 1,
        'vendor_id' => $github->id,
        'check_id' => null,
        'paid_by' => null,
        'belongs_to_vendor_id' => $ownerVendor->id,
        'created_by_user_id' => 0,
    ]);

    $transaction = Transaction::create([
        'transaction_date' => now()->toDateString(),
        'amount' => 100.00,
        'bank_account_id' => $bankAccount->id,
        'vendor_id' => $github->id,
        'expense_id' => $expense->id,
        'plaid_merchant_name' => 'GitHub',
        'plaid_merchant_description' => 'DEBIT PURCHASE Jun 23 4849 NORTHBROOK VLG MISC 26175',
    ]);

    DB::table('expense_transaction')->insert([
        'expense_id' => $expense->id,
        'transaction_id' => $transaction->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return compact('transaction', 'expense');
}

it('shows the change plan in dry run mode', function () {
    ['transaction' => $transaction, 'expense' => $expense] = setupConflictFixture();

    $this->artisan('transactions:fix-name-desc-conflict', [
        '--transaction-id' => (string) $transaction->id,
        '--expense-id' => (string) $expense->id,
        '--dry-run' => true,
    ])->assertSuccessful();

    expect($transaction->refresh()->vendor_id)->not->toBeNull()
        ->and($transaction->expense_id)->toBe($expense->id)
        ->and(DB::table('expense_transaction')
            ->where('transaction_id', $transaction->id)
            ->where('expense_id', $expense->id)
            ->exists())->toBeTrue();
});

it('clears vendor and expense link and removes pivot row', function () {
    ['transaction' => $transaction, 'expense' => $expense] = setupConflictFixture();

    $this->artisan('transactions:fix-name-desc-conflict', [
        '--transaction-id' => (string) $transaction->id,
        '--expense-id' => (string) $expense->id,
    ])->assertSuccessful();

    expect($transaction->refresh()->vendor_id)->toBeNull()
        ->and($transaction->expense_id)->toBeNull()
        ->and(DB::table('expense_transaction')
            ->where('transaction_id', $transaction->id)
            ->where('expense_id', $expense->id)
            ->exists())->toBeFalse();
});

it('does nothing when expected expense does not match current link', function () {
    ['transaction' => $transaction, 'expense' => $expense] = setupConflictFixture();

    $this->artisan('transactions:fix-name-desc-conflict', [
        '--transaction-id' => (string) $transaction->id,
        '--expense-id' => (string) ($expense->id + 999),
    ])->assertSuccessful();

    expect($transaction->refresh()->vendor_id)->not->toBeNull()
        ->and($transaction->expense_id)->toBe($expense->id)
        ->and(DB::table('expense_transaction')
            ->where('transaction_id', $transaction->id)
            ->where('expense_id', $expense->id)
            ->exists())->toBeTrue();
});
