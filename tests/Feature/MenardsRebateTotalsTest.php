<?php

use App\Http\Controllers\ReceiptController;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Menards rebate checks spent at the register print before the total, so
 * TOTAL SALE — and Menards' own transaction total, which the import used — is
 * what was left after them (2026-10-02: expense 25952 read $173.43 for a
 * $216.67 purchase; 26088 read -$82.50 for an $82.50 one). Many were fixed by
 * hand over the years.
 */
function rebateReceipt(string $sale, string $rebate, string $tender = '', ?string $preTax = null, ?string $tax = null): string
{
    $totals = $preTax !== null ? "TOTAL\n{$preTax}\nTAX MOUNT PROSPEC-IL 10%\n{$tax}\n" : '';

    return "PINE TAPERED SHIMS 4334222 3 @1.56 4.68\nMENARD REBATE NO: 6323495338\n{$rebate}-\nRemaining Balance: \$0.00\n{$totals}TOTAL SALE\n{$sale}\n{$tender}";
}

/** @return array{0: Vendor, 1: Vendor} Menards, and the company that owns the expenses */
function menardsVendors(): array
{
    return [
        Vendor::factory()->create(['business_name' => 'Menards', 'business_type' => 'Retail']),
        Vendor::factory()->create(['business_name' => 'GS Test Co']),
    ];
}

function menardsExpense(Vendor $menards, Vendor $company, float $amount, string $text): Expense
{
    $expense = Expense::query()->create(['amount' => $amount, 'date' => '2025-11-05', 'vendor_id' => $menards->id, 'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => 0]);
    ExpenseReceipts::create(['expense_id' => $expense->id, 'receipt_html' => $text]);

    return $expense;
}

it('raises Menards expenses to TOTAL SALE plus the rebate checks, on --commit only', function () {
    [$menards, $company] = menardsVendors();
    $short = menardsExpense($menards, $company, 173.43, rebateReceipt('173.43', '43.24', "US Debit 4849\n173.43", '157.66', '15.77'));
    $wrongSign = menardsExpense($menards, $company, -82.50, rebateReceipt('0.00', '82.50'));
    $fixedByHand = menardsExpense($menards, $company, 243.01, rebateReceipt('0.00', '243.01'));

    $this->artisan('expenses:fix-menards-rebate-totals')->expectsOutputToContain('2 to correct (dry run)')->assertSuccessful();
    expect((float) $short->fresh()->amount)->toBe(173.43);

    $this->artisan('expenses:fix-menards-rebate-totals', ['--commit' => true])->assertSuccessful();

    expect((float) $short->fresh()->amount)->toBe(216.67)
        ->and((float) $wrongSign->fresh()->amount)->toBe(82.50)
        ->and((float) $fixedByHand->fresh()->amount)->toBe(243.01);

    // The receipts describe the purchase, Menards' printed figures kept alongside.
    expect($short->receipts()->first()->receipt_items)->toMatchArray(['subtotal' => 200.90, 'total_tax' => 15.77, 'total' => 216.67, 'menards_rebate_checks' => 43.24, 'menards_total_sale' => 173.43])
        ->and($fixedByHand->receipts()->first()->receipt_items)->toMatchArray(['subtotal' => 243.01, 'total_tax' => 0.0, 'total' => 243.01]);
});

it('imports a rebate-check receipt at the full total, and recognizes it when Menards sends it again', function () {
    [$menards, $company] = menardsVendors();
    Storage::fake('files');
    $dir = sys_get_temp_dir().'/menards-rebate-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/r1.pdf', '%PDF-1.4 receipt');
    file_put_contents($dir.'/manifest.json', json_encode([
        'scrapedAt' => '2025-11-05T15:00:00Z',
        'totalReceipts' => 1,
        'receipts' => [['date' => 'November 5, 2025 @ 8:52 AM', 'amount' => '$173.43', 'card' => 'Mastercard **** 4849', 'store' => 'Long Grove', 'file' => 'r1.pdf']],
    ]));

    $text = rebateReceipt('173.43', '43.24', "US Debit 4849\n173.43");
    $this->mock(ReceiptController::class, fn ($mock) => $mock->shouldReceive('extractReceipt')->andReturn(['content' => $text, 'fields' => ['raw_content' => $text, 'items' => []]]));

    $run = fn () => $this->artisan('menards:scrape-receipts', [
        '--skip-scrape' => true, '--match-expenses' => true, '--lock-wait' => 0, '--since' => '2025-01-01',
        '--output-dir' => $dir, '--vendor-id' => $menards->id, '--belongs-to-vendor-id' => $company->id,
    ]);

    $run()->expectsOutputToContain('REBATE')->assertSuccessful();
    $run()->assertSuccessful();

    $expenses = Expense::withoutGlobalScopes()->where('vendor_id', $menards->id)->get();
    expect($expenses)->toHaveCount(1)
        ->and((float) $expenses->first()->amount)->toBe(216.67)
        ->and(ExpenseReceipts::where('expense_id', $expenses->first()->id)->count())->toBe(1)
        ->and(ExpenseReceipts::where('expense_id', $expenses->first()->id)->first()->receipt_items)->toMatchArray(['total' => 216.67, 'menards_rebate_checks' => 43.24]);
});

/**
 * One Menards.com receipt in an ingest folder, and the importer run on it.
 *
 * @param  array<string, mixed>  $fields  what the receipt reader returns for Menards' PDF
 */
function importMenardsReceipt($test, Vendor $menards, Vendor $company, array $fields, string $amount = '$76.84', array $options = [])
{
    $dir = sys_get_temp_dir().'/menards-beside-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/r1.pdf', '%PDF-1.4 receipt');
    file_put_contents($dir.'/manifest.json', json_encode([
        'scrapedAt' => '2026-11-11T14:54:36Z',
        'totalReceipts' => 1,
        'receipts' => [['date' => 'November 10, 2026 @ 10:34 AM', 'amount' => $amount, 'card' => 'Mastercard **** 4849', 'store' => 'Mount Prospect', 'file' => 'r1.pdf']],
    ]));

    $reader = Mockery::mock(ReceiptController::class);
    $reader->shouldReceive('extractReceipt')->andReturn(['content' => 'MENARDS - MOUNT PROSPECT', 'fields' => $fields]);
    app()->instance(ReceiptController::class, $reader);

    return $test->artisan('menards:scrape-receipts', array_merge([
        '--skip-scrape' => true, '--match-expenses' => true, '--lock-wait' => 0, '--since' => '2026-01-01',
        '--output-dir' => $dir, '--vendor-id' => $menards->id, '--belongs-to-vendor-id' => $company->id,
    ], $options));
}

function scannedMenardsExpense(Vendor $menards, Vendor $company, array $scanItems): Expense
{
    $expense = Expense::query()->create(['amount' => 76.84, 'date' => '2026-11-10', 'vendor_id' => $menards->id, 'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => 0]);
    ExpenseReceipts::create([
        'expense_id' => $expense->id,
        'receipt_filename' => $expense->id.'-2026-11-10-21-11-00-79.pdf',
        'receipt_html' => 'MENARDS scan',
        'receipt_items' => array_merge(['total' => 76.84, 'subtotal' => 69.70, 'handwritten_notes' => ['3154', 'R']], $scanItems),
        'auto_receipt_message_id' => 'epson-1',
    ]);

    return $expense;
}

it('adds Menards\' own copy beside the office scan instead of throwing it away', function () {
    [$menards, $company] = menardsVendors();
    Storage::fake('files');
    // The scan read two of the three lines; Menards' PDF has all three and they add up.
    $expense = scannedMenardsExpense($menards, $company, ['items' => [['Description' => 'CPT TRIM', 'TotalPrice' => 17.97], ['Description' => 'SHIMS', 'TotalPrice' => 4.68]]]);

    importMenardsReceipt($this, $menards, $company, ['total' => 76.84, 'subtotal' => 69.70, 'items' => [
        ['Description' => 'CPT TRIM 2X72', 'TotalPrice' => 17.97], ['Description' => 'PINE SHIMS', 'TotalPrice' => 4.68], ['Description' => 'BASE SHOE', 'TotalPrice' => 47.05],
    ]])->expectsOutputToContain('BESIDE')->assertSuccessful();

    $receipts = ExpenseReceipts::where('expense_id', $expense->id)->orderBy('id')->get();
    [$scan, $fromMenards] = [$receipts[0], $receipts[1]];

    expect($receipts)->toHaveCount(2)
        ->and(Expense::withoutGlobalScopes()->where('vendor_id', $menards->id)->count())->toBe(1, 'no second expense')
        ->and($fromMenards->receipt_filename)->toContain('menards-2026-11-10')
        // Menards' three lines carry the items; the scan keeps its file and its handwriting.
        ->and($fromMenards->receipt_items['items'])->toHaveCount(3)
        ->and($fromMenards->isSupplement())->toBeFalse()
        ->and($scan->isSupplement())->toBeTrue()
        ->and($scan->receipt_items['items'])->toBe([])
        ->and($scan->receipt_items['supplanted_items'])->toHaveCount(2)
        ->and($scan->receipt_items['handwritten_notes'])->toBe(['3154', 'R']);
});

it('leaves the line items with the scan when Menards\' copy reads no better', function () {
    [$menards, $company] = menardsVendors();
    Storage::fake('files');
    $items = [['Description' => 'CPT TRIM', 'TotalPrice' => 17.97], ['Description' => 'SHIMS', 'TotalPrice' => 4.68], ['Description' => 'BASE SHOE', 'TotalPrice' => 47.05]];
    $expense = scannedMenardsExpense($menards, $company, ['items' => $items]);

    importMenardsReceipt($this, $menards, $company, ['total' => 76.84, 'subtotal' => 69.70, 'items' => $items])->assertSuccessful();

    $receipts = ExpenseReceipts::where('expense_id', $expense->id)->orderBy('id')->get();

    expect($receipts)->toHaveCount(2)
        ->and($receipts[0]->isSupplement())->toBeFalse()
        ->and($receipts[0]->receipt_items['items'])->toHaveCount(3)
        ->and($receipts[1]->isSupplement())->toBeTrue()
        ->and($receipts[1]->receipt_items['supplement_of_receipt_id'])->toBe($receipts[0]->id);
});

it('still never attaches a second copy from menards.com', function () {
    [$menards, $company] = menardsVendors();
    Storage::fake('files');
    $expense = scannedMenardsExpense($menards, $company, ['items' => []]);
    $fields = ['total' => 76.84, 'subtotal' => 69.70, 'items' => [['Description' => 'CPT TRIM', 'TotalPrice' => 69.70]]];

    importMenardsReceipt($this, $menards, $company, $fields)->assertSuccessful();
    importMenardsReceipt($this, $menards, $company, $fields)->expectsOutputToContain("already has Menards' receipt")->assertSuccessful();

    expect(ExpenseReceipts::where('expense_id', $expense->id)->count())->toBe(2);
});

it('runs the receipt check only outside production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('receipts:tender-check')->expectsOutputToContain('does not run in production')->assertFailed();
});
