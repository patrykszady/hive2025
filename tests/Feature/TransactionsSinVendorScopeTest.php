<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regression: an incoming-wire transaction already linked to a client Payment
 * (payments.transaction_id) still appeared on the Match Vendor screen because
 * the transactionsSinVendor scope only checked vendor/expense/deposit/check.
 */
function makeSinVendorTransaction(): Transaction
{
    $vendor = Vendor::factory()->create([
        'business_name' => 'Gs Construction & Remodeling, Inc',
        'business_type' => 'Sub',
    ]);

    $bank = Bank::create([
        'name' => 'Citibank',
        'vendor_id' => $vendor->id,
        'plaid_ins_id' => 'ins_citi_test',
    ]);

    $bankAccount = BankAccount::create([
        'vendor_id' => $vendor->id,
        'bank_id' => $bank->id,
        'account_number' => '5678',
        'plaid_account_id' => 'acc_test_'.uniqid(),
        'type' => 'checking',
    ]);

    return Transaction::create([
        'transaction_date' => now()->subDay(),
        'amount' => -60000.00,
        'bank_account_id' => $bankAccount->id,
        'vendor_id' => null,
        'plaid_merchant_description' => 'INCOMING WIRE WIRE FROM GAIL M LASIN TRU060126ST DTD,02/11/',
    ]);
}

it('includes an unprocessed transaction with no linked payment', function () {
    $transaction = makeSinVendorTransaction();

    expect(Transaction::transactionsSinVendor()->where('id', $transaction->id)->exists())->toBeTrue();
});

it('excludes a transaction that is already linked to a payment', function () {
    $transaction = makeSinVendorTransaction();

    Payment::create([
        'project_id' => 364,
        'amount' => 60000.00,
        'date' => $transaction->transaction_date,
        'reference' => 'TRU060126ST',
        'transaction_id' => $transaction->id,
        'belongs_to_vendor_id' => $transaction->bank_account->vendor_id,
        'created_by_user_id' => 1,
    ]);

    expect(Transaction::transactionsSinVendor()->where('id', $transaction->id)->exists())->toBeFalse();
});
