<?php

use App\Http\Controllers\CompanyEmailController;
use App\Jobs\BackfillReceiptHandwrittenNoteJob;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Services\NylasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function precedenceExpense(): Expense
{
    return Expense::withoutGlobalScopes()->create([
        'amount' => -27.50,
        'date' => '2026-08-19',
        'invoice' => null,
        'note' => null,
        'project_id' => null,
        'distribution_id' => null,
        'vendor_id' => 0,
        'check_id' => null,
        'reimbursment' => null,
        'belongs_to_vendor_id' => 1,
        'created_by_user_id' => 1,
        'paid_by' => null,
    ]);
}

function cleanEmailReceiptItems(): array
{
    return [
        'items' => [
            ['Price' => 10.48, 'Quantity' => 1, 'TotalPrice' => 10.48, 'Description' => 'PW WT 6', 'VendorCode' => '070798005853'],
            ['Price' => 6.58, 'Quantity' => 1, 'TotalPrice' => 6.58, 'Description' => 'DAP PLASTIC WOOD', 'VendorCode' => '044021850480'],
            ['Price' => 1.97, 'Quantity' => 4, 'TotalPrice' => 7.88, 'Description' => 'PFJ LWM49 CROWN', 'VendorCode' => '773204104996'],
        ],
        'subtotal' => 24.94,
        'total' => 27.5,
        'total_tax' => 2.56,
        'transaction_date' => '2026-08-19',
        'merchant_name' => 'The Home Depot',
        'purchase_order' => '',
        'handwritten_notes' => [],
    ];
}

function garbledScanItems(): array
{
    return [
        'items' => [
            ['Price' => 10.48, 'Quantity' => 1, 'TotalPrice' => 10.48, 'Description' => 'DAP PLASTIC WOOD LTX', 'VendorCode' => '070798005853'],
        ],
        'subtotal' => 24.94,
        'total' => 27.5,
        'total_tax' => 2.56,
        'transaction_date' => '2026-08-19',
        'merchant_name' => 'THE MOXKIO',
        'purchase_order' => '',
        'handwritten_notes' => [],
    ];
}

function saveReceiptViaController(int $expenseId, array $fields, string $html, string $filename): array
{
    Storage::disk('files')->put('_temp_ocr/' . $filename, '%PDF-fake');

    $controller = new CompanyEmailController(Mockery::mock(NylasService::class));

    return $controller->saveExpenseReceipt(
        $expenseId,
        ['content' => $html, 'fields' => $fields],
        $filename,
    );
}

it('recognizes the same purchase across a clean e-receipt and a garbled scan', function () {
    expect(ExpenseReceipts::matchesSamePurchase(cleanEmailReceiptItems(), garbledScanItems()))->toBeTrue();

    $differentTotal = garbledScanItems();
    $differentTotal['total'] = 31.02;
    expect(ExpenseReceipts::matchesSamePurchase(cleanEmailReceiptItems(), $differentTotal))->toBeFalse();

    $differentPo = garbledScanItems();
    $differentPo['purchase_order'] = 'JOB 912';
    expect(ExpenseReceipts::matchesSamePurchase(cleanEmailReceiptItems(), $differentPo))->toBeFalse();
});

it('scores reconciling line items above garbled ones and ties in favor of the incumbent', function () {
    expect(ExpenseReceipts::itemsSupersede(cleanEmailReceiptItems(), garbledScanItems()))->toBeTrue()
        ->and(ExpenseReceipts::itemsSupersede(garbledScanItems(), cleanEmailReceiptItems()))->toBeFalse()
        ->and(ExpenseReceipts::itemsSupersede(cleanEmailReceiptItems(), cleanEmailReceiptItems()))->toBeFalse()
        ->and(ExpenseReceipts::itemsSupersede(garbledScanItems(), ['items' => [], 'total' => 27.5]))->toBeTrue();
});

it('moves line items aside when converting a payload to supplement form', function () {
    $supplement = ExpenseReceipts::toSupplementReceiptItems(garbledScanItems(), 17867);

    expect($supplement['items'])->toBe([])
        ->and($supplement['supplanted_items'])->toHaveCount(1)
        ->and($supplement['supplement_of_receipt_id'])->toBe(17867)
        ->and($supplement['total'])->toBe(27.5)
        ->and($supplement['handwritten_notes'])->toBe([]);
});

it('prefers the item-bearing sibling and ignores material orders when matching the same purchase', function () {
    $expense = precedenceExpense();

    $materialOrder = ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'receipt_filename' => 'mo.pdf',
        'receipt_items' => cleanEmailReceiptItems(),
        'is_material_order' => true,
    ]);
    $supplementRow = ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'receipt_filename' => 'supplement.pdf',
        'receipt_items' => ExpenseReceipts::toSupplementReceiptItems(garbledScanItems(), 999),
    ]);
    $primary = ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'receipt_filename' => 'primary.pdf',
        'receipt_items' => cleanEmailReceiptItems(),
    ]);

    expect(ExpenseReceipts::findSamePurchaseSibling($expense->id, garbledScanItems())?->id)
        ->toBe($primary->id)
        ->not->toBe($materialOrder->id)
        ->not->toBe($supplementRow->id);
});

it('saves a later garbled scan as a notes-only supplement of the existing e-receipt', function () {
    Storage::fake('files');
    Queue::fake();

    $expense = precedenceExpense();
    $emailReceipt = ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'receipt_filename' => $expense->id . '-email.pdf',
        'receipt_html' => 'clean e-receipt text',
        'receipt_items' => cleanEmailReceiptItems(),
    ]);

    $saved = saveReceiptViaController($expense->id, garbledScanItems(), 'garbled scan text', 'scan.pdf');

    $scanRow = ExpenseReceipts::query()
        ->where('expense_id', $expense->id)
        ->where('id', '!=', $emailReceipt->id)
        ->firstOrFail();

    expect($saved)->toBe([$expense->id . '-scan.pdf'])
        ->and($scanRow->isSupplement())->toBeTrue()
        ->and($scanRow->receipt_items['items'])->toBe([])
        ->and($scanRow->receipt_items['supplement_of_receipt_id'])->toBe($emailReceipt->id)
        ->and($scanRow->receipt_items['supplanted_items'])->toHaveCount(1)
        ->and($scanRow->receipt_html)->toBe('garbled scan text')
        ->and($emailReceipt->fresh()->receipt_items['items'])->toHaveCount(3)
        ->and($emailReceipt->fresh()->isSupplement())->toBeFalse();

    Storage::disk('files')->assertExists('receipts/' . $expense->id . '-scan.pdf');
    Queue::assertPushed(BackfillReceiptHandwrittenNoteJob::class, fn ($job) => $job->receiptId === $scanRow->id && $job->onlyNew);
});

it('demotes an earlier garbled scan when the clean e-receipt arrives afterwards', function () {
    Storage::fake('files');
    Queue::fake();

    $expense = precedenceExpense();
    $scanItems = garbledScanItems();
    $scanItems['handwritten_notes'] = ['912'];
    $scanRow = ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'receipt_filename' => $expense->id . '-scan.pdf',
        'receipt_html' => 'garbled scan text',
        'receipt_items' => $scanItems,
    ]);

    saveReceiptViaController($expense->id, cleanEmailReceiptItems(), 'clean e-receipt text', 'email.pdf');

    $emailRow = ExpenseReceipts::query()
        ->where('expense_id', $expense->id)
        ->where('id', '!=', $scanRow->id)
        ->firstOrFail();
    $scanRow->refresh();

    expect($emailRow->isSupplement())->toBeFalse()
        ->and($emailRow->receipt_items['items'])->toHaveCount(3)
        ->and($scanRow->isSupplement())->toBeTrue()
        ->and($scanRow->receipt_items['items'])->toBe([])
        ->and($scanRow->receipt_items['supplement_of_receipt_id'])->toBe($emailRow->id)
        ->and($scanRow->receipt_items['supplanted_items'])->toHaveCount(1)
        ->and($scanRow->receipt_items['handwritten_notes'])->toBe(['912'])
        ->and($scanRow->notes)->toContain('912');

    // The demoted scan already has notes — no second OCR pass needed.
    Queue::assertNotPushed(BackfillReceiptHandwrittenNoteJob::class);
});

it('keeps a scan with its own handwritten notes as a supplement without re-running OCR', function () {
    Storage::fake('files');
    Queue::fake();

    $expense = precedenceExpense();
    ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'receipt_filename' => $expense->id . '-email.pdf',
        'receipt_html' => 'clean e-receipt text',
        'receipt_items' => cleanEmailReceiptItems(),
    ]);

    $scanItems = garbledScanItems();
    $scanItems['handwritten_notes'] = ['912'];
    saveReceiptViaController($expense->id, $scanItems, 'garbled scan text', 'scan.pdf');

    $scanRow = ExpenseReceipts::query()
        ->where('expense_id', $expense->id)
        ->orderByDesc('id')
        ->firstOrFail();

    expect($scanRow->isSupplement())->toBeTrue()
        ->and($scanRow->receipt_items['handwritten_notes'])->toBe(['912']);

    Queue::assertNotPushed(BackfillReceiptHandwrittenNoteJob::class);
});

it('leaves receipts for a different purchase untouched', function () {
    Storage::fake('files');
    Queue::fake();

    $expense = precedenceExpense();
    ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'receipt_filename' => $expense->id . '-email.pdf',
        'receipt_html' => 'clean e-receipt text',
        'receipt_items' => cleanEmailReceiptItems(),
    ]);

    $otherPurchase = garbledScanItems();
    $otherPurchase['total'] = 54.13;
    saveReceiptViaController($expense->id, $otherPurchase, 'other purchase text', 'other.pdf');

    $newRow = ExpenseReceipts::query()
        ->where('expense_id', $expense->id)
        ->orderByDesc('id')
        ->firstOrFail();

    expect($newRow->isSupplement())->toBeFalse()
        ->and($newRow->receipt_items['items'])->toHaveCount(1);

    Queue::assertNotPushed(BackfillReceiptHandwrittenNoteJob::class);
});

it('skips a garbled re-scan that matches an existing supplement instead of upgrading past the primary', function () {
    Storage::fake('files');
    Queue::fake();

    $expense = precedenceExpense();
    $primary = ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'receipt_filename' => $expense->id . '-email.pdf',
        'receipt_html' => 'clean e-receipt text',
        'receipt_items' => cleanEmailReceiptItems(),
    ]);
    $supplementItems = ExpenseReceipts::toSupplementReceiptItems(garbledScanItems(), $primary->id);
    $supplementItems['handwritten_notes'] = ['912'];
    $supplement = ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'receipt_filename' => $expense->id . '-scan.pdf',
        'receipt_html' => 'garbled scan text',
        'receipt_items' => $supplementItems,
    ]);

    $rescanItems = garbledScanItems();
    $rescanItems['handwritten_notes'] = ['912'];
    $saved = saveReceiptViaController($expense->id, $rescanItems, 'garbled rescan text', 'rescan.pdf');

    expect($saved)->toBe([])
        ->and(ExpenseReceipts::query()->where('expense_id', $expense->id)->count())->toBe(2)
        ->and($primary->fresh()->isSupplement())->toBeFalse()
        ->and($supplement->fresh()->receipt_items['handwritten_notes'])->toBe(['912']);

    Queue::assertNotPushed(BackfillReceiptHandwrittenNoteJob::class);
});

it('re-points earlier supplements at the new primary when a chained upgrade demotes theirs', function () {
    Storage::fake('files');
    Queue::fake();

    $expense = precedenceExpense();

    // R1: primary with 2 items that do NOT reconcile against the subtotal.
    $r1Items = cleanEmailReceiptItems();
    array_pop($r1Items['items']);
    $r1 = ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'receipt_filename' => $expense->id . '-r1.pdf',
        'receipt_html' => 'partial capture',
        'receipt_items' => $r1Items,
    ]);

    // R2: garbled scan, saved as supplement of R1.
    saveReceiptViaController($expense->id, garbledScanItems(), 'garbled scan text', 'r2.pdf');
    $r2 = ExpenseReceipts::query()->where('expense_id', $expense->id)->orderByDesc('id')->firstOrFail();
    expect($r2->receipt_items['supplement_of_receipt_id'])->toBe($r1->id);

    // R3: clean reconciling 3-item copy — supersedes R1.
    saveReceiptViaController($expense->id, cleanEmailReceiptItems(), 'clean e-receipt text', 'r3.pdf');
    $r3 = ExpenseReceipts::query()->where('expense_id', $expense->id)->orderByDesc('id')->firstOrFail();

    expect($r3->isSupplement())->toBeFalse()
        ->and($r1->fresh()->isSupplement())->toBeTrue()
        ->and($r1->fresh()->receipt_items['supplement_of_receipt_id'])->toBe($r3->id)
        ->and($r2->fresh()->receipt_items['supplement_of_receipt_id'])->toBe($r3->id)
        ->and($r2->fresh()->resolveSupplementPrimary()->id)->toBe($r3->id);
});

it('never demotes a material-order row through the upgrade path', function () {
    Storage::fake('files');
    Queue::fake();

    $expense = precedenceExpense();
    $materialItems = garbledScanItems();
    $materialItems['handwritten_notes'] = ['see attached'];
    $materialOrder = ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'receipt_filename' => $expense->id . '-mo.pdf',
        'receipt_html' => 'material order text',
        'receipt_items' => $materialItems,
        'is_material_order' => true,
    ]);

    // Same total/date/PO, empty notes → the notes-subset rule flags it as a
    // duplicate of the material order; its better items must NOT upgrade it.
    $saved = saveReceiptViaController($expense->id, cleanEmailReceiptItems(), 'clean e-receipt text', 'email.pdf');

    expect($saved)->toBe([])
        ->and(ExpenseReceipts::query()->where('expense_id', $expense->id)->count())->toBe(1)
        ->and($materialOrder->fresh()->receipt_items['items'])->toHaveCount(1)
        ->and($materialOrder->fresh()->isSupplement())->toBeFalse();
});

it('still skips exact duplicates before the same-purchase rule runs', function () {
    Storage::fake('files');
    Queue::fake();

    $expense = precedenceExpense();
    $existing = ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'receipt_filename' => $expense->id . '-email.pdf',
        'receipt_html' => 'clean e-receipt text',
        'receipt_items' => cleanEmailReceiptItems(),
    ]);

    $saved = saveReceiptViaController($expense->id, cleanEmailReceiptItems(), 'clean e-receipt text', 'rescan.pdf');

    expect($saved)->toBe([])
        ->and(ExpenseReceipts::query()->where('expense_id', $expense->id)->count())->toBe(1)
        ->and($existing->fresh()->isSupplement())->toBeFalse();

    Queue::assertNotPushed(BackfillReceiptHandwrittenNoteJob::class);
});
