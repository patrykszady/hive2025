<?php

use App\Http\Controllers\TransactionController;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * add_payments_to_transaction() (every ten minutes) links client payments to
 * the deposits that brought them in. PSFCU's feed went quiet for three months
 * and came back on 2026-09-29. Meanwhile Bates' $6,000 (9/9) and Harvey's
 * $4,000 (8/26) had been summed into a $10,000 Citibank ATM deposit on 9/15,
 * and their own PSFCU deposits (9/8 and 8/26) arrived to find them taken. A
 * payment belongs to the deposit of its exact amount nearest its date.
 */
beforeEach(function () {
    $this->travelTo('2026-10-01');
});

/** @return array{0: Vendor, 1: BankAccount, 2: BankAccount} */
function depositMatch_fixture(): array
{
    $company = Vendor::factory()->create(['business_type' => 'GC']);

    $accounts = collect(['Citibank' => '4903', 'PSFCU' => '5134'])->map(function (string $number, string $name) use ($company) {
        $bank = Bank::create(['name' => $name, 'vendor_id' => $company->id, 'plaid_ins_id' => 'ins_'.uniqid()]);

        return BankAccount::create([
            'vendor_id' => $company->id,
            'bank_id' => $bank->id,
            'account_number' => $number,
            'plaid_account_id' => 'acc_'.uniqid(),
            'type' => 'checking',
        ]);
    });

    return [$company, $accounts['Citibank'], $accounts['PSFCU']];
}

function depositMatch_deposit(BankAccount $account, string $date, float $amount): Transaction
{
    return Transaction::forceCreate([
        'transaction_date' => $date,
        'posted_date' => $date,
        'amount' => -$amount,
        'bank_account_id' => $account->id,
        'deposit' => 1,
        'plaid_merchant_description' => 'Deposit by Check MOBILE CHECK DEPOSIT: Remote Deposit',
    ]);
}

function depositMatch_payment(Vendor $company, string $date, float $amount, ?Transaction $linkedTo = null): Payment
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

function depositMatch_run(): void
{
    app(TransactionController::class)->add_payments_to_transaction();
}

it('moves payments out of a combined deposit once their own deposits arrive late', function () {
    [$company, $citi, $psfcu] = depositMatch_fixture();

    $atm = depositMatch_deposit($citi, '2026-09-15', 10000);
    $harvey = depositMatch_payment($company, '2026-08-26', 4000, $atm);
    $bates = depositMatch_payment($company, '2026-09-09', 6000, $atm);

    $harveyDeposit = depositMatch_deposit($psfcu, '2026-08-26', 4000);
    $batesDeposit = depositMatch_deposit($psfcu, '2026-09-08', 6000);
    $laterDeposit = depositMatch_deposit($psfcu, '2026-09-30', 6000);

    depositMatch_run();

    expect($harvey->fresh()->transaction_id)->toBe($harveyDeposit->id)
        ->and($bates->fresh()->transaction_id)->toBe($batesDeposit->id)
        ->and($atm->payments()->count())->toBe(0)
        ->and($laterDeposit->payments()->count())->toBe(0);
});

it('gives a payment to the deposit nearest its date, not the newest one', function () {
    [$company, , $psfcu] = depositMatch_fixture();

    $near = depositMatch_deposit($psfcu, '2026-09-08', 6000);
    $later = depositMatch_deposit($psfcu, '2026-09-30', 6000);
    $payment = depositMatch_payment($company, '2026-09-09', 6000);

    depositMatch_run();

    expect($payment->fresh()->transaction_id)->toBe($near->id)
        ->and($later->payments()->count())->toBe(0);
});

it('does not sum payments into a combined deposit when each has its own', function () {
    [$company, $citi, $psfcu] = depositMatch_fixture();

    $atm = depositMatch_deposit($citi, '2026-09-15', 10000);
    $harveyDeposit = depositMatch_deposit($psfcu, '2026-08-26', 4000);
    $batesDeposit = depositMatch_deposit($psfcu, '2026-09-08', 6000);
    $harvey = depositMatch_payment($company, '2026-08-26', 4000);
    $bates = depositMatch_payment($company, '2026-09-09', 6000);

    depositMatch_run();

    expect($harvey->fresh()->transaction_id)->toBe($harveyDeposit->id)
        ->and($bates->fresh()->transaction_id)->toBe($batesDeposit->id)
        ->and($atm->payments()->count())->toBe(0);
});

it('still sums two checks into the deposit they went in together', function () {
    [$company, $citi] = depositMatch_fixture();

    $atm = depositMatch_deposit($citi, '2026-09-15', 10000);
    $first = depositMatch_payment($company, '2026-09-12', 4000);
    $second = depositMatch_payment($company, '2026-09-14', 6000);

    depositMatch_run();

    expect($first->fresh()->transaction_id)->toBe($atm->id)
        ->and($second->fresh()->transaction_id)->toBe($atm->id);
});

it('keeps a combined deposit whose checks went in that day, despite a same-amount deposit later', function () {
    [$company, $citi, $psfcu] = depositMatch_fixture();

    $atm = depositMatch_deposit($citi, '2026-04-29', 18000);
    $first = depositMatch_payment($company, '2026-04-29', 6000, $atm);
    $second = depositMatch_payment($company, '2026-04-29', 12000, $atm);
    $other = depositMatch_deposit($psfcu, '2026-05-02', 6000);

    depositMatch_run();

    expect($first->fresh()->transaction_id)->toBe($atm->id)
        ->and($second->fresh()->transaction_id)->toBe($atm->id)
        ->and($other->payments()->count())->toBe(0);
});

it('leaves a check split across projects where it is', function () {
    [$company, $citi, $psfcu] = depositMatch_fixture();

    $atm = depositMatch_deposit($citi, '2026-09-15', 10000);
    $parent = depositMatch_payment($company, '2026-09-09', 6000, $atm);
    $split = depositMatch_payment($company, '2026-09-09', 4000, $atm);
    $split->forceFill(['parent_client_payment_id' => $parent->id])->save();
    depositMatch_deposit($psfcu, '2026-09-08', 6000);

    depositMatch_run();

    expect($parent->fresh()->transaction_id)->toBe($atm->id)
        ->and($split->fresh()->transaction_id)->toBe($atm->id);
});
