<?php

use App\Http\Controllers\PlaidTransactionSyncController;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Plaid sometimes names a pending card charge as the pending side of a
 * different posted charge. 2026-09-01 on Citibank 4903: a pending $14.99
 * Prime Video charge (linked to its Amazon receipt) "posted" as a $15.00
 * INCOMING WIRE FEE and kept the Amazon vendor and the link; the real Prime
 * Video charge then sat unlinked. An upgrade that changes the merchant now
 * drops the vendor and the expense link so matching runs on what posted.
 */
function upg_fixture(): array
{
    $company = Vendor::factory()->create(['business_name' => 'GS Test Co']);
    $amazon = Vendor::factory()->create(['business_name' => 'Amazon', 'business_type' => 'Retail']);
    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $company->id, 'plaid_ins_id' => 'ins_upg_'.uniqid()]);
    $account = BankAccount::create(['vendor_id' => $company->id, 'bank_id' => $bank->id, 'account_number' => '4903', 'plaid_account_id' => 'acc_upg_'.uniqid(), 'type' => 'checking']);
    $expense = Expense::query()->create(['amount' => 14.99, 'date' => '2026-08-28', 'vendor_id' => $amazon->id, 'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => 0]);

    return compact('company', 'amazon', 'bank', 'account', 'expense');
}

function upg_pending(array $fx, string $description, float $amount = 14.99, ?string $merchantName = null): Transaction
{
    return Transaction::withoutGlobalScopes()->create([
        'bank_account_id' => $fx['account']->id,
        'transaction_date' => '2026-08-28',
        'amount' => $amount,
        'vendor_id' => $fx['amazon']->id,
        'plaid_merchant_description' => $description,
        'plaid_merchant_name' => $merchantName,
        'plaid_transaction_id' => 'pending-'.uniqid(),
        'expense_id' => $fx['expense']->id,
    ]);
}

function upg_post(array $fx, Transaction $pending, string $name, float $amount, ?string $merchantName = null): Transaction
{
    $sync = app(PlaidTransactionSyncController::class);
    (fn () => $this->processAddedTransaction($fx['bank'], [
        'transaction_id' => 'posted-'.uniqid(),
        'pending_transaction_id' => $pending->plaid_transaction_id,
        'account_id' => $fx['account']->plaid_account_id,
        'amount' => $amount,
        'date' => '2026-08-28',
        'name' => $name,
        'merchant_name' => $merchantName,
        'pending' => false,
    ], 'depository', 'test-request'))->call($sync);

    return $pending->refresh();
}

it('drops the vendor and the link when a pending purchase posts as a different merchant', function () {
    $fx = upg_fixture();
    $pending = upg_pending($fx, 'DEBIT PURCHASE Aug 28 4849 Prime Video *5Q68Z0LV');

    $posted = upg_post($fx, $pending, 'ACH Electronic Debit INCOMING WIRE FEE 083126', 15.00);

    expect($posted->plaid_merchant_description)->toBe('ACH Electronic Debit INCOMING WIRE FEE 083126')
        ->and($posted->expense_id)->toBeNull()
        ->and($posted->vendor_id)->toBeNull();
});

it('drops them even when the other merchant charged the same amount', function () {
    $fx = upg_fixture();
    $pending = upg_pending($fx, 'DEBIT PURCHASE Sep 09 4849 Amazon.com*537ZA8WS2', 41.38);

    $posted = upg_post($fx, $pending, 'Lyft', 41.38);

    expect($posted->expense_id)->toBeNull()->and($posted->vendor_id)->toBeNull();
});

it('keeps the vendor and the link when the same merchant posts', function (string $before, string $after, ?string $merchantBefore, ?string $merchantAfter) {
    $fx = upg_fixture();
    $pending = upg_pending($fx, $before, merchantName: $merchantBefore);

    $posted = upg_post($fx, $pending, $after, 14.99, $merchantAfter);

    expect($posted->expense_id)->toBe($fx['expense']->id)
        ->and($posted->vendor_id)->toBe($fx['amazon']->id);
})->with([
    'same words, card-network noise aside' => ['Amazon Prime Video', 'DEBIT PURCHASE Aug 28 4849 Prime Video *5Q68Z0LV', null, null],
    'Plaid names the same merchant' => ['Amazon', 'AMZN Mktp US*2K4', 'Amazon', 'Amazon'],
]);
