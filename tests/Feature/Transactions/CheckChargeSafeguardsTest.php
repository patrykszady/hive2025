<?php

use App\Http\Controllers\PlaidTransactionSyncController;
use App\Http\Controllers\TransactionController;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Support\CheckCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Replays 2026-09-08..10 on Citibank 4903: a $425.16 JC Licht receipt, its
 * pending card charge (correctly linked), then Plaid posting "CHECK 2658" for
 * $3,075 as the "posted version" of that pending purchase.
 *
 * @return array<string, mixed>
 */
function chk_fixture(): array
{
    $company = Vendor::factory()->create(['business_name' => 'GS Test Co']);
    $jcLicht = Vendor::factory()->create(['business_name' => 'JC Licht', 'business_type' => 'Retail']);
    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $company->id, 'plaid_ins_id' => 'ins_chk_'.uniqid()]);
    $account = BankAccount::create(['vendor_id' => $company->id, 'bank_id' => $bank->id, 'account_number' => '4903', 'plaid_account_id' => 'acc_chk_'.uniqid(), 'type' => 'checking']);

    $expense = Expense::query()->create([
        'amount' => 425.16,
        'date' => '2026-09-08',
        'vendor_id' => $jcLicht->id,
        'belongs_to_vendor_id' => $company->id,
        'created_by_user_id' => 0,
    ]);

    return compact('company', 'jcLicht', 'bank', 'account', 'expense');
}

function chk_charge(array $fx, array $attrs): Transaction
{
    return Transaction::withoutGlobalScopes()->create(array_merge([
        'bank_account_id' => $fx['account']->id,
        'transaction_date' => '2026-09-08',
    ], $attrs));
}

it('reads the check number out of bank descriptions, and nothing out of deposits or card purchases', function (string $description, ?string $expected) {
    expect(CheckCharge::numberFrom($description))->toBe($expected);
})->with([
    ['CHECK 2658', '2658'],
    ['CHECK # 2658', '2658'],
    ['check no. 00412', '412'],
    ['Deposit by Check MOBILE CHECK DEPOSIT: Remote Deposit', null],
    ['DEBIT PURCHASE Sep 08 4849 JC LICHT 1261-PROSPEC 26252', null],
    ['CHECKCARD 0908 JC LICHT', null],
]);

it('fills in the check number when the bank left it empty', function () {
    $fx = chk_fixture();

    $charge = chk_charge($fx, ['amount' => 3075.00, 'plaid_merchant_name' => 'Jc Licht', 'plaid_merchant_description' => 'CHECK 2658']);

    expect($charge->fresh()->check_number)->toBe('2658');
});

it('refuses an automatic link from a check charge to an expense', function () {
    $fx = chk_fixture();
    $charge = chk_charge($fx, ['amount' => 3075.00, 'plaid_merchant_description' => 'CHECK 2658']);

    $charge->expense_id = $fx['expense']->id;
    $charge->save();

    expect($charge->fresh()->expense_id)->toBeNull();
});

it('still lets a person link a check charge to an expense by hand', function () {
    $fx = chk_fixture();
    $charge = chk_charge($fx, ['amount' => 3075.00, 'plaid_merchant_description' => 'CHECK 2658']);

    $charge->expense_id = $fx['expense']->id;
    $charge->manualExpenseLink = true;
    $charge->save();

    expect($charge->fresh()->expense_id)->toBe($fx['expense']->id);
});

it('leaves card purchases free to link automatically', function () {
    $fx = chk_fixture();
    $card = chk_charge($fx, ['amount' => 425.16, 'vendor_id' => $fx['jcLicht']->id, 'plaid_merchant_description' => 'DEBIT PURCHASE Sep 08 4849 JC LICHT']);

    $card->expense_id = $fx['expense']->id;
    $card->save();

    expect($card->fresh()->expense_id)->toBe($fx['expense']->id);
});

it('moves a check charge that was linked straight to an expense onto its check', function () {
    $fx = chk_fixture();
    $charge = chk_charge($fx, ['amount' => 3075.00, 'plaid_merchant_description' => 'CHECK 2658']);
    // Written the way the old bug left it: straight onto the receipt expense.
    Transaction::withoutGlobalScopes()->whereKey($charge->id)->update(['expense_id' => $fx['expense']->id]);

    $check = Check::forceCreate([
        'check_type' => 'Check',
        'check_number' => 2658,
        'date' => '2026-09-09',
        'amount' => 3075.00,
        'bank_account_id' => $fx['account']->id,
        'belongs_to_vendor_id' => $fx['company']->id,
        'created_by_user_id' => 0,
    ]);

    app(TransactionController::class)->add_check_id_to_transactions();

    $charge->refresh();
    expect($charge->check_id)->toBe($check->id)
        ->and($charge->expense_id)->toBeNull();
});

it('does not turn a pending card purchase into a check when the bank pairs them', function () {
    $fx = chk_fixture();
    $pending = chk_charge($fx, [
        'amount' => 425.16,
        'vendor_id' => $fx['jcLicht']->id,
        'plaid_merchant_name' => 'Jc Licht',
        'plaid_merchant_description' => 'DEBIT PURCHASE Sep 08 4849 JC LICHT 1261-PROSPEC',
        'plaid_transaction_id' => 'pending-jc-licht',
        'expense_id' => $fx['expense']->id,
    ]);

    $posted = [
        'transaction_id' => 'posted-check-2658',
        'pending_transaction_id' => 'pending-jc-licht',
        'account_id' => $fx['account']->plaid_account_id,
        'amount' => 3075.00,
        'date' => '2026-09-08',
        'name' => 'CHECK 2658',
        'merchant_name' => 'Jc Licht',
        'pending' => false,
    ];

    $sync = app(PlaidTransactionSyncController::class);
    (fn () => $this->processAddedTransaction($fx['bank'], $posted, 'depository', 'test-request'))->call($sync);

    $pending->refresh();
    expect((float) $pending->amount)->toBe(425.16)
        ->and($pending->expense_id)->toBe($fx['expense']->id)
        ->and($pending->plaid_merchant_description)->toContain('JC LICHT');

    $check = Transaction::withoutGlobalScopes()->where('plaid_transaction_id', 'posted-check-2658')->firstOrFail();
    expect((float) $check->amount)->toBe(3075.0)
        ->and($check->check_number)->toBe('2658')
        ->and($check->expense_id)->toBeNull()
        ->and($check->vendor_id)->toBeNull()
        ->and($check->details['pending_transaction_id'] ?? null)->toBeNull()
        ->and($check->details['refused_pending_transaction_id'] ?? null)->toBe('pending-jc-licht');
});

it('lets the stale pending purchase be removed once the check came in on its own', function () {
    $fx = chk_fixture();
    $pending = chk_charge($fx, [
        'amount' => 425.16,
        'vendor_id' => $fx['jcLicht']->id,
        'plaid_merchant_description' => 'DEBIT PURCHASE Sep 08 4849 JC LICHT',
        'plaid_transaction_id' => 'pending-jc-licht',
        'expense_id' => $fx['expense']->id,
    ]);

    $sync = app(PlaidTransactionSyncController::class);
    (fn () => $this->processAddedTransaction($fx['bank'], [
        'transaction_id' => 'posted-check-2658',
        'pending_transaction_id' => 'pending-jc-licht',
        'account_id' => $fx['account']->plaid_account_id,
        'amount' => 3075.00,
        'date' => '2026-09-08',
        'name' => 'CHECK 2658',
        'pending' => false,
    ], 'depository', 'test-request'))->call($sync);

    // Plaid then removes the pending purchase. Nothing posted points at it any
    // more, so it goes, and the receipt expense is free for the real posted
    // $425.16 card charge.
    $removed = (fn () => $this->processRemovedTransaction($fx['bank'], ['transaction_id' => 'pending-jc-licht'], 'depository', 'test-request'))->call($sync);

    expect($removed['status'] ?? null)->toBe('removed_deleted')
        ->and(Transaction::withoutGlobalScopes()->withTrashed()->find($pending->id)->trashed())->toBeTrue()
        ->and(Transaction::withoutGlobalScopes()->where('plaid_transaction_id', 'posted-check-2658')->value('expense_id'))->toBeNull();
});

it('drops the expense link when a pending charge posts for a different amount', function () {
    $fx = chk_fixture();
    $pending = chk_charge($fx, [
        'amount' => 425.16,
        'vendor_id' => $fx['jcLicht']->id,
        'plaid_merchant_description' => 'DEBIT PURCHASE Sep 08 4849 JC LICHT',
        'plaid_transaction_id' => 'pending-tip',
        'expense_id' => $fx['expense']->id,
    ]);

    $posted = [
        'transaction_id' => 'posted-tip',
        'pending_transaction_id' => 'pending-tip',
        'account_id' => $fx['account']->plaid_account_id,
        'amount' => 450.00,
        'date' => '2026-09-08',
        'name' => 'DEBIT PURCHASE Sep 08 4849 JC LICHT',
        'merchant_name' => 'Jc Licht',
        'pending' => false,
    ];

    $sync = app(PlaidTransactionSyncController::class);
    (fn () => $this->processAddedTransaction($fx['bank'], $posted, 'depository', 'test-request'))->call($sync);

    $pending->refresh();
    expect((float) $pending->amount)->toBe(450.0)
        ->and($pending->plaid_transaction_id)->toBe('posted-tip')
        ->and($pending->expense_id)->toBeNull();
});
