<?php

use App\Livewire\VendorDocs\AuditShow;
use App\Models\AuditRequest;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// =========================================================================
// An expense created from a Missing Expenses row on /audit takes the row out
// of the table right away (Patryk, 2026-10-15): the expense form announces
// the transaction it used, and the audit page reads its list again.
// =========================================================================

function missingExpenseRow(): Transaction
{
    $company = Vendor::factory()->create(['business_type' => 'GC', 'business_name' => 'GS Construction']);
    $user = User::query()->create([
        'first_name' => 'Audit', 'last_name' => 'Admin', 'email' => 'missing-'.uniqid().'@example.test',
        'cell_phone' => '224'.rand(1000000, 9999999), 'primary_vendor_id' => $company->id,
    ]);
    $user->vendors()->attach($company->id, ['role_id' => 1]);
    test()->actingAs($user);
    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $company->id, 'plaid_ins_id' => 'ins_5', 'plaid_item_id' => 'item-test']);
    $account = BankAccount::create(['vendor_id' => $company->id, 'bank_id' => $bank->id, 'account_number' => '4903', 'plaid_account_id' => 'plaid-'.uniqid(), 'type' => 'checking']);

    return Transaction::create([
        'transaction_date' => '2026-01-16', 'posted_date' => '2026-01-16', 'amount' => 200, 'bank_account_id' => $account->id,
        'check_number' => '1010101', 'plaid_merchant_description' => 'ZELLE DEBIT TEST ROW', 'plaid_transaction_id' => uniqid('tx_'),
    ]);
}

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
});

it('takes a Missing Expenses row out of the table once an expense has used its transaction', function () {
    $transaction = missingExpenseRow();

    $page = Livewire::test(AuditShow::class, ['start_date' => '2025-08-26', 'end_date' => '2026-08-26', 'audit_type' => 'general', 'bank_account_ids' => [$transaction->bank_account_id]])
        ->assertSee('ZELLE DEBIT TEST ROW');

    // What saving the expense form does to the transaction.
    $transaction->forceFill(['expense_id' => 999999])->saveQuietly();

    $page->dispatch('transaction-used', transactionId: $transaction->id)
        ->assertDontSee('ZELLE DEBIT TEST ROW');

    expect((new ReflectionMethod(AuditShow::class, 'transactionUsed'))->getAttributes(On::class)[0]->getArguments())->toBe(['transaction-used']);
});

/**
 * Every row of one sheet of an Excel workbook, as plain values.
 *
 * @return list<list<mixed>>
 */
function xlsxSheetRows(string $bytes, string $sheetName): array
{
    $path = tempnam(sys_get_temp_dir(), 'audit-test-').'.xlsx';
    file_put_contents($path, $bytes);
    $reader = new \OpenSpout\Reader\XLSX\Reader;
    $reader->open($path);

    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        if ($sheet->getName() === $sheetName) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
    }
    $reader->close();
    @unlink($path);

    return $rows;
}

// =========================================================================
// The audit's Excel lists the Missing Expenses too, on a sheet of their own
// (Patryk, 2026-10-16), and the page's Export builds the workbook afresh, so
// a row recorded since the package was emailed leaves the sheet.
// =========================================================================

it('lists the Missing Expenses on their own sheet of the audit Excel', function () {
    $transaction = missingExpenseRow();
    $check = Transaction::create([
        'transaction_date' => '2026-03-02', 'posted_date' => '2026-03-02', 'amount' => 13000, 'bank_account_id' => $transaction->bank_account_id,
        'check_number' => '2642', 'plaid_merchant_description' => 'CHECK 2642', 'plaid_transaction_id' => uniqid('tx_'),
    ]);

    $page = Livewire::test(AuditShow::class, ['start_date' => '2025-08-26', 'end_date' => '2026-08-26', 'audit_type' => 'general', 'bank_account_ids' => [$transaction->bank_account_id]]);

    // The package was built and emailed already; its stored workbook is a snapshot.
    Storage::disk('local')->put('audits/emailed.xlsx', 'the emailed snapshot');
    AuditRequest::query()->update(['status' => AuditRequest::STATUS_SENT, 'xlsx_path' => 'audits/emailed.xlsx']);

    $page->call('export_xlsx');

    $rows = xlsxSheetRows(base64_decode(data_get($page->effects, 'download.content')), 'Missing Expenses');

    expect($rows)->toBe([
        ['Date', 'Payment', 'Amount', 'Description'],
        ['01/16/2026', 'Zelle', money(200), 'ZELLE DEBIT TEST ROW'],
        ['03/02/2026', 'Check 2642', money(13000), 'CHECK 2642'],
    ]);

    $check->forceFill(['expense_id' => 999999])->saveQuietly();
    $transaction->forceFill(['check_id' => 999999])->saveQuietly();

    $page->call('export_xlsx');

    expect(xlsxSheetRows(base64_decode(data_get($page->effects, 'download.content')), 'Missing Expenses'))->toBe([
        ['Date', 'Payment', 'Amount', 'Description'],
        ['Every check transaction of the period is in Hive.', '', '', ''],
    ]);
});
