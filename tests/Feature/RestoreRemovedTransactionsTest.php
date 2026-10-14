<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Plaid (Capital One) removed real charges about two weeks after sending them,
 * July to September 2026, and never sent them again; each kept its expense
 * but lost its bank record. transactions:restore-removed brings named ones back.
 */
function removedChargeMaker(): Closure
{
    config(['scout.driver' => 'null']);
    $company = Vendor::factory()->create(['business_name' => 'GS Construction']);
    $bank = Bank::create(['name' => 'Capital One', 'vendor_id' => $company->id, 'plaid_ins_id' => 'ins_128026']);
    $card = BankAccount::create(['vendor_id' => $company->id, 'bank_id' => $bank->id, 'account_number' => '4060', 'plaid_account_id' => 'cc_'.uniqid(), 'type' => 'Credit Card']);

    return fn (float $amount, string $description) => Transaction::create([
        'transaction_date' => '2026-07-26', 'posted_date' => '2026-07-26', 'amount' => $amount, 'bank_account_id' => $card->id,
        'plaid_merchant_description' => $description, 'plaid_transaction_id' => uniqid('tx_'),
    ]);
}

function isRemoved(Transaction $transaction): bool
{
    return Transaction::withoutGlobalScopes()->find($transaction->id)->deleted_at !== null;
}

it('restores the removed transactions it is given and leaves the rest alone', function (): void {
    $make = removedChargeMaker();
    $interest = $make(554.80, 'INTEREST CHARGE:PURCHASES');
    $purchase = $make(120.21, 'Home Depot');
    $live = $make(51.57, 'Home Depot');
    $interest->delete();
    $purchase->delete();

    $this->artisan('transactions:restore-removed', ['ids' => [$interest->id, $purchase->id, $live->id, 999999]])
        ->expectsOutputToContain('Restored 2 of 4.')
        ->assertSuccessful();

    expect(isRemoved($interest))->toBeFalse()
        ->and(isRemoved($purchase))->toBeFalse()
        ->and(isRemoved($live))->toBeFalse();
});

it('changes nothing on a dry run', function (): void {
    $make = removedChargeMaker();
    $interest = $make(332.80, 'INTEREST CHARGE:PURCHASES');
    $interest->delete();

    $this->artisan('transactions:restore-removed', ['ids' => [$interest->id], '--dry-run' => true])
        ->expectsOutputToContain('Would restore 1 of 1.')
        ->assertSuccessful();

    expect(isRemoved($interest))->toBeTrue();
});
