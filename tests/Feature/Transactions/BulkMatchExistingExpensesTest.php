<?php

use App\Http\Controllers\TransactionController;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\TransactionBulkMatch;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Expense 26319 (2026-02-02): the $179 Business Prime renewal, imported from
 * Amazon before the import checked amount rules, never picked up its
 * "= $179, B2B Prime → OFFICE" rule — the pass over existing expenses only
 * looked for "B2B Prime" in the expense note, and the words live on the bank
 * charge.
 */
it('applies a rule to existing expenses whose bank charge carries its description, however old', function () {
    $company = Vendor::factory()->create(['business_name' => 'GS Test Co']);
    $amazon = Vendor::factory()->create(['business_name' => 'Amazon', 'business_type' => 'Retail']);
    $office = Distribution::query()->create(['name' => 'OFFICE', 'vendor_id' => $company->id]);
    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $company->id, 'plaid_ins_id' => 'ins_'.uniqid()]);
    $account = BankAccount::create(['vendor_id' => $company->id, 'bank_id' => $bank->id, 'account_number' => '4903', 'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'Checking']);

    TransactionBulkMatch::create([
        'vendor_id' => $amazon->id, 'amount' => 179.00, 'distribution_id' => $office->id,
        'options' => ['amount_type' => '=', 'desc' => 'B2B Prime', 'splits' => []],
        'belongs_to_vendor_id' => $company->id,
    ]);

    $expense = fn (string $date, string $chargeDescription) => tap(Expense::query()->create([
        'amount' => 179.00, 'date' => $date, 'vendor_id' => $amazon->id,
        'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => 0,
    ]), fn (Expense $created) => Transaction::withoutGlobalScopes()->create([
        'bank_account_id' => $account->id, 'vendor_id' => $amazon->id, 'amount' => 179.00,
        'transaction_date' => $date, 'expense_id' => $created->id, 'plaid_merchant_description' => $chargeDescription,
    ]));

    $renewal = $expense('2024-02-02', 'DEBIT PURCHASE Feb 04 4849 B2B Prime*3R64644T3 26036');
    $sameAmountOtherOrder = $expense('2024-03-10', 'DEBIT PURCHASE Mar 11 4849 AMAZON MKTPL*2B4');

    (fn () => $this->updateExistingExpensesWithBulkMatches())->call(app(TransactionController::class));

    expect($renewal->fresh()->distribution_id)->toBe($office->id)
        ->and($sameAmountOtherOrder->fresh()->distribution_id)->toBeNull();
});
