<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Services\Transactions\CardPaymentPairer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Credit-card payments pair their two legs again — including the legs the
 * transfer rules of 2026-07-17 stamp (check_number 1010101, no vendor), which
 * the old pairing never looked at: nothing paired after 07/10/2026.
 */
function cardPairFixture(): array
{
    $gs = Vendor::factory()->create(['business_type' => 'GC', 'business_name' => 'GS Construction']);
    $capitalOne = Vendor::factory()->create(['business_type' => 'Retail', 'business_name' => 'CapitalOne Credit']);
    $office = Distribution::create(['vendor_id' => $gs->id, 'name' => 'OFFICE']);
    $citi = Bank::create(['name' => 'Citibank', 'vendor_id' => $gs->id, 'plaid_ins_id' => 'ins_5']);
    $capBank = Bank::create(['name' => 'Capital One', 'vendor_id' => $gs->id, 'plaid_ins_id' => 'ins_128026']);
    $checking = BankAccount::create(['vendor_id' => $gs->id, 'bank_id' => $citi->id, 'account_number' => '4903', 'plaid_account_id' => 'chk_'.uniqid(), 'type' => 'Checking']);
    $card = BankAccount::create(['vendor_id' => $gs->id, 'bank_id' => $capBank->id, 'account_number' => '4060', 'plaid_account_id' => 'cc_'.uniqid(), 'type' => 'Credit Card']);

    $tx = fn (BankAccount $account, string $date, float $amount, string $desc, ?string $checkNumber = null, ?int $vendorId = null, ?int $expenseId = null) => Transaction::withoutSyncingToSearch(fn () => Transaction::create([
        'transaction_date' => $date, 'posted_date' => $date, 'amount' => $amount, 'bank_account_id' => $account->id,
        'plaid_merchant_description' => $desc, 'check_number' => $checkNumber, 'vendor_id' => $vendorId, 'expense_id' => $expenseId, 'plaid_transaction_id' => uniqid('tx_'),
    ]));

    // An earlier pair teaches which vendor this card's payments carry.
    $old = Expense::withoutSyncingToSearch(fn () => Expense::create(['amount' => -3000, 'date' => '2026-07-10', 'vendor_id' => $capitalOne->id, 'distribution_id' => $office->id, 'belongs_to_vendor_id' => $gs->id, 'created_by_user_id' => 0]));
    $tx($card, '2026-07-10', -3000, 'CAPITAL ONE MOBILE PYMT', null, $capitalOne->id, $old->id);

    return compact('gs', 'capitalOne', 'office', 'checking', 'card', 'tx');
}

it('pairs stamped card payments, keeps the old way working, and never pairs a payment to a person', function () {
    $f = cardPairFixture();
    $tx = $f['tx'];

    // Stamped legs (the transfer rules), no vendor.
    $credit400 = $tx($f['card'], '2026-08-20', -400, 'CAPITAL ONE MOBILE PYMT', '1010101');
    $debit400 = $tx($f['checking'], '2026-08-20', 400, 'OTHER DECREASE CAPITAL ONE MOBILE PMT082026CA07BE1B93DFF', '1010101');
    // A stamped card credit whose only same-amount checking row is a Zelle to a person.
    $credit500 = $tx($f['card'], '2026-08-24', -500, 'CAPITAL ONE MOBILE PYMT', '1010101');
    $zelle500 = $tx($f['checking'], '2026-08-24', 500, 'ZELLE DEBIT PAY ID CTIabc ORG ID CTI NAME GRZEGORZ', '1010101');
    // The old, unstamped way: both legs carry the card's vendor.
    $creditOld = $tx($f['card'], '2026-06-05', -300, 'CAPITAL ONE MOBILE PYMT', null, $f['capitalOne']->id);
    $debitOld = $tx($f['checking'], '2026-06-06', 300, 'OTHER DECREASE CAPITAL ONE MOBILE PMT060626CA0', null, $f['capitalOne']->id);
    // A payment out of checking whose card side Hive does not have.
    $orphan = $tx($f['checking'], '2026-08-21', 14000, 'OTHER DECREASE CAPITAL ONE MOBILE PMT082126CA07DD3987BD1', '1010101');

    $pairs = Expense::withoutSyncingToSearch(fn () => Transaction::withoutSyncingToSearch(fn () => app(CardPaymentPairer::class)->run()));
    expect($pairs)->toBe(2);

    foreach ([[$credit400, $debit400], [$creditOld, $debitOld]] as [$credit, $debit]) {
        $credit->refresh(); $debit->refresh();
        $creditExpense = Expense::withoutGlobalScopes()->find($credit->expense_id);
        $debitExpense = Expense::withoutGlobalScopes()->find($debit->expense_id);
        expect($creditExpense)->not->toBeNull()
            ->and($debitExpense)->not->toBeNull()
            ->and((float) $creditExpense->amount)->toBe((float) $credit->amount)
            ->and((float) $debitExpense->amount)->toBe((float) $debit->amount)
            ->and($debitExpense->parent_expense_id)->toBe($creditExpense->id)
            ->and($creditExpense->vendor_id)->toBe($f['capitalOne']->id)
            ->and($debitExpense->vendor_id)->toBe($f['capitalOne']->id)
            ->and($creditExpense->distribution_id)->toBe($f['office']->id)
            ->and($credit->vendor_id)->toBe($f['capitalOne']->id)
            ->and($debit->vendor_id)->toBe($f['capitalOne']->id);
    }

    expect($credit500->fresh()->expense_id)->toBeNull()
        ->and($zelle500->fresh()->expense_id)->toBeNull()
        ->and($zelle500->fresh()->vendor_id)->toBeNull()
        ->and($orphan->fresh()->expense_id)->toBeNull();

    // Running again pairs nothing new.
    expect(Expense::withoutSyncingToSearch(fn () => Transaction::withoutSyncingToSearch(fn () => app(CardPaymentPairer::class)->run())))->toBe(0);
});

it('runs from the scheduled job', function () {
    $f = cardPairFixture();
    $credit = ($f['tx'])($f['card'], '2026-08-20', -400, 'CAPITAL ONE MOBILE PYMT', '1010101');
    $debit = ($f['tx'])($f['checking'], '2026-08-21', 400, 'OTHER DECREASE CAPITAL ONE MOBILE PMT082126', '1010101');

    Expense::withoutSyncingToSearch(fn () => Transaction::withoutSyncingToSearch(fn () => app(\App\Http\Controllers\TransactionController::class)->find_credit_payments_on_debit()));

    expect($credit->fresh()->expense_id)->not->toBeNull()
        ->and($debit->fresh()->expense_id)->not->toBeNull();
});

it('pairs a checking leg that posted the day before the card credit, and reads the payment date the bank prints', function () {
    $f = cardPairFixture();
    $tx = $f['tx'];
    $credit = $tx($f['card'], '2026-09-19', -700, 'CAPITAL ONE MOBILE PYMT', '1010101');
    $debit = $tx($f['checking'], '2026-09-18', 700, 'OTHER DECREASE CAPITAL ONE MOBILE PMT091826CA00F8534', '1010101');
    // Posted 08/14 but paid 08/17 (the description's date): pairs with the 08/20 card credit.
    $credit400 = $tx($f['card'], '2026-08-20', -400, 'CAPITAL ONE MOBILE PYMT', '1010101');
    $debit400 = $tx($f['checking'], '2026-08-14', 400, 'OTHER DECREASE CAPITAL ONE MOBILE PMT081726CA06B45BBDE50', '1010101');
    // Too far before: a month-old payment of the same amount stays apart.
    $credit300 = $tx($f['card'], '2026-10-07', -300, 'CAPITAL ONE MOBILE PYMT', '1010101');
    $stale300 = $tx($f['checking'], '2026-09-06', 300, 'OTHER DECREASE CAPITAL ONE MOBILE PMT090826CA05D06CC', '1010101');

    expect(\App\Services\Transactions\CardPaymentPairer::paymentDate($debit400)->toDateString())->toBe('2026-08-17')
        ->and(\App\Services\Transactions\CardPaymentPairer::paymentDate($stale300)->toDateString())->toBe('2026-09-08');

    expect(Expense::withoutSyncingToSearch(fn () => Transaction::withoutSyncingToSearch(fn () => app(CardPaymentPairer::class)->run())))->toBe(2);
    expect($debit->fresh()->expense_id)->not->toBeNull()
        ->and($credit->fresh()->expense_id)->not->toBeNull()
        ->and($debit400->fresh()->expense_id)->not->toBeNull()
        ->and($credit400->fresh()->expense_id)->not->toBeNull()
        ->and($credit300->fresh()->expense_id)->toBeNull()
        ->and($stale300->fresh()->expense_id)->toBeNull();
});

it('never gives a Transfer check to a payment to one of our own cards', function () {
    $f = cardPairFixture();
    $user = \App\Models\User::query()->create(['first_name' => 'X', 'last_name' => 'Y', 'email' => 'x-'.uniqid().'@example.test', 'cell_phone' => '224'.rand(1000000, 9999999), 'primary_vendor_id' => $f['gs']->id]);
    $morenos = Vendor::factory()->create(['business_type' => 'Sub', 'business_name' => 'Morenos Drywall, Inc']);
    $check = \App\Models\Check::create(['check_type' => 'Transfer', 'check_number' => '3922', 'date' => '2026-10-10', 'amount' => 300, 'bank_account_id' => $f['checking']->id, 'vendor_id' => $morenos->id, 'belongs_to_vendor_id' => $f['gs']->id, 'created_by_user_id' => $user->id]);
    $cardPayment = ($f['tx'])($f['checking'], '2026-10-08', 300, 'OTHER DECREASE CAPITAL ONE MOBILE PMT100826CA09C7B8207F0', '1010101');

    Transaction::withoutSyncingToSearch(fn () => app(\App\Http\Controllers\TransactionController::class)->add_check_id_to_transactions());
    expect($cardPayment->fresh()->check_id)->toBeNull();

    $zelle = ($f['tx'])($f['checking'], '2026-10-10', 300, 'ZELLE DEBIT PAY ID CTIxyz ORG ID JPM NAME MORENOS', '1010101');
    Transaction::withoutSyncingToSearch(fn () => app(\App\Http\Controllers\TransactionController::class)->add_check_id_to_transactions());
    expect($zelle->fresh()->check_id)->toBe($check->id)
        ->and($cardPayment->fresh()->check_id)->toBeNull();
});
