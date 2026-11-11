<?php

/**
 * A receipt carrying the office's R marks its expense Reimbursement = Client
 * (ReceiptReimbursementMark): typed into the order's PO / job name on any
 * receipt, or hand-written on paper the office scanned or uploaded.
 */

use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\ExpenseSplits;
use App\Models\Vendor;
use App\Services\Expenses\ReceiptReimbursementMark;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function rmark_expense(array $attributes = []): Expense
{
    $company = Vendor::factory()->create();

    return Expense::query()->create(array_merge([
        'amount' => 453.76, 'date' => '2026-11-10', 'vendor_id' => Vendor::factory()->create()->id,
        'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => 0,
    ], $attributes));
}

/**
 * A receipt whose picture could not settle it ("unknown") unless said
 * otherwise, so the transcribed handwriting decides.
 *
 * @param  list<string>  $notes
 */
function rmark_receipt(Expense $expense, array $notes, bool $scanned = true, ?string $picture = 'unknown'): ExpenseReceipts
{
    return ExpenseReceipts::query()->create([
        'expense_id' => $expense->id,
        'receipt_filename' => 'scan.pdf',
        'receipt_html' => "MENARDS\nR\n®",
        'receipt_items' => array_filter(['handwritten_notes' => $notes, 'client_reimbursement_mark_image' => $picture], fn ($value) => $value !== null),
        'auto_receipt_message_id' => $scanned ? 'epson-message-1' : null,
    ]);
}

it('marks the expense Client when a scanned receipt carries the circled R', function (array $notes) {
    $expense = rmark_expense();

    rmark_receipt($expense, $notes);

    expect($expense->fresh()->reimbursment)->toBe('Client');
})->with([
    'alone' => [['R']],
    'after the job number' => [['123 R']],
    'joined' => [['911 Will | R']],
    'in brackets' => [['949', '(R)']],
]);

it('leaves the expense alone without the mark', function (array $notes) {
    $expense = rmark_expense();

    rmark_receipt($expense, $notes);

    expect($expense->fresh()->reimbursment)->toBeNull();
})->with([
    'no notes' => [[]],
    'job number only' => [['1400']],
    'a name starting with R' => [['Rob', '215 Huron RD']],
    'lowercase' => [['r']],
]);

it('never reads a receipt the office did not handle (emailed, crawled)', function () {
    $expense = rmark_expense();

    rmark_receipt($expense, ['R'], scanned: false, picture: null);

    expect($expense->fresh()->reimbursment)->toBeNull();
});

it('keeps a reimbursement someone already chose', function () {
    $expense = rmark_expense(['reimbursment' => '212']);

    rmark_receipt($expense, ['R']);

    expect($expense->fresh()->getRawOriginal('reimbursment'))->toBe('212');
});

it('leaves a split expense alone', function () {
    $expense = rmark_expense();
    ExpenseSplits::withoutEvents(fn () => ExpenseSplits::query()->forceCreate(['expense_id' => $expense->id, 'amount' => 100, 'belongs_to_vendor_id' => $expense->belongs_to_vendor_id, 'created_by_user_id' => 0]));

    rmark_receipt($expense, ['R']);

    expect($expense->fresh()->reimbursment)->toBeNull();
});

it('marks it when the handwriting is read after the scan was saved', function () {
    $expense = rmark_expense();
    $receipt = rmark_receipt($expense, []);
    expect($expense->fresh()->reimbursment)->toBeNull();

    $receipt = ExpenseReceipts::query()->find($receipt->id);
    $receipt->receipt_items = ['handwritten_notes' => ['3154 | R'], 'client_reimbursement_mark_image' => 'unknown'];
    $receipt->save();

    expect($expense->fresh()->reimbursment)->toBe('Client');
});

it('does not put the mark back after someone clears it', function () {
    $expense = rmark_expense();
    $receipt = rmark_receipt($expense, ['R']);
    $expense->fresh()->forceFill(['reimbursment' => null])->save();

    $receipt = ExpenseReceipts::query()->find($receipt->id);
    $receipt->receipt_items = ['handwritten_notes' => ['R'], 'client_reimbursement_mark_image' => 'unknown', 'total' => 453.76];
    $receipt->save();

    expect($expense->fresh()->reimbursment)->toBeNull()
        ->and(ReceiptReimbursementMark::notesCarryMark($receipt->receipt_items))->toBeTrue();
});

it('takes the receipt reader\'s own answer when the picture could not tell', function (string $answer, array $notes, ?string $expected) {
    $expense = rmark_expense();

    ExpenseReceipts::query()->create([
        'expense_id' => $expense->id,
        'receipt_filename' => 'scan.pdf',
        'receipt_items' => ['handwritten_notes' => $notes, 'client_reimbursement_mark' => $answer, 'client_reimbursement_mark_image' => 'unknown'],
        'auto_receipt_message_id' => 'epson-message-2',
    ]);

    expect($expense->fresh()->reimbursment)->toBe($expected);
})->with([
    'true, though the R was not transcribed' => ['true', ['949', 'E'], 'Client'],
    'false wins over a transcribed R' => ['false', ['1900 R'], null],
    'unknown falls back to the handwriting: R' => ['unknown', ['3154 | R'], 'Client'],
    'unknown falls back to the handwriting: none' => ['unknown', ['3154'], null],
]);

it('reads the analyzer field as true, false or unknown', function (mixed $field, string $expected) {
    expect(ReceiptReimbursementMark::answerFrom($field))->toBe($expected);
})->with([
    [['type' => 'string', 'valueString' => 'true'], 'true'],
    [['type' => 'string', 'valueString' => 'False'], 'false'],
    [['type' => 'string', 'valueString' => 'unknown'], 'unknown'],
    [['type' => 'boolean', 'valueBoolean' => true], 'true'],
    [['type' => 'string'], 'unknown'],
    [null, 'unknown'],
]);

it('lets the picture decide', function (?string $picture, array $notes, ?string $expected) {
    $expense = rmark_expense();

    rmark_receipt($expense, $notes, picture: $picture);

    expect($expense->fresh()->reimbursment)->toBe($expected);
})->with([
    'true, though nothing was transcribed' => ['true', ['949'], 'Client'],
    'false, though an R was transcribed' => ['false', ['1400 | (R)'], null],
    'not looked at: the transcription decides' => [null, ['123 R'], 'Client'],
]);

it('reads the picture through the Azure image analyzer', function () {
    Illuminate\Support\Facades\Storage::fake('files');
    $page = imagecreatetruecolor(200, 600);
    ob_start();
    imagepng($page);
    Illuminate\Support\Facades\Storage::disk('files')->put('_temp_ocr/scan.png', ob_get_clean());

    $azure = Mockery::mock(App\Services\ContentUnderstandingService::class);
    $azure->shouldReceive('analyzeBinary')->once()
        ->withArgs(fn (string $png, string $analyzer) => str_starts_with($png, "\x89PNG") && $analyzer === config('services.azure_cu.analyzer_id_receipt_mark'))
        ->andReturn(['result' => ['contents' => [['fields' => [
            'ClientReimbursementMark' => ['type' => 'string', 'valueString' => 'true'],
            'Handwriting' => ['type' => 'string', 'valueString' => '949 R'],
        ]]]]]);

    $answer = (new App\Services\Expenses\ScannedReceiptMarkReader($azure))->read(Illuminate\Support\Facades\Storage::disk('files')->path('_temp_ocr/scan.png'));

    expect($answer)->toBe(['mark' => 'true', 'handwriting' => '949 R']);
});

it('looks at a scan while it is processed, and at nothing else', function () {
    config(['services.azure_cu.receipt_mark_image_check' => true]);
    $reader = Mockery::mock(App\Services\Expenses\ScannedReceiptMarkReader::class);
    $reader->shouldReceive('read')->once()->andReturn(['mark' => 'true', 'handwriting' => '949 R']);
    app()->instance(App\Services\Expenses\ScannedReceiptMarkReader::class, $reader);

    $controller = app(App\Http\Controllers\CompanyEmailController::class);
    $look = fn (?array $items, bool $officePaper) => (fn () => $this->withScannedReceiptMark($items, $officePaper, '_temp_ocr/scan.pdf'))->call($controller);

    expect($look(['total' => 12.5], true))->toBe(['total' => 12.5, 'client_reimbursement_mark_image' => 'true', 'client_reimbursement_mark_handwriting' => '949 R'])
        ->and($look(['total' => 12.5], false))->toBe(['total' => 12.5]);
});

it('stores unknown when the scan cannot be looked at', function () {
    config(['services.azure_cu.receipt_mark_image_check' => true]);
    $reader = Mockery::mock(App\Services\Expenses\ScannedReceiptMarkReader::class);
    $reader->shouldReceive('read')->once()->andThrow(new RuntimeException('ContentUnderstanding: timed out'));
    app()->instance(App\Services\Expenses\ScannedReceiptMarkReader::class, $reader);

    $controller = app(App\Http\Controllers\CompanyEmailController::class);
    $items = (fn () => $this->withScannedReceiptMark(['handwritten_notes' => ['1400 R']], true, '_temp_ocr/scan.pdf'))->call($controller);

    expect($items['client_reimbursement_mark_image'])->toBe('unknown')
        ->and(ReceiptReimbursementMark::carriesMark($items))->toBeTrue();
});

it('does not look when pictures are switched off', function () {
    config(['services.azure_cu.receipt_mark_image_check' => false]);
    $reader = Mockery::mock(App\Services\Expenses\ScannedReceiptMarkReader::class);
    $reader->shouldNotReceive('read');
    app()->instance(App\Services\Expenses\ScannedReceiptMarkReader::class, $reader);

    $controller = app(App\Http\Controllers\CompanyEmailController::class);

    expect((fn () => $this->withScannedReceiptMark(['total' => 1], true, '_temp_ocr/scan.pdf'))->call($controller))->toBe(['total' => 1]);
});

it('marks the expense from a receipt someone uploaded', function () {
    $expense = rmark_expense();

    rmark_receipt($expense, ['949'], scanned: false, picture: 'true');

    expect($expense->fresh()->reimbursment)->toBe('Client');
});

it('marks the expense Client when the order\'s PO carries the R, whatever brought the receipt in', function (mixed $purchaseOrder) {
    $expense = rmark_expense();

    ExpenseReceipts::query()->create([
        'expense_id' => $expense->id,
        'receipt_filename' => 'ereceipt.pdf',
        'receipt_items' => ['purchase_order' => $purchaseOrder],
    ]);

    expect($expense->fresh()->reimbursment)->toBe('Client');
})->with([
    'Home Depot job name' => ['2600 R'],
    'underscore' => ['288_R'],
    'with a hash' => ['#3154 R'],
    'words then R' => ['630 red barn R'],
    'R then more text' => ['126 R 2024 PRO XTRA SPEND 04/11: $4,611.80'],
    'a list' => [['1856', '1856 R']],
    'lowercase' => ['1400 r'],
]);

it('leaves the expense alone when the PO has no standalone R', function (mixed $purchaseOrder) {
    $expense = rmark_expense();

    ExpenseReceipts::query()->create([
        'expense_id' => $expense->id,
        'receipt_filename' => 'ereceipt.pdf',
        'receipt_items' => ['purchase_order' => $purchaseOrder],
    ]);

    expect($expense->fresh()->reimbursment)->toBeNull();
})->with([
    'job number' => ['3154'],
    'a name starting with R' => ['ROSEWOOD'],
    'R inside a code' => ['RM1400'],
    'none' => [null],
]);

it('marks it when the PO is filled in after the receipt was saved', function () {
    $expense = rmark_expense();
    $receipt = ExpenseReceipts::query()->create(['expense_id' => $expense->id, 'receipt_filename' => 'ereceipt.pdf', 'receipt_items' => ['purchase_order' => null]]);

    $receipt = ExpenseReceipts::query()->find($receipt->id);
    $receipt->receipt_items = ['purchase_order' => '1856 R'];
    $receipt->save();

    expect($expense->fresh()->reimbursment)->toBe('Client');
});

/** An emailed receipt (not office paper) with this PO on the expense. */
function rmark_po_receipt(Expense $expense, string $purchaseOrder): ExpenseReceipts
{
    return ExpenseReceipts::query()->create([
        'expense_id' => $expense->id,
        'receipt_filename' => 'ereceipt.pdf',
        'receipt_items' => ['purchase_order' => $purchaseOrder],
    ]);
}

it('keeps a mark the PO set, whatever a later scan shows', function (string $picture) {
    $expense = rmark_expense();
    rmark_po_receipt($expense, '3154 R');
    expect($expense->fresh()->reimbursment)->toBe('Client');

    rmark_receipt($expense, ['3154'], picture: $picture);

    expect($expense->fresh()->reimbursment)->toBe('Client');
})->with(['false', 'true', 'unknown']);

it('marks from the PO even when a scan without an R is already on the expense', function () {
    $expense = rmark_expense();
    rmark_receipt($expense, ['3154'], picture: 'false');

    rmark_po_receipt($expense, '3154 R');

    expect($expense->fresh()->reimbursment)->toBe('Client');
});
