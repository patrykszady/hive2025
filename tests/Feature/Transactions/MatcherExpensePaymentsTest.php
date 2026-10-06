<?php

use App\Http\Controllers\TransactionController;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\ExpenseReceipts;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase 2 of expense payments (2026-10-06): the bank matcher takes what the
 * receipt says was paid without a bank charge — store credit, gift cards and
 * Menards certificates, rebate checks, points, cash — off what it looks for,
 * and matches each card line on the receipt to a charge of that amount from
 * that card, before any guessing.
 */
function mx_fixture(): array
{
    $company = Vendor::factory()->create(['business_name' => 'GS Test Co', 'business_type' => 'Sub']);
    $company->forceFill(['registration' => ['registered' => true]])->save();
    $store = Vendor::factory()->create(['business_name' => 'Menards', 'business_type' => 'Retail']);
    $citi = Bank::create(['name' => 'Citibank', 'vendor_id' => $company->id, 'plaid_ins_id' => 'ins_mx_'.uniqid()]);
    $capitalOne = Bank::create(['name' => 'Capital One', 'vendor_id' => $company->id, 'plaid_ins_id' => 'ins_mx_'.uniqid()]);

    return [
        'company' => $company,
        'store' => $store,
        'checking' => BankAccount::create(['vendor_id' => $company->id, 'bank_id' => $citi->id, 'account_number' => '4903', 'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'Checking']),
        'card' => BankAccount::create(['vendor_id' => $company->id, 'bank_id' => $capitalOne->id, 'account_number' => '4060', 'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'Credit']),
    ];
}

/** An expense with a receipt saying how it was paid (its payment lines follow on save). */
function mx_expense(array $fx, float $amount, string $receipt): Expense
{
    $expense = Expense::query()->create(['amount' => $amount, 'date' => now()->subDays(3)->toDateString(), 'vendor_id' => $fx['store']->id, 'belongs_to_vendor_id' => $fx['company']->id, 'created_by_user_id' => 0]);
    ExpenseReceipts::create(['expense_id' => $expense->id, 'receipt_html' => $receipt]);

    return $expense;
}

function mx_charge(array $fx, BankAccount $account, float $amount, string $description, ?string $owner = null, int $daysAgo = 3): Transaction
{
    return Transaction::withoutGlobalScopes()->create([
        'bank_account_id' => $account->id, 'vendor_id' => $fx['store']->id, 'amount' => $amount,
        'transaction_date' => now()->subDays($daysAgo)->toDateString(), 'plaid_merchant_description' => $description, 'owner' => $owner,
    ]);
}

function mx_match(): void
{
    app(TransactionController::class)->add_expense_to_transactions();
}

it('matches the card part of a Menards purchase paid partly with a rebate check', function () {
    $fx = mx_fixture();
    $expense = mx_expense($fx, 216.67, "MENARD REBATE NO: 6384629331\n43.24-\nTOTAL\n157.66\nTOTAL SALE\n173.43\nUS Debit 4849\n173.43");
    $charge = mx_charge($fx, $fx['checking'], 173.43, 'DEBIT PIN PURCHASE 4849 MNRD-MOUNT PROS');

    mx_match();

    expect($charge->fresh()->expense_id)->toBe($expense->id)
        ->and(ExpensePayment::where('expense_id', $expense->id)->where('method', 'card')->value('transaction_id'))->toBe($charge->id);
});

it('leaves an expense paid entirely with store credit alone, even next to a same-amount charge', function () {
    $fx = mx_fixture();
    mx_expense($fx, 34.05, "XXXXXXXX2798 STORE CREDIT 15.25\nCARD BALANCE 0.00\nXXXXXXXX6827 STORE CREDIT 18.80\nCARD BALANCE 34.06");
    $coincidence = mx_charge($fx, $fx['checking'], 34.05, 'DEBIT PIN PURCHASE 4849 MNRD-LONG GROVE');

    mx_match();

    expect($coincidence->fresh()->expense_id)->toBeNull();
});

it('matches each card of a split payment to its own charge, not a same-amount charge on another card', function () {
    $fx = mx_fixture();
    $expense = mx_expense($fx, 75.62, "TOTAL SALE\t75.62\nVISA 4060\t13.76\nKeyed\nMASTERCARD 4849\t61.86");
    $wrongCard = mx_charge($fx, $fx['card'], 13.76, 'MENARDS 3131', '0286', 3);
    $visa = mx_charge($fx, $fx['card'], 13.76, 'MENARDS 3131', '4060', 2);
    $debit = mx_charge($fx, $fx['checking'], 61.86, 'DEBIT PIN PURCHASE 4849 MNRD-LONG GROVE');

    mx_match();

    expect($visa->fresh()->expense_id)->toBe($expense->id)
        ->and($debit->fresh()->expense_id)->toBe($expense->id)
        ->and($wrongCard->fresh()->expense_id)->toBeNull();
});

it('never takes a charge on a different card than the receipt names', function () {
    $fx = mx_fixture();
    mx_expense($fx, 50.00, "TOTAL \$50.00\nXXXXXXXXXXXX4849 MASTERCARD\nUSD$ 50.00");
    $otherCard = mx_charge($fx, $fx['checking'], 50.00, 'DEBIT PIN PURCHASE 4842 MNRD-LONG GROVE');

    mx_match();

    expect($otherCard->fresh()->expense_id)->toBeNull();
});

it('finds the rest after store credit entered by hand', function () {
    $fx = mx_fixture();
    $expense = mx_expense($fx, 122.21, 'A receipt with no tenders printed');
    // A person recorded $72.50 of store credit; what is left for the bank is $49.71.
    ExpensePayment::create(['expense_id' => $expense->id, 'method' => 'store_credit', 'amount' => 72.50, 'source' => ExpensePayment::SOURCE_MANUAL]);
    $charge = mx_charge($fx, $fx['checking'], 49.71, 'DEBIT PIN PURCHASE 4849 MNRD-MOUNT PROS');

    mx_match();

    expect($charge->fresh()->expense_id)->toBe($expense->id);
});

it('adds up several charges to what is still unpaid, not to the whole amount', function () {
    $fx = mx_fixture();
    $expense = mx_expense($fx, 100.00, 'An invoice with no tenders printed');
    mx_charge($fx, $fx['checking'], 40.00, 'MENARDS 3131 deposit', null, 5)->update(['expense_id' => $expense->id]);
    $first = mx_charge($fx, $fx['checking'], 25.00, 'MENARDS 3131', null, 3);
    $second = mx_charge($fx, $fx['checking'], 35.00, 'MENARDS 3131', null, 2);

    mx_match();

    expect($first->fresh()->expense_id)->toBe($expense->id)
        ->and($second->fresh()->expense_id)->toBe($expense->id);
});
