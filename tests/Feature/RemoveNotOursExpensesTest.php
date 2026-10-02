<?php

use App\Http\Controllers\ExpenseAutoMatchController;
use App\Http\Controllers\ReceiptController;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\Transaction;
use App\Support\NotOursPurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * An Amazon order whose PO says it is not the company's ("Not Greg home",
 * "not patryk home") is deleted once a week has passed with no charge
 * linked; one that shows up on a company card is kept. Such a PO matches no
 * project or distribution, and a removed refund is not re-imported.
 */
beforeEach(function () {
    $this->travelTo('2026-10-02 12:00:00');
    $this->gregHome = Distribution::withoutEvents(fn () => Distribution::forceCreate(['name' => 'Greg - Home', 'vendor_id' => 1, 'user_id' => 2]));
    $this->patrykHome = Distribution::withoutEvents(fn () => Distribution::forceCreate(['name' => 'Patryk - Home', 'vendor_id' => 1, 'user_id' => 1]));
    Distribution::withoutEvents(fn () => Distribution::forceCreate(['name' => 'OFFICE', 'vendor_id' => 1, 'user_id' => 0]));
});

function notOurs_charge(Expense $expense): void
{
    Transaction::withoutEvents(fn () => Transaction::forceCreate([
        'transaction_date' => $expense->date, 'amount' => $expense->amount, 'bank_account_id' => 1,
        'plaid_merchant_description' => 'DEBIT PURCHASE 4849', 'expense_id' => $expense->id,
    ]));
}

/** $charges: Amazon's payment lines; by default the whole order on a personal card. */
function notOurs_expense(string $po, string $createdAt, int $vendorId = 54, float $amount = 12.46, ?string $order = null, ?array $charges = null): Expense
{
    $expense = Expense::withoutEvents(fn () => Expense::forceCreate([
        'amount' => $amount,
        'date' => substr($createdAt, 0, 10),
        'vendor_id' => $vendorId,
        'belongs_to_vendor_id' => 1,
        'created_by_user_id' => 0,
        'invoice' => $order ?? 'ORDER-'.uniqid(),
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]));

    ExpenseReceipts::withoutEvents(fn () => ExpenseReceipts::query()->create([
        'expense_id' => $expense->id,
        'receipt_filename' => "{$expense->id}.pdf",
        'receipt_items' => ['purchase_order' => $po, 'total' => (string) $amount, 'charges' => $charges ?? [['amount' => (string) $amount, 'paymentInstrumentLast4Digits' => '4399']]],
    ]));

    return $expense;
}

it('recognises a "Not …" purchase order and nothing else', function (mixed $po, bool $notOurs) {
    expect(NotOursPurchaseOrder::matches($po))->toBe($notOurs);
})->with([
    ['Not Greg home', true],
    ['not patryk home', true],
    ['Not greghome', true],
    ['NOT Greg hom', true],
    ['Not', true],
    ['notary fees', false],
    ['Nottingham Ave', false],
    ['Greg home', false],
    ['oak park', false],
    ['', false],
    [null, false],
]);

it('removes a "Not" Amazon expense with no charge after a week, receipts and all', function () {
    $old = notOurs_expense('Not Greg home', '2026-09-24 06:00:00');

    $this->artisan('expenses:remove-not-ours')
        ->expectsOutputToContain("Removing expense {$old->id}")
        ->assertSuccessful();

    expect(Expense::withTrashed()->find($old->id)->trashed())->toBeTrue()
        ->and(ExpenseReceipts::query()->where('expense_id', $old->id)->exists())->toBeFalse();
});

it('leaves what is too young, not Amazon, or not a "Not" PO', function () {
    $young = notOurs_expense('Not Greg home', '2026-09-29 06:00:00');
    $homeDepot = notOurs_expense('Not Greg home', '2026-09-01 06:00:00', vendorId: 8);
    $ours = notOurs_expense('Greg home', '2026-09-01 06:00:00');

    $this->artisan('expenses:remove-not-ours')->assertSuccessful();

    foreach ([$young, $homeDepot, $ours] as $kept) {
        expect(Expense::query()->find($kept->id))->not->toBeNull()
            ->and(Expense::query()->find($kept->id)->distribution_id)->toBeNull();
    }
});

it('puts a business-paid "Not <name> home" expense on that person\'s Home distribution', function () {
    $greg = notOurs_expense('Not Greg home', '2026-09-01 06:00:00', charges: [['amount' => '12.46', 'paymentInstrumentLast4Digits' => '4849']]);
    $patryk = notOurs_expense('not patryk home', '2026-09-01 06:00:00', charges: [['amount' => '12.46', 'paymentInstrumentLast4Digits' => '4842']]);
    notOurs_charge($greg);
    notOurs_charge($patryk);

    $this->artisan('expenses:remove-not-ours')->assertSuccessful();

    expect(Expense::query()->find($greg->id)->distribution_id)->toBe($this->gregHome->id)
        ->and(Expense::query()->find($patryk->id)->distribution_id)->toBe($this->patrykHome->id);
});

it('sends a refund where its order went: Home if the business paid, deleted with it if not', function () {
    // The real pair: order 27501 on company card 4849, refund 28598 back to it.
    $paidOrder = notOurs_expense('Not Greg home', '2026-08-06 06:00:00', amount: 12.46, order: '112-9575429-5101011', charges: [['amount' => '12.46', 'paymentInstrumentLast4Digits' => '4849']]);
    notOurs_charge($paidOrder);
    $paidRefund = notOurs_expense('Not Greg home', '2026-09-20 06:00:00', amount: -12.46, order: '112-9575429-5101011', charges: [['amount' => '-12.46', 'paymentInstrumentLast4Digits' => '4849']]);

    $unpaidOrder = notOurs_expense('Not Greg home', '2026-06-26 06:00:00', amount: 197.44, order: '112-5305967-6195443');
    $unpaidRefund = notOurs_expense('Not Greg home', '2026-07-01 06:00:00', amount: -197.44, order: '112-5305967-6195443');

    $this->artisan('expenses:remove-not-ours')->assertSuccessful();

    expect(Expense::query()->find($paidRefund->id)->distribution_id)->toBe($this->gregHome->id)
        ->and(Expense::query()->find($unpaidOrder->id))->toBeNull()
        ->and(Expense::query()->find($unpaidRefund->id))->toBeNull();
});

it('leaves a business-paid expense naming nobody\'s Home for a person', function () {
    $mike = notOurs_expense('Not Mike home', '2026-09-01 06:00:00');
    notOurs_charge($mike);

    $this->artisan('expenses:remove-not-ours')
        ->expectsOutputToContain('names no single Home distribution')
        ->assertSuccessful();

    expect(Expense::query()->find($mike->id))->not->toBeNull()
        ->and(Expense::query()->find($mike->id)->distribution_id)->toBeNull();
});

it('reads the Home distribution a "Not" PO names, typos included', function (string $po, ?string $home) {
    $id = NotOursPurchaseOrder::homeDistributionId($po, 1);

    expect($id === null ? null : Distribution::query()->find($id)->name)->toBe($home);
})->with([
    ['Not Greg home', 'Greg - Home'],
    ['Not greghome', 'Greg - Home'],
    ['Not Greg hom', 'Greg - Home'],
    ['NOT PATRYK HOME', 'Patryk - Home'],
    ['Not Mike home', null],
    ['Not office', null],
    ['Not', null],
]);

it('only lists on a dry run', function () {
    $old = notOurs_expense('Not Greg home', '2026-09-20 06:00:00');

    $this->artisan('expenses:remove-not-ours', ['--dry-run' => true])
        ->expectsOutputToContain("Would remove expense {$old->id}")
        ->assertSuccessful();

    expect(Expense::query()->find($old->id))->not->toBeNull();
});

it('gives a "Not" purchase order nothing for the project and distribution matchers', function () {
    $controller = new class extends ExpenseAutoMatchController
    {
        public function candidates(Expense $expense): array
        {
            return [$this->extractPurchaseOrderCandidates($expense), $this->extractDistributionOnlyCandidates($expense)];
        }
    };

    expect($controller->candidates(notOurs_expense('Not Greg home', '2026-10-01 06:00:00')))->toBe([[], []])
        ->and($controller->candidates(notOurs_expense('Greg home', '2026-10-01 06:00:00'))[1])->not->toBe([]);
});

it('does not import a refund again once someone deleted it', function () {
    $refund = Expense::withoutEvents(fn () => Expense::forceCreate([
        'amount' => -157.64, 'date' => '2024-07-07', 'vendor_id' => 54, 'belongs_to_vendor_id' => 1,
        'created_by_user_id' => 0, 'invoice' => '113-0000000-0000000',
    ]));
    $refund->delete();

    expect(app(ReceiptController::class)->amazonRefundAlreadyImported(1, '113-0000000-0000000', -157.64, '2024-07-07'))->toBeTrue()
        ->and(app(ReceiptController::class)->amazonRefundAlreadyImported(1, '113-0000000-0000000', -25.17, '2024-07-07'))->toBeFalse();
});

it('never removes what was paid even partly by gift card or points, or without payment details', function (array $charges) {
    $kept = notOurs_expense('Not Greg home', '2026-08-01 06:00:00', amount: 30.78, charges: $charges);

    $this->artisan('expenses:remove-not-ours')->assertSuccessful();

    expect(Expense::query()->find($kept->id))->not->toBeNull();
})->with([
    'gift card or points, whole order' => [[['amount' => '30.78', 'paymentInstrumentLast4Digits' => '']]],
    'points for part, card for the rest' => [[['amount' => '0.10', 'paymentInstrumentLast4Digits' => ''], ['amount' => '30.68', 'paymentInstrumentLast4Digits' => '4399']]],
    'card covers less than the order' => [[['amount' => '20.00', 'paymentInstrumentLast4Digits' => '4399']]],
    'no payment details' => [[]],
]);

it('treats a $0.00 line with no card as an authorization, not a payment', function () {
    $personal = notOurs_expense('Not Greg home', '2026-08-01 06:00:00', amount: 20.89, charges: [
        ['amount' => '0.00', 'paymentInstrumentLast4Digits' => ''],
        ['amount' => '20.89', 'paymentInstrumentLast4Digits' => '3305'],
    ]);

    $this->artisan('expenses:remove-not-ours')->assertSuccessful();

    expect(Expense::query()->find($personal->id))->toBeNull();
});

it('counts an order paid with a company card as business-paid before its bank charge links', function () {
    $earlier = notOurs_expense('Not Greg home', '2026-07-01 06:00:00', charges: [['amount' => '12.46', 'paymentInstrumentLast4Digits' => '4849']]);
    notOurs_charge($earlier);
    $notLinkedYet = notOurs_expense('Not Greg home', '2026-08-01 06:00:00', amount: 25.00, charges: [['amount' => '25.00', 'paymentInstrumentLast4Digits' => '4849']]);

    $this->artisan('expenses:remove-not-ours')->assertSuccessful();

    expect(Expense::query()->find($notLinkedYet->id))->not->toBeNull()
        ->and(Expense::query()->find($notLinkedYet->id)->distribution_id)->toBe($this->gregHome->id);
});
