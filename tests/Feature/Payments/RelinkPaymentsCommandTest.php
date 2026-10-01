<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * payments:relink moves payments onto the deposits that brought them in:
 * first run for two $4,000 payments swapped between deposits (2025-09), and
 * for Bates' and Harvey's checks summed into a $10,000 ATM deposit while
 * PSFCU's feed was down (2026-09).
 */

/** @return array{0: Vendor, 1: BankAccount} */
function relink_fixture(): array
{
    $company = Vendor::factory()->create(['business_type' => 'GC']);
    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $company->id, 'plaid_ins_id' => 'ins_'.uniqid()]);

    return [$company, BankAccount::create([
        'vendor_id' => $company->id,
        'bank_id' => $bank->id,
        'account_number' => '4903',
        'plaid_account_id' => 'acc_'.uniqid(),
        'type' => 'checking',
    ])];
}

function relink_deposit(BankAccount $account, string $date, float $amount): Transaction
{
    return Transaction::forceCreate([
        'transaction_date' => $date,
        'posted_date' => $date,
        'amount' => -$amount,
        'bank_account_id' => $account->id,
        'deposit' => 1,
        'plaid_merchant_description' => 'ATM DEPOSIT',
    ]);
}

function relink_payment(Vendor $company, string $date, float $amount, ?Transaction $linkedTo): Payment
{
    return Payment::forceCreate([
        'amount' => $amount,
        'date' => $date,
        'reference' => (string) random_int(100, 9999),
        'belongs_to_vendor_id' => $company->id,
        'transaction_id' => $linkedTo?->id,
        'created_by_user_id' => User::query()->value('id'),
    ]);
}

it('swaps two payments back onto their own deposits', function () {
    [$company, $account] = relink_fixture();
    $first = relink_deposit($account, '2025-09-22', 4000);
    $second = relink_deposit($account, '2025-09-26', 4000);
    $haverhill = relink_payment($company, '2025-09-22', 4000, $second);
    $hale = relink_payment($company, '2025-09-26', 4000, $first);

    $this->artisan('payments:relink', ['links' => ["{$haverhill->id}:{$first->id}", "{$hale->id}:{$second->id}"]])
        ->expectsOutputToContain("Payment {$haverhill->id} (\$4,000.00, 2025-09-22")
        ->assertSuccessful();

    expect($haverhill->fresh()->transaction_id)->toBe($first->id)
        ->and($hale->fresh()->transaction_id)->toBe($second->id);
});

it('moves payments out of a combined deposit and reports what it holds', function () {
    [$company, $account] = relink_fixture();
    $atm = relink_deposit($account, '2026-09-15', 10000);
    $harveyDeposit = relink_deposit($account, '2026-08-26', 4000);
    $batesDeposit = relink_deposit($account, '2026-09-08', 6000);
    $harvey = relink_payment($company, '2026-08-26', 4000, $atm);
    $bates = relink_payment($company, '2026-09-09', 6000, $atm);

    $this->artisan('payments:relink', ['links' => ["{$bates->id}:{$batesDeposit->id}", "{$harvey->id}:{$harveyDeposit->id}"]])
        ->expectsOutputToContain("Transaction {$atm->id} (\$10,000.00 on 2026-09-15) now holds \$0.00 in payments.")
        ->assertSuccessful();

    expect($bates->fresh()->transaction_id)->toBe($batesDeposit->id)
        ->and($harvey->fresh()->transaction_id)->toBe($harveyDeposit->id)
        ->and($atm->payments()->count())->toBe(0);
});

it('changes nothing on a dry run', function () {
    [$company, $account] = relink_fixture();
    $wrong = relink_deposit($account, '2026-09-15', 10000);
    $right = relink_deposit($account, '2026-09-08', 6000);
    $payment = relink_payment($company, '2026-09-09', 6000, $wrong);

    $this->artisan('payments:relink', ['links' => ["{$payment->id}:{$right->id}"], '--dry-run' => true])
        ->expectsOutputToContain('(dry run)')
        ->assertSuccessful();

    expect($payment->fresh()->transaction_id)->toBe($wrong->id);
});

it('is harmless to run again', function () {
    [$company, $account] = relink_fixture();
    $deposit = relink_deposit($account, '2026-09-08', 6000);
    $payment = relink_payment($company, '2026-09-09', 6000, $deposit);

    $this->artisan('payments:relink', ['links' => ["{$payment->id}:{$deposit->id}"]])
        ->expectsOutputToContain("already on transaction {$deposit->id}")
        ->assertSuccessful();

    expect($payment->fresh()->transaction_id)->toBe($deposit->id);
});

it('writes nothing when any link is wrong', function (string $why) {
    [$company, $account] = relink_fixture();
    $deposit = relink_deposit($account, '2026-09-08', 6000);
    $first = relink_payment($company, '2026-09-09', 6000, null);

    $bad = match ($why) {
        'missing transaction' => "{$first->id}:999999",
        'money out' => $first->id.':'.Transaction::forceCreate([
            'transaction_date' => '2026-09-08', 'amount' => 6000, 'bank_account_id' => $account->id, 'plaid_merchant_description' => 'CHECK 2672',
        ])->id,
        'another company' => (function () use ($first) {
            [, $theirAccount] = relink_fixture();

            return "{$first->id}:".relink_deposit($theirAccount, '2026-09-08', 6000)->id;
        })(),
        'malformed' => "{$first->id}-{$deposit->id}",
    };

    $this->artisan('payments:relink', ['links' => ["{$first->id}:{$deposit->id}", $bad]])
        ->expectsOutputToContain('Nothing changed.')
        ->assertFailed();

    expect($first->fresh()->transaction_id)->toBeNull();
})->with(['missing transaction', 'money out', 'another company', 'malformed']);
