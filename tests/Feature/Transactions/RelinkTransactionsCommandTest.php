<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * transactions:relink moves bank charges onto the expenses they paid, or
 * off one: first run 2026-10-02 for charges Plaid had "posted" as other
 * merchants while the real charges sat unlinked.
 */
function rtx_fixture(): array
{
    $company = Vendor::factory()->create(['business_name' => 'GS Test Co']);
    $amazon = Vendor::factory()->create(['business_name' => 'Amazon', 'business_type' => 'Retail']);
    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $company->id, 'plaid_ins_id' => 'ins_rtx_'.uniqid()]);
    $account = BankAccount::create(['vendor_id' => $company->id, 'bank_id' => $bank->id, 'account_number' => '4903', 'plaid_account_id' => 'acc_rtx_'.uniqid(), 'type' => 'checking']);

    return compact('company', 'amazon', 'bank', 'account');
}

function rtx_expense(array $fx, float $amount): Expense
{
    return Expense::query()->create(['amount' => $amount, 'date' => '2026-08-28', 'vendor_id' => $fx['amazon']->id, 'belongs_to_vendor_id' => $fx['company']->id, 'created_by_user_id' => 0]);
}

function rtx_charge(array $fx, float $amount, string $description, ?Expense $expense = null): Transaction
{
    return Transaction::withoutGlobalScopes()->create([
        'bank_account_id' => $fx['account']->id, 'transaction_date' => '2026-08-28', 'amount' => $amount,
        'vendor_id' => $fx['amazon']->id, 'plaid_merchant_description' => $description, 'expense_id' => $expense?->id,
    ]);
}

it('moves the link off the wire fee onto the real charge, clearing the fee\'s vendor', function () {
    $fx = rtx_fixture();
    $expense = rtx_expense($fx, 14.99);
    $fee = rtx_charge($fx, 15.00, 'ACH Electronic Debit INCOMING WIRE FEE', $expense);
    $real = rtx_charge($fx, 14.99, 'DEBIT PURCHASE Aug 28 4849 Prime Video');

    $this->artisan('transactions:relink', ['links' => ["{$fee->id}:none", "{$real->id}:{$expense->id}"], '--clear-vendor' => true])
        ->expectsOutputToContain("expense {$expense->id} → none")
        ->assertSuccessful();

    expect($fee->fresh()->expense_id)->toBeNull()
        ->and($fee->fresh()->vendor_id)->toBeNull()
        ->and($real->fresh()->expense_id)->toBe($expense->id)
        ->and($real->fresh()->vendor_id)->toBe($fx['amazon']->id);
});

it('swaps two charges between two expenses in one go', function () {
    $fx = rtx_fixture();
    $a = rtx_expense($fx, 93.06);
    $b = rtx_expense($fx, 92.98);
    $chargeA = rtx_charge($fx, 92.98, 'HLU*HULUPLUS', $a);
    $chargeB = rtx_charge($fx, 93.06, 'Home Depot', $b);

    $this->artisan('transactions:relink', ['links' => ["{$chargeB->id}:{$a->id}", "{$chargeA->id}:{$b->id}"]])->assertSuccessful();

    expect($chargeB->fresh()->expense_id)->toBe($a->id)
        ->and($chargeA->fresh()->expense_id)->toBe($b->id);
});

it('changes nothing on a dry run, and nothing on a second run', function () {
    $fx = rtx_fixture();
    $expense = rtx_expense($fx, 14.99);
    $real = rtx_charge($fx, 14.99, 'Prime Video');

    $this->artisan('transactions:relink', ['links' => ["{$real->id}:{$expense->id}"], '--dry-run' => true])->expectsOutputToContain('(dry run)')->assertSuccessful();
    expect($real->fresh()->expense_id)->toBeNull();

    $this->artisan('transactions:relink', ['links' => ["{$real->id}:{$expense->id}"]])->assertSuccessful();
    $this->artisan('transactions:relink', ['links' => ["{$real->id}:{$expense->id}"]])->expectsOutputToContain('already on expense')->assertSuccessful();
});

it('writes nothing when any pair is wrong', function (string $why) {
    $fx = rtx_fixture();
    $expense = rtx_expense($fx, 14.99);
    $real = rtx_charge($fx, 14.99, 'Prime Video');

    $bad = match ($why) {
        'missing transaction' => '999999:'.$expense->id,
        'missing expense' => "{$real->id}:999999",
        'another company' => (function () use ($real) {
            $other = rtx_fixture();

            return "{$real->id}:".rtx_expense($other, 14.99)->id;
        })(),
        'malformed' => "{$real->id}-{$expense->id}",
    };

    $this->artisan('transactions:relink', ['links' => ["{$real->id}:{$expense->id}", $bad]])
        ->expectsOutputToContain('Nothing changed.')
        ->assertFailed();

    expect($real->fresh()->expense_id)->toBeNull();
})->with(['missing transaction', 'missing expense', 'another company', 'malformed']);
