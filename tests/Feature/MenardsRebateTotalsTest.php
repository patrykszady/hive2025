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
function rebateReceipt(string $sale, string $rebate, string $tender = ''): string
{
    return "PINE TAPERED SHIMS 4334222 3 @1.56 4.68\nMENARD REBATE NO: 6323495338\n{$rebate}-\nRemaining Balance: \$0.00\nTOTAL\n0.00\nTOTAL SALE\n{$sale}\n{$tender}";
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
    $short = menardsExpense($menards, $company, 173.43, rebateReceipt('173.43', '43.24', "US Debit 4849\n173.43"));
    $wrongSign = menardsExpense($menards, $company, -82.50, rebateReceipt('0.00', '82.50'));
    $fixedByHand = menardsExpense($menards, $company, 216.67, rebateReceipt('173.43', '43.24'));

    $this->artisan('expenses:fix-menards-rebate-totals')->expectsOutputToContain('2 to correct (dry run)')->assertSuccessful();
    expect((float) $short->fresh()->amount)->toBe(173.43);

    $this->artisan('expenses:fix-menards-rebate-totals', ['--commit' => true])->assertSuccessful();

    expect((float) $short->fresh()->amount)->toBe(216.67)
        ->and((float) $wrongSign->fresh()->amount)->toBe(82.50)
        ->and((float) $fixedByHand->fresh()->amount)->toBe(216.67);
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
        ->and(ExpenseReceipts::where('expense_id', $expenses->first()->id)->count())->toBe(1);
});

it('runs the receipt check only outside production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('receipts:tender-check')->expectsOutputToContain('does not run in production')->assertFailed();
});
