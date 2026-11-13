<?php

use App\Http\Controllers\TransactionController;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\ExpenseReceipts;
use App\Models\Transaction;
use App\Models\VendorTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/receipt-charge-pairing.php';

uses(RefreshDatabase::class);

/*
 * Two follow-ups of 2026-11-13 to ReceiptChargePairer.
 *
 * The receipt sweep (TransactionController::add_transaction_to_expenses_sin_vendor,
 * every ten minutes and after a vendor is saved on Match Vendor) gave a
 * vendor-less receipt the nearest unlinked charge of the same amount — any
 * date, any card — and that charge's vendor: the $25 dump ticket of
 * 2026-06-02 (expense 27088) took a Serpapi charge of 2026-05-25 and became
 * "Serpapi" (Patryk: "fix it"). It now links only by the pairing rule.
 *
 * And the duplicate check runs however the second record of a purchase came
 * about, in the step that creates it (Patryk: "this will happen automatically
 * going forward when creating the second expense (check for duplicates) no
 * matter how the expense is created"; "when creating expenses/29068 we
 * should have found expenses/29031 during the process and added the receipt
 * and transaction to 29068"). The expense in hand is the one that stays.
 */
beforeEach(function (): void {
    $this->travelTo('2026-11-12 10:00:00');
    pairingCompany();
});

/** The 2026-06-02 case: a $25 ticket on card 4849, a Serpapi charge a week before, and the ticket's own Groot charge. */
function sweepDumpTicketWorld(): array
{
    $serpapi = pairingVendor('Serpapi');
    $ticket = pairingReceiptExpense(['amount' => 25.00, 'date' => '2026-06-02', 'invoice' => '4194245'], '4849', 'Solid Waste Agency ofNorthern Cook CountyGlenview Transfer Station');
    $serpapiCharge = pairingCharge(['vendor_id' => $serpapi->id, 'amount' => 25.00, 'transaction_date' => '2026-05-25', 'posted_date' => '2026-05-26', 'plaid_merchant_description' => 'DEBIT PURCHASE May 25 4849 SERPAPI, LLC 26146', 'plaid_merchant_name' => 'Serpapi']);

    return compact('serpapi', 'ticket', 'serpapiCharge');
}

it('no longer gives a receipt the nearest charge of its amount from another day and another merchant', function () {
    ['ticket' => $ticket, 'serpapiCharge' => $serpapiCharge] = sweepDumpTicketWorld();

    app(TransactionController::class)->add_transaction_to_expenses_sin_vendor();

    expect($serpapiCharge->fresh()->expense_id)->toBeNull()
        ->and($ticket->fresh()->vendor_id)->toBe(0, 'the Serpapi charge\'s vendor is not the ticket\'s');
});

it('links a lone charge that has no expense yet when the receipt prints its card, and gives the receipt its vendor', function () {
    $receipt = pairingReceiptExpense();
    $charge = pairingCharge();

    app(TransactionController::class)->add_transaction_to_expenses_sin_vendor();

    expect($charge->fresh()->expense_id)->toBe($receipt->id)
        ->and($receipt->fresh()->vendor_id)->toBe($this->groot->id)
        ->and(ExpensePayment::query()->where('expense_id', $receipt->id)->sole()->transaction_id)->toBe($charge->id)
        ->and(VendorTransaction::query()->sole())->vendor_id->toBe($this->groot->id)->desc->toBe(PAIRING_PRINTED_NAME)
        ->and(Expense::withoutGlobalScopes()->whereNull('deleted_at')->count())->toBe(1);
});

it('links no charge without the card printed on the receipt being the card charged, and none when two could be it', function (string $case) {
    $receipt = match ($case) {
        'no card printed' => pairingReceiptExpense([], null),
        'another card printed' => pairingReceiptExpense([], '0616'),
        default => pairingReceiptExpense(),
    };
    $charges = [pairingCharge()];

    if ($case === 'two charges') {
        $charges[] = pairingCharge(['transaction_date' => '2026-11-05', 'plaid_merchant_description' => 'DEBIT PURCHASE Nov 05 4849 GROOT INDUSTRIES INCO ELK GROVE VIL 26310']);
    }

    app(TransactionController::class)->add_transaction_to_expenses_sin_vendor();

    foreach ($charges as $charge) {
        expect($charge->fresh()->expense_id)->toBeNull($case);
    }
    expect($receipt->fresh()->vendor_id)->toBe(0)
        ->and(VendorTransaction::query()->count())->toBe(0);
})->with(['no card printed', 'another card printed', 'two charges']);

it('finds the charge\'s expense the moment a receipt lands on an expense, whoever made it, and keeps the expense in hand', function () {
    $dump = \App\Models\Distribution::query()->create(['name' => 'DUMP FEES', 'vendor_id' => $this->company->id]);
    $chargeExpense = pairingChargeExpense(['distribution_id' => $dump->id, 'note' => 'weekly dump run']);
    $charge = Transaction::withoutGlobalScopes()->where('expense_id', $chargeExpense->id)->sole();

    // Made by hand, the receipt attached after: no intake, no sweep, no Match Vendor.
    $byHand = Expense::query()->create(['amount' => 41.00, 'date' => '2026-11-04', 'vendor_id' => 0, 'invoice' => '4249778', 'belongs_to_vendor_id' => $this->company->id, 'created_by_user_id' => 1]);
    expect(pairingTrashed($chargeExpense))->toBeFalse('nothing to check until it has a receipt');

    pairingReceipt($byHand);

    // In that same step: the expense being made stays, now with the bank
    // charge and what the charge's expense was filed as; that one is gone.
    expect(pairingTrashed($byHand))->toBeFalse()
        ->and(pairingTrashed($chargeExpense))->toBeTrue()
        ->and($charge->fresh()->expense_id)->toBe($byHand->id)
        ->and(pairingReceiptCount($byHand))->toBe(1)
        ->and($byHand->fresh())->vendor_id->toBe($this->groot->id)->distribution_id->toBe($dump->id)->note->toBe('weekly dump run')->invoice->toBe('4249778')->created_by_user_id->toBe(1)
        ->and(ExpensePayment::query()->where('expense_id', $byHand->id)->sole()->transaction_id)->toBe($charge->id)
        ->and(VendorTransaction::query()->sole()->desc)->toBe(PAIRING_PRINTED_NAME)
        ->and(Expense::withoutGlobalScopes()->whereNull('deleted_at')->pluck('id')->all())->toBe([$byHand->id]);
});

it('overwrites nothing the expense in hand already holds when it takes the charge', function () {
    $dump = \App\Models\Distribution::query()->create(['name' => 'DUMP FEES', 'vendor_id' => $this->company->id]);
    $office = \App\Models\Distribution::query()->create(['name' => 'OFFICE', 'vendor_id' => $this->company->id]);
    $chargeExpense = pairingChargeExpense(['distribution_id' => $dump->id, 'note' => 'from the bank']);
    $byHand = Expense::query()->create(['amount' => 41.00, 'date' => '2026-11-04', 'vendor_id' => 0, 'distribution_id' => $office->id, 'note' => 'mine', 'belongs_to_vendor_id' => $this->company->id, 'created_by_user_id' => 1]);

    pairingReceipt($byHand);

    expect(pairingTrashed($chargeExpense))->toBeTrue()
        ->and($byHand->fresh())->distribution_id->toBe($office->id)->note->toBe('mine')->vendor_id->toBe($this->groot->id);
});

it('finds the receipt\'s expense the moment a bank charge is linked to an expense, whichever matcher linked it', function () {
    $receipt = pairingReceiptExpense();
    $chargeExpense = Expense::query()->create(['amount' => 41.00, 'date' => '2026-11-04', 'vendor_id' => $this->groot->id, 'belongs_to_vendor_id' => $this->company->id, 'created_by_user_id' => 0]);
    $charge = pairingCharge();

    $charge->expense_id = $chargeExpense->id;
    $charge->save();

    // Here the charge's expense is the one in hand, so it stays.
    expect(pairingTrashed($receipt))->toBeTrue()
        ->and(pairingTrashed($chargeExpense))->toBeFalse()
        ->and(pairingReceiptCount($chargeExpense))->toBe(1)
        ->and($chargeExpense->fresh()->invoice)->toBe('4249778');
});

it('checks again when an expense\'s amount or date is corrected', function () {
    $chargeExpense = pairingChargeExpense();
    $receipt = pairingReceiptExpense(['date' => '2026-10-04']);
    expect(pairingTrashed($chargeExpense))->toBeFalse('October, as the reader misread the month');

    $receipt->date = '2026-11-04';
    $receipt->save();

    expect(pairingTrashed($chargeExpense))->toBeTrue()
        ->and(pairingTrashed($receipt))->toBeFalse()
        ->and(pairingReceiptCount($receipt))->toBe(1)
        ->and(Transaction::withoutGlobalScopes()->where('expense_id', $receipt->id)->count())->toBe(1);
});

it('leaves both alone, in the same step, when the rule cannot be sure', function (string $case) {
    $first = pairingChargeExpense();
    $second = $case === 'two charges' ? pairingChargeExpense() : null;
    $byHand = Expense::query()->create(['amount' => 41.00, 'date' => '2026-11-04', 'vendor_id' => 0, 'belongs_to_vendor_id' => $this->company->id, 'created_by_user_id' => 1]);

    pairingReceipt($byHand, $case === 'no card on the receipt' ? null : '4849');

    expect(pairingTrashed($first))->toBeFalse()
        ->and(pairingTrashed($byHand))->toBeFalse()
        ->and($byHand->fresh()->vendor_id)->toBe(0)
        ->and(Transaction::withoutGlobalScopes()->where('expense_id', $byHand->id)->count())->toBe(0)
        ->and($second === null || ! pairingTrashed($second))->toBeTrue();
})->with(['two charges', 'no card on the receipt']);

it('takes the wrongly linked charge off the dump ticket and lets the rule file it with its own Groot charge', function () {
    ['serpapi' => $serpapi, 'ticket' => $ticket, 'serpapiCharge' => $serpapiCharge] = sweepDumpTicketWorld();
    // As production holds it: the ticket on the Serpapi charge, filed under Serpapi, and on project 369.
    $ticket->forceFill(['vendor_id' => $serpapi->id, 'project_id' => 369])->saveQuietly();
    $serpapiCharge->forceFill(['expense_id' => $ticket->id])->saveQuietly();
    $grootExpense = pairingChargeExpense(['amount' => 25.00, 'date' => '2026-06-02'], ['plaid_merchant_description' => 'DEBIT PURCHASE Jun 02 4849 GROOT INDUSTRIES INCO ELK GROVE V']);

    $this->artisan('expenses:unlink-receipt-charge', ['expense' => $ticket->id, 'transaction' => $serpapiCharge->id, '--dry-run' => true])
        ->expectsOutputToContain('the expense goes back to no vendor: Serpapi came with the charge')
        ->expectsOutputToContain('Dry run: nothing changed.')
        ->assertSuccessful();
    expect($serpapiCharge->fresh()->expense_id)->toBe($ticket->id);

    $this->artisan('expenses:unlink-receipt-charge', ['expense' => $ticket->id, 'transaction' => $serpapiCharge->id])
        ->expectsOutputToContain('Unlinked transaction #'.$serpapiCharge->id)
        ->expectsOutputToContain('Paired: the receipt prints card ••4849, the card that was charged')
        ->assertSuccessful();

    expect($serpapiCharge->fresh()->expense_id)->toBeNull()
        ->and(pairingTrashed($ticket))->toBeTrue()
        ->and(pairingReceiptCount($grootExpense))->toBe(1)
        ->and($grootExpense->fresh())->vendor_id->toBe($this->groot->id)->project_id->toBe(369)->invoice->toBe('4194245');
});

it('refuses to unlink a charge that is not the expense\'s, or from an expense with no receipt', function () {
    $chargeExpense = pairingChargeExpense();
    $charge = Transaction::withoutGlobalScopes()->where('expense_id', $chargeExpense->id)->sole();
    $other = pairingReceiptExpense(['amount' => 9.99]);

    $this->artisan('expenses:unlink-receipt-charge', ['expense' => $chargeExpense->id, 'transaction' => $charge->id])
        ->expectsOutputToContain('has no receipt')
        ->assertFailed();
    $this->artisan('expenses:unlink-receipt-charge', ['expense' => $other->id, 'transaction' => $charge->id])
        ->expectsOutputToContain('is not linked to expense')
        ->assertFailed();

    expect($charge->fresh()->expense_id)->toBe($chargeExpense->id)
        ->and(ExpenseReceipts::query()->count())->toBe(1);
});
