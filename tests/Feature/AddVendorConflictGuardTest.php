<?php

use App\Http\Controllers\TransactionController;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regression: PART 1 of add_vendor_to_transactions fell back to plaid_merchant_name
 * when the description matched no retail vendor, then assigned that vendor even though
 * the description clearly named a DIFFERENT merchant. Plaid reported merchant_name
 * "GitHub" on a transaction whose description was "NORTHBROOK VLG MISC", so the txn was
 * tagged Github and the ANY-amount Github bulk match swept it into a Github expense.
 */
function setupConflictVendors(): array
{
    $github = Vendor::factory()->create([
        'business_name' => 'Github',
        'business_type' => 'Retail',
    ]);

    $northbrook = Vendor::factory()->create([
        'business_name' => 'Village Of Northbrook',
        'business_type' => 'Retail',
    ]);

    $bank = Bank::create([
        'name' => 'Test Bank',
        'vendor_id' => $github->id,
        'plaid_ins_id' => 'ins_conflict',
    ]);

    $bankAccount = BankAccount::create([
        'vendor_id' => $github->id,
        'bank_id' => $bank->id,
        'account_number' => '4903',
        'plaid_account_id' => 'acc_conflict_'.uniqid(),
        'type' => 'checking',
    ]);

    return ['github' => $github, 'northbrook' => $northbrook, 'bankAccount' => $bankAccount];
}

it('does not tag a transaction whose description names a different vendor than plaid_merchant_name', function () {
    ['github' => $github, 'bankAccount' => $bankAccount] = setupConflictVendors();

    $transaction = Transaction::create([
        'transaction_date' => now()->subDays(2),
        'amount' => 100.00,
        'bank_account_id' => $bankAccount->id,
        'vendor_id' => null,
        'plaid_merchant_name' => 'GitHub',
        'plaid_merchant_description' => 'DEBIT PURCHASE Jun 23 4849 NORTHBROOK VLG MISC 26175',
    ]);

    app(TransactionController::class)->add_vendor_to_transactions();

    expect($transaction->refresh()->vendor_id)->toBeNull();
});

it('still tags a transaction whose description is consistent with plaid_merchant_name', function () {
    ['github' => $github, 'bankAccount' => $bankAccount] = setupConflictVendors();

    $transaction = Transaction::create([
        'transaction_date' => now()->subDays(2),
        'amount' => 100.00,
        'bank_account_id' => $bankAccount->id,
        'vendor_id' => null,
        'plaid_merchant_name' => 'GitHub',
        'plaid_merchant_description' => 'DEBIT PURCHASE Jun 23 4849 GITHUB, INC. 26175',
    ]);

    app(TransactionController::class)->add_vendor_to_transactions();

    expect($transaction->refresh()->vendor_id)->toBe($github->id);
});
