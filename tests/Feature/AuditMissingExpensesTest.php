<?php

use App\Livewire\VendorDocs\AuditShow;
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
