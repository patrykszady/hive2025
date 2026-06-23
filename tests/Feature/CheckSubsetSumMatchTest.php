<?php

use App\Http\Controllers\TransactionController;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeTransferCheckBankAccount(): BankAccount
{
    $vendor = Vendor::factory()->create([
        'business_name' => 'Owner LLC',
    ]);

    $bank = Bank::create([
        'name' => 'Citibank',
        'vendor_id' => $vendor->id,
        'plaid_ins_id' => 'ins_5',
    ]);

    return BankAccount::create([
        'vendor_id' => $vendor->id,
        'bank_id' => $bank->id,
        'account_number' => '4903',
        'plaid_account_id' => 'acc_'.uniqid(),
        'type' => 'checking',
    ]);
}

function makeCheckUser(int $vendorId): User
{
    return User::query()->create([
        'first_name' => 'Check',
        'last_name' => 'Owner',
        'email' => 'check-owner-'.uniqid().'@example.com',
        'cell_phone' => '5551234567',
        'password' => bcrypt('password'),
        'primary_vendor_id' => $vendorId,
    ]);
}

it('prefers a multi-transaction group over a single transaction equal to the check amount', function () {
    $bankAccount = makeTransferCheckBankAccount();
    $user = makeCheckUser($bankAccount->vendor_id);

    $check = Check::create([
        'check_type' => 'Transfer',
        'check_number' => '3825',
        'date' => '2026-05-15',
        'amount' => 200.00,
        'bank_account_id' => $bankAccount->id,
        'belongs_to_vendor_id' => $bankAccount->vendor_id,
        'user_id' => $user->id,
        'created_by_user_id' => $user->id,
    ]);

    // Correct match: two $100 transfers two days before the check.
    $pairA = Transaction::create([
        'transaction_date' => '2026-05-13',
        'amount' => 100.00,
        'bank_account_id' => $bankAccount->id,
        'check_number' => '1010101',
        'plaid_merchant_description' => 'Venmo',
    ]);
    $pairB = Transaction::create([
        'transaction_date' => '2026-05-13',
        'amount' => 100.00,
        'bank_account_id' => $bankAccount->id,
        'check_number' => '1010101',
        'plaid_merchant_description' => 'Venmo',
    ]);

    // Decoy: a single $200 transfer one day after the check (date-closer, but wrong).
    $single = Transaction::create([
        'transaction_date' => '2026-05-16',
        'amount' => 200.00,
        'bank_account_id' => $bankAccount->id,
        'check_number' => '1010101',
        'plaid_merchant_description' => 'Venmo',
    ]);

    app(TransactionController::class)->add_check_id_to_transactions();

    expect($pairA->refresh()->check_id)->toBe($check->id)
        ->and($pairB->refresh()->check_id)->toBe($check->id)
        ->and($single->refresh()->check_id)->toBeNull();
});

it('still matches a single transaction when no multi-transaction group sums to the check', function () {
    $bankAccount = makeTransferCheckBankAccount();
    $user = makeCheckUser($bankAccount->vendor_id);

    $check = Check::create([
        'check_type' => 'Transfer',
        'check_number' => '4100',
        'date' => '2026-05-15',
        'amount' => 200.00,
        'bank_account_id' => $bankAccount->id,
        'belongs_to_vendor_id' => $bankAccount->vendor_id,
        'user_id' => $user->id,
        'created_by_user_id' => $user->id,
    ]);

    $single = Transaction::create([
        'transaction_date' => '2026-05-16',
        'amount' => 200.00,
        'bank_account_id' => $bankAccount->id,
        'check_number' => '1010101',
        'plaid_merchant_description' => 'Venmo',
    ]);

    app(TransactionController::class)->add_check_id_to_transactions();

    expect($single->refresh()->check_id)->toBe($check->id);
});
