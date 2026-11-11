<?php

use App\Http\Controllers\TransactionController;
use App\Models\Expense;
use App\Models\VendorTransaction;
use App\Support\Transactions\MerchantIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../Support/processor-fixtures.php';

uses(RefreshDatabase::class);

/**
 * Regression (2026-11-10): vendor_transactions rule 280 — vendor 686 "Laravel
 * Daily", desc "PADDLE.NET", written 2023-09-25 when Laravel Daily's charge
 * read just that — claimed two DataForSEO charges (30216 "PADDLE.NET*
 * DATAFORSEO" and 29633 "DEBIT PURCHASE Sep 04 4849 PADDLE.NET* DATAFORSE
 * 26248"), because PART 3 of add_vendor_to_transactions() applies a rule as
 * an unanchored regex and PART 2 then keeps any assignment an alias backs.
 * Paddle is a payment processor many companies bill through; the merchant
 * is the text after "PADDLE.NET*". The sync now reads processors from the
 * descriptor prefix and from Plaid's payload (App\Support\Transactions\
 * MerchantIdentity), matches on the merchant behind them, never through a
 * processor's name, lets a processor-only rule claim only a bare processor
 * charge, and clears expense-less charges such a rule claimed.
 */
function syncVendors(): void
{
    app(TransactionController::class)->add_vendor_to_transactions();
}

it('reads the processor and the merchant of the three Paddle charges from the descriptor and Plaid payload', function () {
    ['account' => $account] = processorFixtureCompany();

    $full = MerchantIdentity::of(processorFixtureTransaction($account, plaidDataforseoFull()));
    $truncated = MerchantIdentity::of(processorFixtureTransaction($account, plaidDataforseoTruncated()));
    $bare = MerchantIdentity::of(processorFixtureTransaction($account, plaidLaravelDailyBare()));

    expect($full->processors)->toBe(['Paddle'])
        // Plaid's merchant counterparty leads; the descriptor's own text is the same name.
        ->and($full->merchant())->toBe('Dataforseo')
        ->and($full->texts())->toBe(['Dataforseo'])
        ->and($full->matchText)->toBe('DATAFORSE')
        // Plaid called Paddle the merchant of 29633: a processor, not a merchant.
        ->and($truncated->processors)->toBe(['Paddle'])
        ->and($truncated->merchant())->toBe('DATAFORSE')
        ->and($truncated->texts())->toBe(['DATAFORSE', 'DATAFORSE New York NY'])
        ->and($truncated->isProcessorName('Paddle'))->toBeTrue()
        ->and($truncated->matchText)->toBe('DATAFORSE')
        // The bare 2023 charge names nobody but processors: Paddle in the descriptor, PayPal in payment_meta.
        ->and($bare->processors)->toBe(['Paddle', 'PayPal'])
        ->and($bare->namesMerchant())->toBeFalse()
        ->and($bare->matchText)->toBeNull();
});

it('lets rule PADDLE.NET claim only the bare Paddle charge, not the DataForSEO charges', function () {
    ['account' => $account] = processorFixtureCompany();
    $laravelDaily = processorFixtureRetailVendor('Laravel Daily');
    paddleRule280($laravelDaily);

    $full = processorFixtureTransaction($account, plaidDataforseoFull());
    $truncated = processorFixtureTransaction($account, plaidDataforseoTruncated());
    $bare = processorFixtureTransaction($account, plaidLaravelDailyBare());

    syncVendors();

    expect($full->refresh()->vendor_id)->toBeNull()
        ->and($truncated->refresh()->vendor_id)->toBeNull()
        ->and($bare->refresh()->vendor_id)->toBe($laravelDaily->id);
});

it('lets a merchant rule claim both DataForSEO forms while rule 280 keeps the bare charge', function (string $rule) {
    ['account' => $account] = processorFixtureCompany();
    $laravelDaily = processorFixtureRetailVendor('Laravel Daily');
    $dataforseo = processorFixtureRetailVendor('DataForSEO');
    paddleRule280($laravelDaily);
    VendorTransaction::create(['vendor_id' => $dataforseo->id, 'deposit_check' => null, 'desc' => $rule, 'options' => json_encode('/i')]);

    $full = processorFixtureTransaction($account, plaidDataforseoFull());
    $truncated = processorFixtureTransaction($account, plaidDataforseoTruncated());
    $bare = processorFixtureTransaction($account, plaidLaravelDailyBare());

    syncVendors();

    expect($full->refresh()->vendor_id)->toBe($dataforseo->id)
        ->and($truncated->refresh()->vendor_id)->toBe($dataforseo->id)
        ->and($bare->refresh()->vendor_id)->toBe($laravelDaily->id);
})->with([
    'the prefilled prefix' => 'DATAFORSE',
    'the processor with the merchant after it' => 'PADDLE\.NET\* ?DATAFORSE',
]);

it('never fuzzy-matches a vendor through the processor name Plaid reports as the merchant', function () {
    ['account' => $account] = processorFixtureCompany();
    processorFixtureRetailVendor('Paddle');
    processorFixtureRetailVendor('Laravel Daily');

    $truncated = processorFixtureTransaction($account, plaidDataforseoTruncated());
    $bare = processorFixtureTransaction($account, plaidLaravelDailyBare());

    syncVendors();

    expect($truncated->refresh()->vendor_id)->toBeNull()
        ->and($bare->refresh()->vendor_id)->toBeNull();
});

it('clears the expense-less charges rule 280 claimed and leaves the 2023 one with its expense alone', function () {
    ['account' => $account, 'company' => $company, 'user' => $user] = processorFixtureCompany();
    $laravelDaily = processorFixtureRetailVendor('Laravel Daily');
    $rule = paddleRule280($laravelDaily);

    $full = processorFixtureTransaction($account, plaidDataforseoFull(), ['vendor_id' => $laravelDaily->id]);
    $truncated = processorFixtureTransaction($account, plaidDataforseoTruncated(), ['vendor_id' => $laravelDaily->id]);

    $bare = processorFixtureTransaction($account, plaidLaravelDailyBare(), ['vendor_id' => $laravelDaily->id]);
    $expense = Expense::withoutGlobalScopes()->create([
        'amount' => -129.00, 'date' => '2023-09-04', 'vendor_id' => $laravelDaily->id,
        'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => $user->id,
    ]);
    $bare->forceFill(['expense_id' => $expense->id])->save();

    // A DataForSEO charge already booked as an expense is wrong too, but never touched by the sync.
    $booked = processorFixtureTransaction($account, plaidDataforseoFull(), ['vendor_id' => $laravelDaily->id]);
    $bookedExpense = Expense::withoutGlobalScopes()->create([
        'amount' => -50.00, 'date' => '2026-11-02', 'vendor_id' => $laravelDaily->id,
        'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => $user->id,
    ]);
    $booked->forceFill(['expense_id' => $bookedExpense->id])->save();

    $cleared = collect();
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('info')->andReturnUsing(function ($message, $context = []) use ($cleared) {
        if ($message === 'Cleared vendor claimed through a payment processor') {
            $cleared->push($context);
        }
    });
    Log::shouldReceive('debug')->andReturnNull();
    Log::shouldReceive('warning')->andReturnNull();
    Log::shouldReceive('error')->andReturnNull();

    syncVendors();

    expect($full->refresh()->vendor_id)->toBeNull()
        ->and($truncated->refresh()->vendor_id)->toBeNull()
        ->and($bare->refresh()->vendor_id)->toBe($laravelDaily->id)
        ->and($booked->refresh()->vendor_id)->toBe($laravelDaily->id)
        ->and($rule->fresh())->not->toBeNull()
        ->and($cleared->pluck('transaction_id')->all())->toEqualCanonicalizing([$full->id, $truncated->id])
        ->and($cleared->first()['rule_ids'])->toBe([$rule->id])
        ->and($cleared->first()['old_vendor_name'])->toBe('Laravel Daily');

    // The next run leaves them unmatched rather than claiming them back.
    syncVendors();

    expect($full->refresh()->vendor_id)->toBeNull()
        ->and($truncated->refresh()->vendor_id)->toBeNull();
});

it('leaves a hand assignment no rule backs alone, and one a merchant rule backs', function () {
    ['account' => $account] = processorFixtureCompany();
    $laravelDaily = processorFixtureRetailVendor('Laravel Daily');
    $dataforseo = processorFixtureRetailVendor('DataForSEO');
    paddleRule280($laravelDaily);
    VendorTransaction::create(['vendor_id' => $laravelDaily->id, 'deposit_check' => null, 'desc' => 'DATAFORSE', 'options' => json_encode('/i')]);

    // Someone decided, by hand, that the full charge is DataForSEO: no rule, no clearing.
    $byHand = processorFixtureTransaction($account, plaidDataforseoFull(), ['vendor_id' => $dataforseo->id]);
    // And a merchant-specific rule, even a wrong one, is a human's explicit mapping: left alone.
    $byMerchantRule = processorFixtureTransaction($account, plaidDataforseoTruncated(), ['vendor_id' => $laravelDaily->id]);

    syncVendors();

    expect($byHand->refresh()->vendor_id)->toBe($dataforseo->id)
        ->and($byMerchantRule->refresh()->vendor_id)->toBe($laravelDaily->id);
});

it('reads a processor only Plaid names and matches the merchant behind it, never the processor', function () {
    ['account' => $account] = processorFixtureCompany();
    processorFixtureRetailVendor('PayPal');
    $github = processorFixtureRetailVendor('Github');

    $charge = processorFixtureTransaction($account, plaidPayPalOnly());
    $identity = MerchantIdentity::of($charge);

    expect($identity->processors)->toBe(['PayPal'])
        ->and($identity->merchant())->toBe('GitHub')
        ->and($identity->isProcessorOnlyRule('PAYPAL'))->toBeTrue();

    // Plaid labelling the app as the merchant too never turns it into the vendor.
    $mislabeled = processorFixtureTransaction($account, plaidPayPalOnly([
        'merchant_name' => 'PayPal',
        'counterparties' => [['name' => 'PayPal', 'type' => 'payment_app', 'website' => 'paypal.com']],
    ]));

    expect(MerchantIdentity::of($mislabeled)->texts())->toBe(['GITHUB INC']);

    syncVendors();

    expect($charge->refresh()->vendor_id)->toBe($github->id)
        ->and($mislabeled->refresh()->vendor_id)->toBe($github->id);
});

it('clears a charge claimed through a processor that only Plaid names and does not claim it back', function () {
    ['account' => $account] = processorFixtureCompany();
    $braintree = processorFixtureRetailVendor('Braintree Direct');
    VendorTransaction::create(['vendor_id' => $braintree->id, 'deposit_check' => null, 'desc' => 'BRAINTREE', 'options' => json_encode('/i')]);

    $charge = processorFixtureTransaction($account, plaidBraintreeAcme(), ['vendor_id' => $braintree->id]);
    $identity = MerchantIdentity::of($charge);

    expect($identity->processors)->toBe(['Braintree'])
        ->and($identity->texts())->toBe(['Acme Tools', 'ACME TOOLS 1234'])
        ->and($identity->isProcessorOnlyRule('BRAINTREE'))->toBeTrue();

    syncVendors();
    syncVendors();

    expect($charge->refresh()->vendor_id)->toBeNull();
});

it('treats a processor billing for itself as the merchant', function () {
    ['account' => $account] = processorFixtureCompany();
    $microsoft = processorFixtureRetailVendor('Microsoft');

    $charge = processorFixtureTransaction($account, plaidSelfBilledMicrosoft());

    expect(MerchantIdentity::of($charge)->hasProcessor())->toBeFalse();

    syncVendors();
    syncVendors();

    expect($charge->refresh()->vendor_id)->toBe($microsoft->id);
});
