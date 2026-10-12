<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Plaid guesses a merchant even for a payment-app transfer: transaction 29182
 * ($200 Venmo to a person, 2026-07-12) came back as "Anthropic Headquarters"
 * with counterparties [Venmo: payment_app] and no merchant, showed that name
 * on every screen, and was grouped as a business payment by the check
 * matcher. The sync drops such a guess; the command cleans existing rows.
 */
it('keeps a real merchant and drops a guess on a payment-app transfer', function (array $payload, ?string $expected): void {
    expect(Transaction::merchantNameFromPlaid($payload))->toBe($expected);
})->with([
    'venmo to a person, guessed merchant' => [[
        'merchant_name' => 'Anthropic Headquarters', 'name' => 'Venmo',
        'original_description' => 'DEBIT PURCHASE Jul 12 4849 VENMO *PATRYK SZADY 8558124430 NY 26195',
        'counterparties' => [['name' => 'Venmo', 'type' => 'payment_app', 'confidence_level' => 'VERY_HIGH']],
    ], null],
    'netflix paid through paypal keeps its merchant' => [[
        'merchant_name' => 'Netflix', 'name' => 'PAYPAL *NETFLIX',
        'counterparties' => [['name' => 'PayPal', 'type' => 'payment_app'], ['name' => 'Netflix', 'type' => 'merchant']],
    ], 'Netflix'],
    'no counterparty data keeps the merchant' => [[
        'merchant_name' => 'Ssemblyai', 'name' => 'Venmo', 'original_description' => 'DEBIT PURCHASE Jun 26 4849 VENMO *PATRYK SZADY 8558124430 NY',
    ], 'Ssemblyai'],
    'payment app in the counterparties but no app in the memo keeps the merchant' => [[
        'merchant_name' => 'The Home Depot', 'name' => 'THE HOME DEPOT #1902 EVANSTON IL',
        'counterparties' => [['name' => 'Zelle', 'type' => 'payment_app']],
    ], 'The Home Depot'],
    'the app itself is not a guess' => [[
        'merchant_name' => 'Venmo', 'name' => 'Venmo',
        'counterparties' => [['name' => 'Venmo', 'type' => 'payment_app']],
    ], 'Venmo'],
    'no merchant at all' => [['name' => 'Venmo'], null],
]);

it('clears guessed merchant names on existing rows, dry-run first', function (): void {
    config(['scout.driver' => 'collection']);
    $vendor = Vendor::factory()->create();
    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $vendor->id, 'plaid_ins_id' => 'ins_5']);
    $account = BankAccount::create(['vendor_id' => $vendor->id, 'bank_id' => $bank->id, 'account_number' => '4903', 'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'checking']);
    $row = fn (array $attributes) => Transaction::create(array_merge([
        'transaction_date' => '2026-07-12', 'amount' => 200, 'bank_account_id' => $account->id, 'check_number' => '1010101',
        'plaid_transaction_id' => 'tx_'.uniqid(),
    ], $attributes));

    $guess = $row(['plaid_merchant_name' => 'Anthropic Headquarters', 'plaid_merchant_description' => 'Venmo', 'details' => [
        'original_description' => 'DEBIT PURCHASE Jul 12 4849 VENMO *PATRYK SZADY 8558124430 NY 26195',
        'counterparties' => [['name' => 'Venmo', 'type' => 'payment_app', 'confidence_level' => 'VERY_HIGH']],
    ]]);
    $netflix = $row(['plaid_merchant_name' => 'Netflix', 'plaid_merchant_description' => 'PAYPAL *NETFLIX', 'check_number' => null, 'details' => [
        'counterparties' => [['name' => 'PayPal', 'type' => 'payment_app'], ['name' => 'Netflix', 'type' => 'merchant']],
    ]]);
    $plain = $row(['plaid_merchant_name' => null, 'plaid_merchant_description' => 'Venmo', 'details' => [
        'counterparties' => [['name' => 'Venmo', 'type' => 'payment_app']],
    ]]);

    $this->artisan('transactions:clear-merchant-guesses')
        ->expectsOutputToContain('Dry-run only: 1 row(s)')
        ->assertSuccessful();
    expect($guess->refresh()->plaid_merchant_name)->toBe('Anthropic Headquarters');

    $this->artisan('transactions:clear-merchant-guesses', ['--apply' => true])
        ->expectsOutputToContain('Cleared the guessed merchant name on 1 row(s)')
        ->assertSuccessful();

    expect($guess->refresh()->plaid_merchant_name)->toBeNull()
        ->and($netflix->refresh()->plaid_merchant_name)->toBe('Netflix')
        ->and($plain->refresh()->plaid_merchant_name)->toBeNull()
        ->and($guess->merchantNameIsPaymentAppGuess())->toBeFalse();

    $this->artisan('transactions:clear-merchant-guesses', ['--apply' => true])
        ->expectsOutputToContain('No merchant guesses')
        ->assertSuccessful();
});
