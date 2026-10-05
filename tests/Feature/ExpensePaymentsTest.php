<?php

use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\ExpenseReceipts;
use App\Models\Vendor;
use App\Support\ReceiptTenders;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * How each expense was paid (2026-10-02): every tender on its receipt as a
 * payment line — card, store credit, gift card, points, cash. Store credit,
 * gift cards, points and cash never reach the bank, so they shrink what is
 * left to find among bank charges. Formats below are the stores' own, with
 * the card numbers changed.
 */
function tenders(string $text): array
{
    return array_map(fn (array $t) => implode(' ', array_filter([$t['method'], $t['last_four'], (string) $t['amount']], fn ($part) => $part !== null && $part !== '')), ReceiptTenders::fromText($text));
}

it('reads Home Depot card, store credit, gift card and Pro Xtra Dollar tenders', function () {
    $receipt = <<<'TXT'
    SUBTOTAL 425.76
    TOTAL $469.00
    XXXXXXXX0211 STORE CREDIT 159.47
    CARD BALANCE 0.00
    XXXXXXXX0002
    GIFT CARD
    116.04
    CARD BALANCE
    5.82
    XXXXXXXX7629 ProXtraDollar 50.00
    CARD BALANCE 0.00
    XXXXXXXXXXXX1234 VISA
    USD$ 143.49
    AUTH CODE 007252/7631773
    AID A0000000031010 CAPITAL ONE VISA
    US Debit
    2024 PRO XTRA SPEND 05/13:
    $17,892.13
    Take a short survey for a chance TO WIN A $5,000 HOME DEPOT GIFT CARD
    TXT;

    expect(tenders($receipt))->toBe(['store_credit 0211 159.47', 'gift_card 0002 116.04', 'points 7629 50', 'card 1234 143.49']);
});

it('reads a Home Depot refund with its printed signs', function () {
    expect(tenders("SUBTOTAL\n-93.18\nXXXXXXXXXXXX4144 VISA\n-96.69\nXXXXXXXX5996\nSTORE CREDIT\n-5.82\nCARD BALANCE\n5.82"))
        ->toBe(['card 4144 -96.69', 'store_credit 5996 -5.82']);
});

it('takes change out of cash, and leaves debit cash back inside the card charge', function () {
    expect(tenders("TOTAL \$156.87\nCASH 160.00\nCHANGE DUE 3.13"))->toBe(['cash 156.87'])
        ->and(tenders("TOTAL \$24.03\nXXXXXXXXXXXX4846 DEBIT\nUSD$ 74.03\nAUTH CODE 980212\nCHANGE DUE 50.00"))->toBe(['card 4846 74.03']);
});

it('reads Menards tenders in each of its formats', function (string $receipt, array $expected) {
    expect(tenders($receipt))->toBe($expected);
})->with([
    'printed' => ["TOTAL SALE\n193.80\nUS Debit 4139\n193.80\nEFT Debit", ['card 4139 193.8']],
    'refund, split, trailing minus' => ["TOTAL SALE\t75.62-\nVISA 4060\t13.76-\n041144\nKeyed\nMASTERCARD 4849\t61.86-", ['card 4060 -13.76', 'card 4849 -61.86']],
    'online lookup' => ["Total\n\$356.79\nPayment Method(s) Used:\nVisa - 4060\nJob # or Name : 3143\n\$356.79", ['card 4060 356.79']],
    'online, 2019, gift certificates' => ["Payment Method(s) Used:\nMASTERCARD DEBIT\t\$59.07\n- 4846\nGift Certificate\t\$16.68\nGift Certificate\t\$219.16", ['card 4846 59.07', 'gift_card 16.68', 'gift_card 219.16']],
]);

it('reads Floor & Decor tenders, refunds in parentheses', function () {
    expect(tenders("Grand Total\n21.93\nMasterCard\n21.93\nXXXXXXXXXXXX4846\nAuth. #: 606453"))->toBe(['card 4846 21.93'])
        ->and(tenders("Grand Total\n(55.49)\nMasterCard\n(55.49)\nXXXXXXXXXXXX4846"))->toBe(['card 4846 -55.49'])
        ->and(tenders("Grand Total (8.59)\nVisa (8.59)\nXOOOCOOOOOKK 41 44"))->toBe(['card -8.59']);
});

it('reads Amazon charges: a charge with no card is a gift card or points, $0.00 is an authorization', function () {
    $lines = ReceiptTenders::fromAmazonCharges([
        ['transactionDate' => '2026-03-03T08:10:00.907Z', 'transactionId' => 'abc', 'amount' => '14.29', 'paymentInstrumentLast4Digits' => '4849'],
        ['transactionDate' => '2026-03-03T08:10:00.907Z', 'transactionId' => 'def', 'amount' => '5.00', 'paymentInstrumentLast4Digits' => ''],
        ['transactionDate' => '2026-03-02T08:10:00.907Z', 'transactionId' => 'ghi', 'amount' => '0.00', 'paymentInstrumentLast4Digits' => '4849'],
    ]);

    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toMatchArray(['method' => 'card', 'last_four' => '4849', 'amount' => 14.29, 'paid_at' => '2026-03-03', 'source_ref' => 'abc'])
        ->and($lines[1])->toMatchArray(['method' => 'gift_card_or_points', 'last_four' => null, 'amount' => 5.0]);
});

it('normalizes the receipt reader\'s payment types', function () {
    $lines = ReceiptTenders::fromReaderPaymentMethods([
        ['type' => 'Debit', 'last_four' => '4849', 'amount' => 20],
        ['type' => 'StoreCredit', 'last_four' => null, 'amount' => 5.5],
        ['type' => 'Cash', 'last_four' => null, 'amount' => 0],
    ]);

    expect(array_column($lines, 'method'))->toBe(['card', 'store_credit']);
});

/** An expense with one receipt holding this text and these parsed fields. */
function paidExpense(float $amount, string $text = '', array $items = []): Expense
{
    $company = Vendor::factory()->create(['business_name' => 'GS Test Co']);
    $store = Vendor::factory()->create(['business_name' => 'Home Depot', 'business_type' => 'Retail']);
    $expense = Expense::query()->create(['amount' => $amount, 'date' => '2026-09-01', 'vendor_id' => $store->id, 'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => 0]);
    ExpenseReceipts::create(['expense_id' => $expense->id, 'receipt_html' => $text, 'receipt_items' => $items ?: null]);

    return $expense->fresh('receipts');
}

it('keeps the lines only when they add up to the expense, signed like it', function () {
    $refund = ReceiptTenders::forExpense(paidExpense(102.51, "XXXXXXXXXXXX4144 VISA\n-96.69\nXXXXXXXX5996 STORE CREDIT -5.82"));
    $short = ReceiptTenders::forExpense(paidExpense(738.38, "CASH 55.00\nCASH 100.00"));
    $repeated = ReceiptTenders::forExpense(paidExpense(20.00, "XXXXXXXXXXXX1234 VISA\nUSD$ 20.00\nXXXXXXXXXXXX1234 VISA\nUSD$ 20.00"));

    expect($refund['status'])->toBe('matched')
        ->and(array_column($refund['lines'], 'amount'))->toBe([96.69, 5.82])
        ->and($short['status'])->toBe('mismatch')
        ->and($repeated['status'])->toBe('matched')
        ->and($repeated['lines'])->toHaveCount(1);
});

it('prefers Amazon\'s charges over the receipt text', function () {
    $result = ReceiptTenders::forExpense(paidExpense(19.29, 'Order total $19.29', ['charges' => [
        ['transactionDate' => '2026-09-01T10:00:00Z', 'transactionId' => 'x1', 'amount' => '14.29', 'paymentInstrumentLast4Digits' => '4849'],
        ['transactionDate' => '2026-09-01T10:00:00Z', 'transactionId' => 'x2', 'amount' => '5.00', 'paymentInstrumentLast4Digits' => ''],
    ]]));

    expect($result['source'])->toBe('amazon')->and($result['lines'])->toHaveCount(2);
});

it('writes the lines as soon as a receipt is saved, and the backfill leaves them as they are', function () {
    $expense = paidExpense(27.40, "TOTAL \$27.40\nXXXXXXXX3128 STORE CREDIT 0.08\nCARD BALANCE 0.00\nXXXXXXXXXXXX5360 DEBIT\nUSD$ 27.32");

    $lines = ExpensePayment::where('expense_id', $expense->id)->orderBy('id')->get();
    expect($lines->pluck('method')->all())->toBe(['store_credit', 'card'])
        ->and($lines[1]->last_four)->toBe('5360')
        ->and($lines[0]->isOffBank())->toBeTrue();

    $this->artisan('expenses:backfill-payments', ['--expense' => [$expense->id]])->expectsOutputToContain('(dry run)')->assertSuccessful();
    $this->artisan('expenses:backfill-payments', ['--expense' => [$expense->id], '--commit' => true])->assertSuccessful();

    expect(ExpensePayment::where('expense_id', $expense->id)->orderBy('id')->pluck('method')->all())->toBe(['store_credit', 'card']);
});

it('keeps a card line\'s matched charge when the lines are rebuilt, and lets a person\'s lines replace the receipt\'s', function () {
    $expense = paidExpense(20.00, "XXXXXXXXXXXX1111 VISA\nUSD$ 20.00");
    ExpensePayment::where('expense_id', $expense->id)->update(['transaction_id' => 555]);

    $expense->receipts->first()->update(['receipt_html' => "XXXXXXXXXXXX1111 VISA\nUSD$ 20.00\n"]);
    expect(ExpensePayment::where('expense_id', $expense->id)->value('transaction_id'))->toBe(555);

    ExpensePayment::create(['expense_id' => $expense->id, 'method' => 'cash', 'amount' => 20, 'source' => ExpensePayment::SOURCE_MANUAL]);
    $this->artisan('expenses:backfill-payments', ['--expense' => [$expense->id], '--commit' => true])->assertSuccessful();

    expect(ExpensePayment::where('expense_id', $expense->id)->pluck('source')->all())->toBe([ExpensePayment::SOURCE_MANUAL]);
});

it('rebuilds the lines when the amount changes: a Menards rebate total corrected adds up', function () {
    $expense = paidExpense(173.43, "MENARD REBATE NO: 6384629331\n43.24-\nTOTAL\n157.66\nTOTAL SALE\n173.43\nUS Debit 4849\n173.43");
    expect(ExpensePayment::where('expense_id', $expense->id)->count())->toBe(0);

    $expense->update(['amount' => 216.67]);

    expect(ExpensePayment::where('expense_id', $expense->id)->orderBy('id')->get()->map->methodName()->all())->toBe(['Rebate check', 'Card (DEBIT)']);
});

it('lists rebate checks, cash and store credit under the bank charges, each with its note', function () {
    $expense = paidExpense(216.67);
    ExpensePayment::create(['expense_id' => $expense->id, 'method' => 'store_credit', 'amount' => 43.24, 'last_four' => '9331', 'brand' => ReceiptTenders::REBATE_CHECK, 'source' => ExpensePayment::SOURCE_RECEIPT_TEXT]);
    ExpensePayment::create(['expense_id' => $expense->id, 'method' => 'cash', 'amount' => 10, 'source' => ExpensePayment::SOURCE_RECEIPT_TEXT]);

    $payments = $expense->fresh()->payments->filter->isOffBank();

    $this->blade('<x-transactions.list_card :transactions="$transactions" :payments="$payments" />', ['transactions' => collect(), 'payments' => $payments])
        ->assertSee('Rebate check')
        ->assertSee('••9331')
        ->assertSee('Paid without a bank charge · Rebate check ••9331 · from the receipt')
        ->assertSee('Paid without a bank charge · Cash · from the receipt');
});

it('reads Home Depot receipts the reader split into two columns', function () {
    $receipt = "TOTAL\n\$34.05\nXXXXXXXX2798\nCARD BALANCE\nSTORE CREDIT\n\$34.05\n15.25\n0.00\nXXXXXXXX6827\nTA\nCARD BALANCE\nSTORE CREDIT\n18.80\n34.06";
    $points = "XXXXXXXX8453\nCARD BALANCE\tProXtraDollar\n9.99\n0.00\nXXXXXXXX2445\nCARD BALANCE\tTA\nProXtraDollar\n0,00\n25.00\nXXXXXXXXXXXX4849\tTA\nAUTH CODE\tMASTERCARD\nUSD$ 77.46";
    $refund = "TOTAL\nXXXXXXXXXXXX4849 DEBIT\n-\$94.71\nAUTH CODE 002079\nUSD$ -21.96\nXXXXXXXXXXXX4849 MASTERCARD\nINVOICE\n-57.50\n0201666\nTA";

    expect(tenders($receipt))->toBe(['store_credit 2798 15.25', 'store_credit 6827 18.8'])
        ->and(tenders($points))->toBe(['points 8453 9.99', 'points 2445 25', 'card 4849 77.46'])
        ->and(tenders($refund))->toBe(['card 4849 -21.96', 'card 4849 -57.5']);
});

it('reads Menards rebate checks and certificates, and the full total they hide', function () {
    $receipt = "PINE TAPERED SHIMS 12 CT 4334222 3 @1.56 4.68\nMENARD REBATE NO: 6323495338\n214.98-\nRemaining Balance: \$67.73\nTOTAL\n5.53\nTAX LONG GROVE-IL 8%\n0.44\nTOTAL SALE\n5.97\nCERTIFICATE-BARCODED\n5.97\n****** 9769\nRemaining Balance: \$0.00";
    $withCard = "TOTAL SALE\n51.51\nCERTIFICATE-BARCODED\n7.59\n****** 7256\nRemaining Balance: \$0.00\nCAPITAL ONE VISA 4144\n43.92";

    expect(tenders($receipt))->toBe(['store_credit 5338 214.98', 'gift_card 9769 5.97'])
        ->and(ReceiptTenders::menardsRebateTotal($receipt))->toBe(['sale' => 5.97, 'rebates' => 214.98, 'total' => 220.95])
        ->and(tenders($withCard))->toBe(['gift_card 7256 7.59', 'card 4144 43.92'])
        ->and(ReceiptTenders::menardsRebateTotal($withCard))->toBeNull();
});

it('reads the ACE / True Value register, skipping a declined card, and Walgreens', function () {
    expect(tenders("TOTAL: \$\n127.43\nBC AMT:\n\$\n127.43\nBK CARD#:\nXXXXXXXXXXXX4849\nMID :******** 8883\nAUTH:\n288207"))->toBe(['card 4849 127.43'])
        ->and(tenders("BC AMT:\n\$\n17.59\nBK CARD#:\nXXXXXXXXXXXX0286\nAUTH :\nDECLINED\nAMT: \$\n00.00"))->toBe([])
        ->and(tenders("TOTAL\nMASTERCARD ACCT 4844\n289.87\nAUTH CODE\n289.87\nCHANGE\n492729\n.00"))->toBe(['card 4844 289.87']);
});

it('nets every way change is printed out of cash', function () {
    expect(tenders("TOTAL\n\$40.13\nCASH\n41.00\nCHANGE DUE\n0.87"))->toBe(['cash 40.13'])
        ->and(tenders("Total\n\$3.00\nCash\n\$5.00\nChange back (Cash)\n\$2.00"))->toBe(['cash 3']);
});
