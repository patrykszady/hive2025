<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Services\Expenses\PaidInvoiceMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * National Construction Rentals sends an invoice and later a payment
 * confirmation listing the invoices it paid; both became expenses, so each
 * invoice counted twice (28411, 27334, 22107, 27503 — 2026-10-21).
 */
beforeEach(function (): void {
    config(['scout.driver' => null]);

    $this->company = Vendor::factory()->create(['business_name' => 'GS Test Co']);
    $this->national = Vendor::factory()->create(['business_name' => 'National Construction Rental', 'business_type' => 'Retail']);
    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $this->company->id, 'plaid_ins_id' => 'ins_merge_'.uniqid()]);
    $this->account = BankAccount::create(['vendor_id' => $this->company->id, 'bank_id' => $bank->id, 'account_number' => '4903', 'plaid_account_id' => 'acc_merge_'.uniqid(), 'type' => 'checking']);
});

function national_expense(array $attrs, ?string $receiptText = null, bool $paid = false): Expense
{
    $expense = Expense::query()->create(array_merge([
        'vendor_id' => test()->national->id,
        'belongs_to_vendor_id' => test()->company->id,
        'created_by_user_id' => 0,
    ], $attrs));

    if ($receiptText !== null) {
        ExpenseReceipts::forceCreate([
            'expense_id' => $expense->id,
            'receipt_html' => '',
            'receipt_items' => ['raw_content' => $receiptText, 'items' => []],
            'receipt_filename' => $expense->id.'-receipt.pdf',
        ]);
    }

    if ($paid) {
        Transaction::withoutGlobalScopes()->create([
            'bank_account_id' => test()->account->id,
            'transaction_date' => $expense->date,
            'amount' => $expense->amount,
            'expense_id' => $expense->id,
            'plaid_merchant_description' => 'National Construction Ren',
        ]);
    }

    return $expense;
}

const NATIONAL_PAYMENT_SEP30 = 'National Construction Rentals PO BOX 841461 Los Angeles, CA 90084-1461 Bill To: GS CONSTRUCTION & REMODELING Payment Number: WEBPMT0001241009 Date: 9/30/2026 # Transaction Details Capture Amount: $262.06 Type: MasterCard ## Paid Invoice List Invoice Number Amount Paid 8299040 $131.03 JobSite: 30200012-0004 SiteAddress: 3154 VIOLET LN Site City: NORTHBROOK Site State: IL 8328629 $131.03 JobSite: 30200012-0004 SiteAddress: 3154 VIOLET LN Site City: NORTHBROOK Site State: IL';

it('reads the paid invoices off a payment confirmation, not the job site or street numbers', function () {
    expect(PaidInvoiceMerger::paidInvoiceNumbers(NATIONAL_PAYMENT_SEP30))->toBe(['8299040', '8328629'])
        // The 2024 layout: number first, amount after the site.
        ->and(PaidInvoiceMerger::paidInvoiceNumbers('Paid Invoice List Invoice Number 7471444 JobSite: 30200012-0002 SiteAddress: 504 LINCOLN AVE Site City: LAKE BLUFF Site State: IL Amount Paid $150.00'))->toBe(['7471444'])
        ->and(PaidInvoiceMerger::paidInvoiceNumbers('Invoice # 8299040 Total: $131.03'))->toBe([]);
});

it('folds an invoice into the payment that lists it, PDF and all', function () {
    $payment = national_expense(['amount' => 262.06, 'date' => '2026-09-30', 'invoice' => '8299040', 'project_id' => 364], NATIONAL_PAYMENT_SEP30, paid: true);
    $invoice = national_expense(['amount' => 131.03, 'date' => '2026-08-05', 'invoice' => '8299040', 'project_id' => 364], 'Invoice # 8299040 Invoice Date: 08/5/2026 Total: $131.03');

    $merges = app(PaidInvoiceMerger::class)->run();

    expect($merges)->toHaveCount(1)
        ->and($merges[0])->toMatchArray(['invoice_expense_id' => $invoice->id, 'payment_expense_id' => $payment->id, 'rule' => 'paid_invoice_list'])
        ->and(Expense::withoutGlobalScopes()->withTrashed()->find($invoice->id)->trashed())->toBeTrue()
        ->and(ExpenseReceipts::withoutGlobalScopes()->where('expense_id', $payment->id)->count())->toBe(2)
        ->and((float) $payment->fresh()->amount)->toBe(262.06);
});

it('gives the payment the invoice\'s project and number when it has none', function () {
    $payment = national_expense(['amount' => 150.00, 'date' => '2024-09-25'], 'Paid Invoice List Invoice Number 7471444 JobSite: 30200012-0002 SiteAddress: 504 LINCOLN AVE Amount Paid $150.00', paid: true);
    national_expense(['amount' => 150.00, 'date' => '2024-06-19', 'invoice' => '7471444', 'project_id' => 250], 'Invoice # 7471444');

    app(PaidInvoiceMerger::class)->run();

    expect($payment->fresh())->project_id->toBe(250)->invoice->toBe('7471444');
});

it('folds an invoice for a rental ticket into the payment of that ticket', function () {
    $payment = national_expense(['amount' => 181.00, 'date' => '2026-06-01', 'invoice' => '1904464', 'project_id' => 364], 'Payment receipt', paid: true);
    $invoice = national_expense(['amount' => 181.00, 'date' => '2026-06-11', 'invoice' => '8238410'], 'NATIONAL CONSTRUCTION RENTALS # INVOICE Customer # 30200012 Job Site # 0004 Ticket # 1904464 Invoice # 8238410');
    $otherAmount = national_expense(['amount' => 99.00, 'date' => '2026-06-11', 'invoice' => '8238411'], 'Ticket # 1904464 Invoice # 8238411');

    $merges = app(PaidInvoiceMerger::class)->run();

    expect(collect($merges)->pluck('invoice_expense_id')->all())->toBe([$invoice->id])
        ->and($merges[0]['rule'])->toBe('ticket')
        ->and($otherAmount->fresh()->trashed())->toBeFalse();
});

it('leaves paid invoices, other vendors, unpaid payments and dry runs alone', function () {
    // The invoice has its own bank charge: both are real payments.
    national_expense(['amount' => 262.06, 'date' => '2026-09-30', 'invoice' => '8299040'], NATIONAL_PAYMENT_SEP30, paid: true);
    $paidInvoice = national_expense(['amount' => 131.03, 'date' => '2026-08-05', 'invoice' => '8299040'], 'Invoice # 8299040', paid: true);

    // Another vendor's expense with the same number.
    $otherVendor = Expense::query()->create(['vendor_id' => Vendor::factory()->create()->id, 'belongs_to_vendor_id' => $this->company->id, 'amount' => 131.03, 'date' => '2026-08-05', 'invoice' => '8328629', 'created_by_user_id' => 0]);

    // A payment confirmation nothing paid yet.
    national_expense(['amount' => 50.00, 'date' => '2026-10-01', 'invoice' => '9000001'], 'Paid Invoice List Invoice Number Amount Paid 9000002 $50.00');
    $unpaidInvoice = national_expense(['amount' => 50.00, 'date' => '2026-09-20', 'invoice' => '9000002'], 'Invoice # 9000002');

    expect(app(PaidInvoiceMerger::class)->run())->toBe([])
        ->and($paidInvoice->fresh()->trashed())->toBeFalse()
        ->and($otherVendor->fresh()->trashed())->toBeFalse()
        ->and($unpaidInvoice->fresh()->trashed())->toBeFalse();

    $payment = national_expense(['amount' => 131.03, 'date' => '2026-10-16', 'invoice' => '8359103'], 'Paid Invoice List 8359103 $131.03', paid: true);
    $invoice = national_expense(['amount' => 131.03, 'date' => '2026-10-01', 'invoice' => '8359103'], 'Invoice # 8359103');

    expect(app(PaidInvoiceMerger::class)->run(dryRun: true))->toHaveCount(1)
        ->and($invoice->fresh()->trashed())->toBeFalse();
});
