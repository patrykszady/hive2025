<?php

use App\Http\Controllers\TransactionController;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\VendorTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/**
 * Regression: PART 2 of add_vendor_to_transactions cleared a vendor whenever the
 * vendor's business name didn't textually overlap the Plaid merchant name
 * (e.g. "chicago parking" vs "ParkChicago"). PART 3 then re-applied the vendor
 * from the matching VendorTransaction alias, so every scheduled run flip-flopped
 * and spammed "Cleared mismatched vendor (no better match found)".
 */
function setupParkingTransaction(string $vendorName = 'chicago parking'): array
{
    $vendor = Vendor::factory()->create([
        'business_name' => $vendorName,
        // Deliberately NOT "Retail" so the PART 2 fuzzy matcher (which only
        // searches Retail vendors) can never re-match it — isolating the guard.
        'business_type' => 'Sub',
    ]);

    $bank = Bank::create([
        'name' => 'Test Bank',
        'vendor_id' => $vendor->id,
        'plaid_ins_id' => 'ins_test',
    ]);

    $bankAccount = BankAccount::create([
        'vendor_id' => $vendor->id,
        'bank_id' => $bank->id,
        'account_number' => '1234',
        'plaid_account_id' => 'acc_test_'.uniqid(),
        'type' => 'checking',
    ]);

    $transaction = Transaction::create([
        'transaction_date' => now()->subDays(2),
        'amount' => 25.00,
        'bank_account_id' => $bankAccount->id,
        'vendor_id' => $vendor->id,
        'plaid_merchant_name' => 'ParkChicago',
        'plaid_merchant_description' => 'PARKCHICAGO',
    ]);

    return ['vendor' => $vendor, 'transaction' => $transaction];
}

it('keeps a vendor assigned by a matching VendorTransaction alias rule', function () {
    ['vendor' => $vendor, 'transaction' => $transaction] = setupParkingTransaction();

    VendorTransaction::create([
        'vendor_id' => $vendor->id,
        'deposit_check' => null,
        'amount_sign' => null,
        'plaid_inst_id' => null,
        'desc' => 'PARKCHICAGO',
        'options' => json_encode('/i'),
    ]);

    // PART 3 re-applies the alias vendor after PART 2 clears it, so the end-state
    // vendor_id is identical with or without the guard. The real regression is the
    // clearing itself (the log spam), so assert that clearing never happens.
    $captured = collect();
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('debug')->andReturnUsing(fn ($message, $context = []) => $captured->push($message));
    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('warning')->andReturnNull();
    Log::shouldReceive('error')->andReturnNull();

    app(TransactionController::class)->add_vendor_to_transactions();

    expect($captured)->not->toContain('Cleared mismatched vendor (no better match found)')
        ->and($transaction->refresh()->vendor_id)->toBe($vendor->id);
});

it('clears a mismatched vendor when no alias rule justifies the assignment', function () {
    ['vendor' => $vendor, 'transaction' => $transaction] = setupParkingTransaction();

    app(TransactionController::class)->add_vendor_to_transactions();

    expect($transaction->refresh()->vendor_id)->toBeNull();
});
