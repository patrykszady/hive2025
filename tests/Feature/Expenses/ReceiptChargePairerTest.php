<?php

use App\Http\Controllers\CompanyEmailController;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\ExpenseReceipts;
use App\Models\ExpenseSplits;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\VendorTransaction;
use App\Services\Expenses\ReceiptChargePairer;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/receipt-charge-pairing.php';

uses(RefreshDatabase::class);

/**
 * One purchase became two half-records (production, 2026-11-04): expense
 * 29031, $41.00 under Groot, made from the bank charge and without a
 * receipt; expense 29068, $41.00 with no vendor, made from the scanned
 * ticket printed "Solid Waste Agency of Northern Cook County" and without a
 * charge. ReceiptChargePairer folds the second into the first when it is
 * sure they are one purchase, and never guesses when it is not.
 */
beforeEach(function (): void {
    $this->travelTo('2026-11-12 10:00:00');
    // The models run this check themselves the moment a receipt or a charge
    // lands on an expense (ReceiptSweepAndDuplicateCheckTest). Held back
    // here: these tests build the two halves first, then call the rule.
    pairingAutomaticCheck(false);
    pairingCompany();
});

function pairingRun(bool $dryRun = false): array
{
    return app(ReceiptChargePairer::class)->run($dryRun);
}

function pairingRules(): int
{
    return VendorTransaction::query()->count();
}

it('folds the scanned ticket into the expense of its bank charge', function () {
    $charge = pairingChargeExpense();
    $receipt = pairingReceiptExpense();
    $transaction = Transaction::withoutGlobalScopes()->where('expense_id', $charge->id)->sole();
    $receiptRow = ExpenseReceipts::query()->where('expense_id', $receipt->id)->sole();

    $outcomes = pairingRun();

    expect($outcomes)->toHaveCount(1)
        ->and($outcomes[0])->toMatchArray([
            'status' => ReceiptChargePairer::PAIRED,
            'receipt_expense_id' => $receipt->id,
            'charge_expense_id' => $charge->id,
            'transaction_id' => $transaction->id,
            'amount' => '41.00',
            'receipt_date' => '2026-11-04',
            'charge_date' => '2026-11-04',
            'printed_name' => PAIRING_PRINTED_NAME,
            'vendor' => 'Groot',
            'evidence' => 'card ••4849',
        ])
        ->and(pairingTrashed($receipt))->toBeTrue()
        ->and(pairingTrashed($charge))->toBeFalse()
        ->and(pairingReceiptCount($charge))->toBe(1)
        ->and(pairingReceiptCount($receipt))->toBe(0);

    // The row and its stored file moved as they were; the charge stayed put.
    expect($receiptRow->fresh())->expense_id->toBe($charge->id)->receipt_filename->toBe($receiptRow->receipt_filename)
        ->and($receiptRow->fresh()->trashed())->toBeFalse()
        ->and($transaction->fresh()->expense_id)->toBe($charge->id)
        ->and($charge->fresh())->vendor_id->toBe($this->groot->id)->invoice->toBe('4249778')
        ->and((float) $charge->fresh()->amount)->toBe(41.0);

    // How it was paid follows the receipt, tied to the charge that paid it.
    expect(ExpensePayment::query()->where('expense_id', $charge->id)->sole())
        ->method->toBe(ExpensePayment::CARD)->last_four->toBe('4849')->transaction_id->toBe($transaction->id)
        ->and(ExpensePayment::query()->where('expense_id', $receipt->id)->count())->toBe(0);
});

it('remembers the printed name as a rule of the charge\'s vendor, so the intake\'s own lookup finds it', function () {
    $lookup = fn (string $printed): ?int => app(CompanyEmailController::class)
        ->fuzzyMatchVendor($printed, Vendor::withoutGlobalScopes()->get(), 70.0)?->id;

    pairingChargeExpense();
    pairingReceiptExpense();

    expect($lookup(PAIRING_PRINTED_NAME))->toBeNull();

    $outcomes = pairingRun();

    expect(VendorTransaction::query()->sole())
        ->vendor_id->toBe($this->groot->id)
        ->desc->toBe(PAIRING_PRINTED_NAME)
        ->options->toBe(json_encode('/i'))
        ->deposit_check->toBeNull()
        ->and($outcomes[0]['name'])->toBe('"'.PAIRING_PRINTED_NAME.'" learned as Groot')
        ->and($lookup(PAIRING_PRINTED_NAME))->toBe($this->groot->id)
        // The longer form the station also prints carries the learned name inside it.
        ->and($lookup(PAIRING_PRINTED_NAME.' Glenview Transfer Station'))->toBe($this->groot->id);
});

it('gives the charge\'s expense what only the receipt expense had', function () {
    $office = Distribution::query()->create(['name' => 'DUMP FEES', 'vendor_id' => $this->company->id]);
    $charge = pairingChargeExpense();
    $receipt = pairingReceiptExpense(['note' => 'Service Address: 3154 VIOLET LN', 'reimbursment' => 'Client', 'category_id' => 7]);

    foreach ([20.00, 21.00] as $amount) {
        ExpenseSplits::query()->create([
            'expense_id' => $receipt->id, 'amount' => $amount, 'distribution_id' => $office->id,
            'belongs_to_vendor_id' => $this->company->id, 'created_by_user_id' => 0,
        ]);
    }

    pairingRun();

    expect($charge->fresh())
        ->invoice->toBe('4249778')
        ->note->toBe('Service Address: 3154 VIOLET LN')
        ->category_id->toBe(7)
        ->and($charge->fresh()->getRawOriginal('reimbursment'))->toBe('Client')
        ->and(ExpenseSplits::query()->where('expense_id', $charge->id)->pluck('amount')->map(fn ($amount) => (float) $amount)->all())->toBe([20.0, 21.0])
        ->and(ExpenseSplits::withTrashed()->where('expense_id', $receipt->id)->count())->toBe(0);
});

it('takes the receipt expense\'s project when the charge\'s expense is filed nowhere', function () {
    $charge = pairingChargeExpense();
    pairingReceiptExpense(['project_id' => 364]);

    pairingRun();

    expect($charge->fresh()->project_id)->toBe(364);
});

it('overwrites nothing the charge\'s expense already has', function () {
    $office = Distribution::query()->create(['name' => 'OFFICE', 'vendor_id' => $this->company->id]);
    $charge = pairingChargeExpense(['invoice' => 'OURS-1', 'note' => 'ours', 'distribution_id' => $office->id, 'reimbursment' => 'Client', 'category_id' => 3]);
    $receipt = pairingReceiptExpense(['project_id' => 364, 'note' => 'theirs', 'category_id' => 7]);
    ExpenseSplits::query()->create([
        'expense_id' => $receipt->id, 'amount' => 41.00, 'project_id' => 250,
        'belongs_to_vendor_id' => $this->company->id, 'created_by_user_id' => 0,
    ]);

    pairingRun();

    expect(pairingTrashed($receipt))->toBeTrue()
        ->and($charge->fresh())
        ->invoice->toBe('OURS-1')
        ->note->toBe('ours')
        ->distribution_id->toBe($office->id)
        ->project_id->toBeNull()
        ->category_id->toBe(3)
        ->date->toDateString()->toBe('2026-11-04')
        ->and(ExpenseSplits::query()->where('expense_id', $charge->id)->count())->toBe(0);
});

it('pairs nobody when two charges of that amount on that card could be the receipt\'s', function () {
    $first = pairingChargeExpense();
    $second = pairingChargeExpense();
    $receipt = pairingReceiptExpense();

    $outcomes = pairingRun();

    expect($outcomes)->toHaveCount(1)
        ->and($outcomes[0]['status'])->toBe(ReceiptChargePairer::LEFT)
        ->and($outcomes[0]['reason'])->toContain('2 charges of $41.00 in the window could be this receipt\'s')
        ->and(pairingTrashed($receipt))->toBeFalse()
        ->and(pairingReceiptCount($first) + pairingReceiptCount($second))->toBe(0)
        ->and(pairingRules())->toBe(0);
});

it('pairs nobody when two charges and two receipts of that amount meet: which ticket is whose is a guess', function () {
    $charges = [pairingChargeExpense(), pairingChargeExpense()];
    $receipts = [pairingReceiptExpense(), pairingReceiptExpense(['invoice' => '4249779'])];

    $outcomes = pairingRun();

    expect(collect($outcomes)->pluck('status')->unique()->all())->toBe([ReceiptChargePairer::LEFT])
        ->and($outcomes)->toHaveCount(2)
        ->and(pairingTrashed($receipts[0]))->toBeFalse()
        ->and(pairingTrashed($receipts[1]))->toBeFalse()
        ->and(pairingReceiptCount($charges[0]) + pairingReceiptCount($charges[1]))->toBe(0)
        ->and(pairingRules())->toBe(0);
});

it('pairs nobody when two receipts could belong to the one charge', function (?string $secondCard) {
    $charge = pairingChargeExpense();
    $receipts = [pairingReceiptExpense(), pairingReceiptExpense(['invoice' => '4249779', 'date' => '2026-11-03'], $secondCard)];

    $reasons = collect(pairingRun())->pluck('reason', 'receipt_expense_id');

    expect($reasons[$receipts[0]->id])->toContain('2 receipts of $41.00 could belong to this charge')
        ->and(pairingTrashed($receipts[0]))->toBeFalse()
        ->and(pairingTrashed($receipts[1]))->toBeFalse()
        ->and(pairingReceiptCount($charge))->toBe(0);
})->with([
    'both print the charged card' => ['4849'],
    'the other prints no card: it may still be the charge\'s own' => [null],
]);

it('does not count a receipt that prints another card against the charge', function () {
    $charge = pairingChargeExpense();
    $receipt = pairingReceiptExpense();
    $otherCard = pairingReceiptExpense(['invoice' => '4249779', 'date' => '2026-11-03'], '1854');

    pairingRun();

    expect(pairingTrashed($receipt))->toBeTrue()
        ->and(pairingTrashed($otherCard))->toBeFalse()
        ->and(pairingReceiptCount($charge))->toBe(1);
});

it('counts a charge that has no expense yet, or already has its receipt, against the receipt', function (Closure $rival) {
    $charge = pairingChargeExpense();
    $rival();
    $receipt = pairingReceiptExpense();

    expect(pairingRun()[0]['reason'])->toContain('2 charges of $41.00')
        ->and(pairingTrashed($receipt))->toBeFalse()
        ->and(pairingReceiptCount($charge))->toBe(0);
})->with([
    'a bare charge on the same card' => [fn () => pairingCharge(['transaction_date' => '2026-11-05'])],
    'a charge whose expense already has a receipt' => [fn () => pairingReceipt(pairingChargeExpense(), '4849', 'Groot Industries, Inc')],
    'another vendor\'s charge on the same card' => [fn () => pairingChargeExpense(['vendor_id' => pairingVendor('City Of Evanston')->id], ['plaid_merchant_description' => 'DEBIT PURCHASE Nov 05 4849 CITY OF EVANSTON PARKING'])],
    'a charge the bank states no card for: it may be the receipt\'s own' => [fn () => pairingChargeExpense(['vendor_id' => pairingVendor('Microsoft')->id], ['plaid_merchant_description' => 'MSFT * E0100EGKEJ'])],
]);

it('does not count a charge of that amount the bank says was made with another card', function () {
    $charge = pairingChargeExpense();
    $otherCard = pairingChargeExpense(charge: ['plaid_merchant_description' => 'DEBIT PURCHASE Nov 04 4846 GROOT INDUSTRIES INCO ELK GROVE VIL 26309']);
    $receipt = pairingReceiptExpense();

    expect(pairingRun()[0])->toMatchArray(['status' => ReceiptChargePairer::PAIRED, 'charge_expense_id' => $charge->id])
        ->and(pairingTrashed($receipt))->toBeTrue()
        ->and(pairingReceiptCount($charge))->toBe(1)
        ->and(pairingReceiptCount($otherCard))->toBe(0);
});

/*
 * Capital One: one card account (••4060) with several member cards. Plaid
 * names the member in account_owner (transactions.owner) on most charges;
 * on the rest only the account's own number is known.
 */
it('treats a card account\'s own number as the card charged, but never as proof that another card was not', function () {
    $capitalOne = App\Models\BankAccount::create([
        'vendor_id' => $this->company->id, 'bank_id' => $this->account->bank_id, 'account_number' => '4060',
        'plaid_account_id' => 'acc_pairing_card_'.uniqid(), 'type' => 'credit',
    ]);
    $onAccount = ['bank_account_id' => $capitalOne->id, 'plaid_merchant_description' => 'GROOT INDUSTRIES INCO'];

    // The receipt prints the account's own number: that is the card.
    $charge = pairingChargeExpense(charge: $onAccount);
    $receipt = pairingReceiptExpense(card: '4060');

    expect(pairingRun()[0])->toMatchArray(['status' => ReceiptChargePairer::PAIRED, 'evidence' => 'card ••4060'])
        ->and(pairingTrashed($receipt))->toBeTrue()
        ->and(pairingReceiptCount($charge))->toBe(1);

    // A receipt printing a member card of that account: the charge names no
    // card of its own, so it can neither pair nor be ruled out.
    $memberCharge = pairingChargeExpense(['amount' => 25.00], $onAccount);
    $memberReceipt = pairingReceiptExpense(['amount' => 25.00, 'invoice' => '4250500'], card: '8338');

    $outcomes = pairingRun();

    expect($outcomes[0])->toMatchArray(['status' => ReceiptChargePairer::LEFT, 'reason' => 'the bank names only the card account ••4060, not which of its cards made the charge'])
        ->and(pairingTrashed($memberReceipt))->toBeFalse()
        ->and(pairingReceiptCount($memberCharge))->toBe(0);

    // And it still stands in the way of a look-alike charge that does name the receipt's card.
    pairingChargeExpense(['amount' => 25.00], $onAccount + ['owner' => '8338']);

    expect(pairingRun()[0]['reason'])->toContain('2 charges of $25.00')->toContain('card account ••4060')->toContain('card ••8338')
        ->and(pairingTrashed($memberReceipt))->toBeFalse();
});

it('rules out a charge on which the bank names another card member', function () {
    $capitalOne = App\Models\BankAccount::create([
        'vendor_id' => $this->company->id, 'bank_id' => $this->account->bank_id, 'account_number' => '4060',
        'plaid_account_id' => 'acc_pairing_card_'.uniqid(), 'type' => 'credit',
    ]);
    $onAccount = ['bank_account_id' => $capitalOne->id, 'plaid_merchant_description' => 'GROOT INDUSTRIES INCO'];
    $charge = pairingChargeExpense(charge: $onAccount + ['owner' => '8338']);
    $otherMember = pairingChargeExpense(charge: $onAccount + ['owner' => '9286']);
    $receipt = pairingReceiptExpense(card: '8338');

    expect(pairingRun()[0])->toMatchArray(['status' => ReceiptChargePairer::PAIRED, 'charge_expense_id' => $charge->id, 'evidence' => 'card ••8338'])
        ->and(pairingTrashed($receipt))->toBeTrue()
        ->and(pairingReceiptCount($otherMember))->toBe(0);
});

it('never pairs a receipt that prints a different card than the one charged', function (int|string $receiptVendor) {
    $charge = pairingChargeExpense();
    $receipt = pairingReceiptExpense(['vendor_id' => $receiptVendor === 'groot' ? $this->groot->id : 0], card: '1854');

    $outcomes = pairingRun();

    expect($outcomes[0]['status'])->toBe(ReceiptChargePairer::LEFT)
        ->and($outcomes[0]['reason'])->toContain('the receipt prints card ••1854')->toContain('was made with ••4849')
        ->and(pairingTrashed($receipt))->toBeFalse()
        ->and(pairingReceiptCount($charge))->toBe(0);
})->with([
    'a receipt with no vendor' => [0],
    'even a receipt already filed under the charge\'s vendor' => ['groot'],
]);

it('never pairs a receipt that prints no card number, whoever it is filed under', function (string $filedUnder) {
    $charge = pairingChargeExpense();
    $receipt = pairingReceiptExpense(['vendor_id' => $filedUnder === 'groot' ? $this->groot->id : 0], card: null);

    $outcomes = pairingRun();

    expect($outcomes)->toHaveCount(1)
        ->and($outcomes[0])->toMatchArray(['status' => ReceiptChargePairer::LEFT, 'evidence' => null, 'reason' => 'the receipt prints no card number'])
        ->and(pairingTrashed($receipt))->toBeFalse()
        ->and(pairingReceiptCount($charge))->toBe(0)
        ->and(pairingRules())->toBe(0);
})->with([
    'a receipt with no vendor' => ['nobody'],
    'a receipt already filed under the charge\'s vendor: the vendor proves nothing' => ['groot'],
]);

it('never pairs a charge whose card the bank does not state, whoever the receipt is filed under', function (string $filedUnder) {
    $charge = pairingChargeExpense(charge: ['plaid_merchant_description' => 'GROOT INDUSTRIES INCO']);
    $receipt = pairingReceiptExpense(['vendor_id' => $filedUnder === 'groot' ? $this->groot->id : 0]);

    $outcomes = pairingRun();

    expect($outcomes)->toHaveCount(1)
        ->and($outcomes[0])->toMatchArray(['status' => ReceiptChargePairer::LEFT, 'evidence' => null, 'reason' => 'the bank does not say which card made the charge'])
        ->and(pairingTrashed($receipt))->toBeFalse()
        ->and(pairingReceiptCount($charge))->toBe(0)
        ->and(pairingRules())->toBe(0);
})->with([
    'a receipt with no vendor' => ['nobody'],
    'a receipt already filed under the charge\'s vendor: the vendor proves nothing' => ['groot'],
]);

it('pairs a receipt filed under the charge\'s vendor on its card too', function () {
    $charge = pairingChargeExpense();
    $receipt = pairingReceiptExpense(['vendor_id' => $this->groot->id]);

    expect(pairingRun()[0])->toMatchArray(['status' => ReceiptChargePairer::PAIRED, 'evidence' => 'card ••4849'])
        ->and(pairingTrashed($receipt))->toBeTrue()
        ->and(pairingReceiptCount($charge))->toBe(1);
});

it('leaves a receipt filed under another vendor alone, same card or not', function (?string $card, bool $listed) {
    $charge = pairingChargeExpense();
    $receipt = pairingReceiptExpense(['vendor_id' => pairingVendor('Circle K Gas Station')->id], $card, 'Circle K');

    $outcomes = pairingRun();

    expect(pairingTrashed($receipt))->toBeFalse()
        ->and(pairingReceiptCount($charge))->toBe(0)
        ->and($receipt->fresh()->vendor_id)->not->toBe($this->groot->id)
        ->and(count($outcomes))->toBe($listed ? 1 : 0);

    if ($listed) {
        expect($outcomes[0]['reason'])->toContain('the receipt is filed under Circle K Gas Station and the charge under Groot');
    }
})->with([
    'the same card: listed for a person' => ['4849', true],
    'no card: not even a near miss' => [null, false],
]);

it('leaves a receipt alone when it was not paid by one card charge for the whole amount', function (array $methods) {
    $charge = pairingChargeExpense();
    $receipt = pairingReceiptExpense(items: ['payment_methods' => $methods]);

    $outcomes = pairingRun();

    expect($outcomes[0]['reason'])->toContain('not paid by one card charge for the whole amount')
        ->and(pairingTrashed($receipt))->toBeFalse()
        ->and(pairingReceiptCount($charge))->toBe(0);
})->with([
    'cash' => [[['type' => 'Cash', 'amount' => 41.00]]],
    'a card and a gift card' => [[['type' => 'CreditCard', 'amount' => 30.00, 'last_four' => '4849'], ['type' => 'GiftCard', 'amount' => 11.00]]],
]);

it('never touches an expense that already has both a charge and a receipt', function () {
    $complete = pairingChargeExpense();
    $itsReceipt = pairingReceipt($complete, '4849', 'Groot Industries, Inc');
    $receipt = pairingReceiptExpense();

    $outcomes = pairingRun();

    expect($outcomes[0]['status'])->toBe(ReceiptChargePairer::LEFT)
        ->and($outcomes[0]['reason'])->toContain('already has a receipt')
        ->and(pairingTrashed($receipt))->toBeFalse()
        ->and(pairingReceiptCount($complete))->toBe(1)
        ->and($itsReceipt->fresh()->expense_id)->toBe($complete->id)
        ->and(pairingRules())->toBe(0);
});

it('never merges two expenses that each have their own bank charge', function () {
    $charge = pairingChargeExpense();
    $paidReceipt = pairingReceiptExpense(['vendor_id' => $this->groot->id]);
    pairingCharge(['expense_id' => $paidReceipt->id]);

    expect(pairingRun())->toBe([])
        ->and(app(ReceiptChargePairer::class)->pairExpense($paidReceipt))->toBeNull()
        ->and(app(ReceiptChargePairer::class)->pairExpense($charge))->toBeNull()
        ->and(pairingTrashed($paidReceipt))->toBeFalse()
        ->and(pairingTrashed($charge))->toBeFalse()
        ->and(pairingReceiptCount($paidReceipt))->toBe(1);
});

it('leaves alone a receipt expense that something else already pays', function (array $paidHow) {
    $charge = pairingChargeExpense();
    $receipt = pairingReceiptExpense($paidHow);

    expect(pairingRun())->toBe([])
        ->and(pairingTrashed($receipt))->toBeFalse()
        ->and(pairingReceiptCount($charge))->toBe(0);
})->with([
    'an employee paid it' => [['paid_by' => 3]],
    'a sub paid it' => [['paid_by_vendor_id' => 44]],
    'a check pays it' => [['check_id' => 12]],
    'it is a client\'s material order' => [['belongs_to_client_id' => 9]],
]);

it('leaves alone a charge expense that is more than one plain charge', function (Closure $spoil, string $why) {
    $charge = pairingChargeExpense();
    $spoil($charge);
    $receipt = pairingReceiptExpense();

    $reasons = collect(pairingRun())->pluck('reason')->implode(' | ');

    expect($reasons)->toContain($why)
        ->and(pairingTrashed($receipt))->toBeFalse()
        ->and(pairingReceiptCount($charge))->toBe(0);
})->with([
    'a second charge on it' => [fn (Expense $charge) => pairingCharge(['expense_id' => $charge->id, 'amount' => 5.00, 'plaid_merchant_description' => 'GROOT SERVICEFEE']), 'has more than one bank charge'],
    'an amount that is not its charge\'s' => [fn (Expense $charge) => $charge->update(['amount' => 82.00]), 'is for a different amount than its charge'],
    'no vendor' => [function (Expense $charge): void {
        Transaction::withoutGlobalScopes()->where('expense_id', $charge->id)->update(['vendor_id' => null]);
        $charge->update(['vendor_id' => 0]);
    }, 'has no vendor'],
]);

it('keeps companies apart', function () {
    $other = Vendor::factory()->create(['business_name' => 'Another Builder', 'business_type' => 'Sub']);
    $charge = pairingChargeExpense();
    $receipt = pairingReceiptExpense(['belongs_to_vendor_id' => $other->id]);

    expect(pairingRun())->toBe([])
        ->and(pairingTrashed($receipt))->toBeFalse()
        ->and(pairingReceiptCount($charge))->toBe(0);
});

it('needs the same amount, to the cent', function () {
    $charge = pairingChargeExpense();
    $receipt = pairingReceiptExpense(['amount' => 41.01]);

    expect(pairingRun())->toBe([])
        ->and(pairingTrashed($receipt))->toBeFalse()
        ->and(pairingReceiptCount($charge))->toBe(0);
});

it('pairs only a charge made from one day before to four days after the receipt', function (string $chargeDate, bool $pairs) {
    $charge = pairingChargeExpense(['date' => $chargeDate]);
    $receipt = pairingReceiptExpense();

    pairingRun();

    expect(pairingTrashed($receipt))->toBe($pairs)
        ->and(pairingReceiptCount($charge))->toBe($pairs ? 1 : 0);
})->with([
    'two days before' => ['2026-11-02', false],
    'the day before' => ['2026-11-03', true],
    'the same day' => ['2026-11-04', true],
    'four days after' => ['2026-11-08', true],
    'five days after' => ['2026-11-09', false],
]);

it('waits for the window to close when the charge is not dated the receipt\'s day', function () {
    $charge = pairingChargeExpense(['date' => '2026-11-11']);
    $receipt = pairingReceiptExpense(['date' => '2026-11-10']);

    $outcomes = pairingRun();

    expect($outcomes[0]['status'])->toBe(ReceiptChargePairer::LEFT)
        ->and($outcomes[0]['reason'])->toContain('waits until 2026-11-15')
        ->and(pairingTrashed($receipt))->toBeFalse();

    $this->travelTo('2026-11-14 23:00:00');
    expect(pairingRun()[0]['status'])->toBe(ReceiptChargePairer::LEFT);

    $this->travelTo('2026-11-15 00:30:00');
    expect(pairingRun()[0]['status'])->toBe(ReceiptChargePairer::PAIRED)
        ->and(pairingTrashed($receipt))->toBeTrue()
        ->and(pairingReceiptCount($charge))->toBe(1);
});

it('does not wait when the charge is dated the receipt\'s own day', function () {
    $charge = pairingChargeExpense(['date' => '2026-11-12']);
    $receipt = pairingReceiptExpense(['date' => '2026-11-12']);

    expect(pairingRun()[0]['status'])->toBe(ReceiptChargePairer::PAIRED)
        ->and(pairingTrashed($receipt))->toBeTrue()
        ->and(pairingReceiptCount($charge))->toBe(1);
});

it('pairs but does not learn a printed name that is only a payment processor, or too short to be a safe rule', function (string $printed, string $note) {
    $charge = pairingChargeExpense();
    $receipt = pairingReceiptExpense(printedName: $printed);

    $outcomes = pairingRun();

    expect($outcomes[0]['status'])->toBe(ReceiptChargePairer::PAIRED)
        ->and($outcomes[0]['name'])->toContain($note)
        ->and(pairingTrashed($receipt))->toBeTrue()
        ->and(pairingReceiptCount($charge))->toBe(1)
        ->and(pairingRules())->toBe(0);
})->with([
    'PayPal' => ['PayPal', 'a payment processor'],
    'PAYPAL *' => ['PAYPAL *', 'a payment processor'],
    'Square' => ['Square', 'a payment processor'],
    'one word' => ['Bothwell', 'too short'],
    'two short words' => ['SW AG', 'too short'],
]);

it('writes no rule when the printed name is already known as the vendor\'s', function () {
    VendorTransaction::create(['vendor_id' => $this->groot->id, 'desc' => 'solid waste agency of northern cook county', 'options' => json_encode('/i')]);
    $charge = pairingChargeExpense();
    $receipt = pairingReceiptExpense();

    $outcomes = pairingRun();

    expect($outcomes[0]['status'])->toBe(ReceiptChargePairer::PAIRED)
        ->and($outcomes[0]['name'])->toContain('already known as Groot')
        ->and(pairingTrashed($receipt))->toBeTrue()
        ->and(pairingReceiptCount($charge))->toBe(1)
        ->and(pairingRules())->toBe(1);
});

it('leaves a rule that gives the printed name to a different vendor as it is, and does not pair against it', function (Closure $claim, string $why) {
    $swancc = pairingVendor('Lakeshore Recycling');
    $claim($swancc);
    $rules = VendorTransaction::query()->get()->toArray();
    $charge = pairingChargeExpense();
    $receipt = pairingReceiptExpense();

    $outcomes = pairingRun();

    expect($outcomes[0]['status'])->toBe(ReceiptChargePairer::LEFT)
        ->and($outcomes[0]['reason'])->toContain($why)
        ->and(pairingTrashed($receipt))->toBeFalse()
        ->and(pairingReceiptCount($charge))->toBe(0)
        ->and(VendorTransaction::query()->get()->toArray())->toBe($rules);
})->with([
    'a rule with the same text' => [
        fn (Vendor $other) => VendorTransaction::create(['vendor_id' => $other->id, 'desc' => PAIRING_PRINTED_NAME, 'options' => json_encode('/i')]),
        'is already match rule #',
    ],
    'a rule the intake\'s lookup resolves it through' => [
        fn (Vendor $other) => VendorTransaction::create(['vendor_id' => $other->id, 'desc' => 'Solid Waste Agency', 'options' => json_encode('/i')]),
        'is on file as Lakeshore Recycling, not Groot',
    ],
    'a non-vendor rule with the same text' => [
        fn () => VendorTransaction::create(['vendor_id' => null, 'deposit_check' => 3, 'desc' => PAIRING_PRINTED_NAME, 'options' => json_encode('/i')]),
        'of a non-vendor code',
    ],
]);

it('escapes what a printed name would mean as a pattern, so the rule matches the name as printed', function () {
    $printed = 'A+ Disposal (North) Co.';
    pairingChargeExpense();
    pairingReceiptExpense(printedName: $printed);

    pairingRun();

    $lookup = fn (string $name): ?int => app(CompanyEmailController::class)->fuzzyMatchVendor($name, Vendor::withoutGlobalScopes()->get(), 70.0)?->id;

    expect(VendorTransaction::query()->sole()->desc)->toBe('A\+ Disposal \(North\) Co.')
        ->and($lookup($printed))->toBe($this->groot->id);
});

it('lists what it would do on a dry run and changes nothing', function () {
    $charge = pairingChargeExpense();
    $receipt = pairingReceiptExpense();

    $outcomes = pairingRun(dryRun: true);

    expect($outcomes[0])->toMatchArray([
        'status' => ReceiptChargePairer::WOULD_PAIR,
        'receipt_expense_id' => $receipt->id,
        'charge_expense_id' => $charge->id,
        'name' => '"'.PAIRING_PRINTED_NAME.'" learned as Groot',
    ])
        ->and(pairingTrashed($receipt))->toBeFalse()
        ->and(pairingReceiptCount($charge))->toBe(0)
        ->and($charge->fresh()->invoice)->toBeNull()
        ->and(pairingRules())->toBe(0);
});

it('finds nothing left to do on a second run', function () {
    pairingChargeExpense();
    pairingReceiptExpense();

    expect(pairingRun())->toHaveCount(1)
        ->and(pairingRun())->toBe([])
        ->and(pairingRun(dryRun: true))->toBe([])
        ->and(pairingRules())->toBe(1)
        ->and(Expense::query()->count())->toBe(1);
});

it('pairs from either half when handed one expense', function (string $half) {
    $charge = pairingChargeExpense();
    $receipt = pairingReceiptExpense();

    $outcome = app(ReceiptChargePairer::class)->pairExpense($half === 'charge' ? $charge : $receipt->id);

    expect($outcome)->toMatchArray(['status' => ReceiptChargePairer::PAIRED, 'receipt_expense_id' => $receipt->id, 'charge_expense_id' => $charge->id])
        ->and(pairingTrashed($receipt))->toBeTrue()
        ->and(pairingReceiptCount($charge))->toBe(1);
})->with(['charge', 'receipt']);

it('has nothing to say about an expense that is neither half', function () {
    $lone = pairingChargeExpense();

    expect(app(ReceiptChargePairer::class)->pairExpense($lone))->toBeNull()
        ->and(app(ReceiptChargePairer::class)->pairExpense(999999))->toBeNull();
});

it('lets the intake\'s lookup see a rule saved after an earlier lookup in the same process', function () {
    $lookup = fn (): ?int => app(CompanyEmailController::class)
        ->fuzzyMatchVendor('Northwest Hauling Depot', Vendor::withoutGlobalScopes()->get(), 70.0)?->id;

    expect($lookup())->toBeNull();

    $rule = VendorTransaction::create(['vendor_id' => $this->groot->id, 'desc' => 'Northwest Hauling Depot', 'options' => json_encode('/i')]);
    expect($lookup())->toBe($this->groot->id);

    $rule->delete();
    expect($lookup())->toBeNull();
});
