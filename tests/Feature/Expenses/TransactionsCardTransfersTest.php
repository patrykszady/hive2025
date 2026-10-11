<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The Expenses screen's Transactions card is the queue of bank rows still to
 * be accounted for. Transfer-marked rows (check_number 1010101) were hidden
 * wholesale, so a Venmo or Zelle payment to a person that never got a
 * Transfer check was visible nowhere: 65 Venmo debits from Citibank since
 * May 2026, $18,019, 32 of them unclaimed (2026-10-11). Patryk: "we should
 * see them under Transactions unless they have a check / timesheet attached".
 * Transfers between our own accounts (a credit-card payment) stay hidden —
 * their other leg is the record.
 */
function transfersCardFixture(): array
{
    config(['scout.driver' => 'collection']);

    $vendor = Vendor::factory()->create(['business_name' => 'Owner LLC']);
    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $vendor->id, 'plaid_ins_id' => 'ins_5']);
    $account = BankAccount::create([
        'vendor_id' => $vendor->id, 'bank_id' => $bank->id, 'account_number' => '4903',
        'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'checking',
    ]);
    $user = User::query()->create([
        'first_name' => 'Patryk', 'last_name' => 'Szady', 'email' => 'transfers-card-'.uniqid().'@example.test',
        'cell_phone' => '2245550001', 'password' => null, 'primary_vendor_id' => $vendor->id,
    ]);
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);

    $row = fn (array $attributes) => Transaction::create(array_merge([
        'transaction_date' => '2026-10-07', 'posted_date' => '2026-10-09', 'amount' => 1500,
        'bank_account_id' => $account->id, 'plaid_transaction_id' => 'tx_'.uniqid(),
    ], $attributes));

    return [$user, $account, $row];
}

it('tells a payment to a person from a transfer between our own accounts', function (?string $description, ?string $checkNumber, ?string $kind): void {
    expect(Transaction::transferKindFor($description, $checkNumber))->toBe($kind);
})->with([
    'venmo' => ['Venmo', '1010101', 'person'],
    'zelle' => ['ZELLE DEBIT PAY ID CTIxbc1lNvfB ORG ID ALB NAME patryk s', '1010101', 'person'],
    'credit union share transfer to a person' => ['88123 To RACZKA,CEZARY T 4411 Share 1', '1010101', 'person'],
    'paypal' => ['PAYPAL INST XFER', '1010101', 'person'],
    'credit-card payment, bank side' => ['OTHER DECREASE CAPITAL ONE MOBILE 1234', '1010101', 'account'],
    'credit-card payment, card side' => ['CAPITAL ONE MOBILE PYMT', '1010101', 'account'],
    'a lower-case "to" is not a named payee' => ['PAYMENT TO CHECKING, ONLINE', '1010101', 'account'],
    'a real check number' => ['CHECK 1845', '1845', null],
    'an ordinary purchase' => ['HOME DEPOT #1920', null, null],
]);

it('indexes the transfer kind, labels the row, and keeps person transfers inside the card filter', function (): void {
    [, $account, $row] = transfersCardFixture();
    $venmo = $row(['check_number' => '1010101', 'plaid_merchant_description' => 'Venmo']);
    $cardPayment = $row(['check_number' => '1010101', 'plaid_merchant_description' => 'OTHER DECREASE CAPITAL ONE MOBILE 5566', 'amount' => 2500]);
    $purchase = $row(['plaid_merchant_description' => 'HOME DEPOT #1920', 'amount' => 84.12]);

    expect($venmo->toSearchableArray()['transfer_kind'])->toBe('person')
        ->and($cardPayment->toSearchableArray()['transfer_kind'])->toBe('account')
        ->and($purchase->toSearchableArray()['transfer_kind'])->toBeNull()
        ->and($venmo->transferLabel())->toBe('Venmo')
        ->and($cardPayment->transferLabel())->toBe('Transfer')
        ->and($purchase->transferLabel())->toBeNull();

    $filters = Transaction::unmatchedSearchFilters([$account->id]);

    // Still nothing with an expense, a check or a deposit; a transfer only
    // when it paid a person.
    expect($filters)->toContain('check_id IS NULL', 'expense_id IS NULL', "bank_account_id IN [{$account->id}]")
        ->and(implode(' AND ', $filters))->toContain('(check_number IS NULL OR check_number != "1010101" OR transfer_kind = "person")')
        ->and(config('scout.meilisearch.index-settings')[Transaction::class]['filterableAttributes'])->toContain('transfer_kind');
});
