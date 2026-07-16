<?php

use App\Http\Controllers\TransactionController;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\VendorTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Generic same-named merchants ("SMOKE N VAPE", "Chinese Food") can only be
 * told apart by where the charge happened. Vendors with a pinned location
 * (street address + zip) hard-gate matching on the transaction's Plaid
 * location; soft city/state (backfilled) only aids positive disambiguation.
 */
function locationFixture(): BankAccount
{
    $owner = Vendor::factory()->create([
        'business_name' => 'Fixture Holding Co',
        'business_type' => 'Sub',
    ]);

    $bank = Bank::create([
        'name' => 'Test Bank',
        'vendor_id' => $owner->id,
        'plaid_ins_id' => 'ins_test',
    ]);

    return BankAccount::create([
        'vendor_id' => $owner->id,
        'bank_id' => $bank->id,
        'account_number' => '1234',
        'plaid_account_id' => 'acc_test_'.uniqid(),
        'type' => 'checking',
    ]);
}

function vapeVendor(array $attributes = []): Vendor
{
    return Vendor::factory()->create(array_merge([
        // Not "Retail" so PART 1/PART 2 fuzzy matching (Retail-only) stays out
        // of the way — these tests isolate the PART 3 alias loop. The factory
        // fills an address by default, which would pin every vendor.
        'business_type' => 'Sub',
        'business_name' => 'Corner Store MP',
        'address' => null,
        'address_2' => null,
        'city' => null,
        'state' => null,
        'zip_code' => null,
    ], $attributes));
}

function vapeTransaction(BankAccount $account, ?array $location, float $amount = 33.08): Transaction
{
    return Transaction::create([
        'transaction_date' => now()->subDays(2),
        'amount' => $amount,
        'bank_account_id' => $account->id,
        'plaid_merchant_name' => 'Smoke N Vape',
        'plaid_merchant_description' => 'SMOKE N VAPE',
        'details' => $location === null ? ['location' => [
            'city' => null, 'region' => null, 'postal_code' => null,
            'address' => null, 'lat' => null, 'lon' => null,
        ]] : ['location' => $location],
    ]);
}

function vapeAlias(Vendor $vendor): VendorTransaction
{
    return VendorTransaction::create([
        'vendor_id' => $vendor->id,
        'deposit_check' => null,
        'amount_sign' => null,
        'plaid_inst_id' => null,
        'desc' => 'SMOKE N VAPE',
        'options' => json_encode('/i'),
    ]);
}

it('resolves plaid location and null when the bank stripped it', function () {
    $account = locationFixture();

    $located = vapeTransaction($account, ['city' => 'Mount Prospect', 'region' => 'IL', 'postal_code' => '60056-1234']);
    $stripped = vapeTransaction($account, null);

    expect($located->plaidLocation())->toBe(['city' => 'Mount Prospect', 'region' => 'IL', 'postal_code' => '60056'])
        ->and($stripped->plaidLocation())->toBeNull();
});

it('compares transaction location against vendor address as a tri-state', function () {
    $account = locationFixture();
    $vendor = vapeVendor(['address' => '1522 N Elmhurst Rd', 'city' => 'Mount Prospect', 'state' => 'IL', 'zip_code' => '60056']);

    // zip wins when both sides have one
    expect(vapeTransaction($account, ['postal_code' => '60056'])->locationAgreementWithVendor($vendor))->toBeTrue()
        ->and(vapeTransaction($account, ['postal_code' => '60120'])->locationAgreementWithVendor($vendor))->toBeFalse()
        // bank-truncated city still agrees ("Mount" for Mount Prospect)
        ->and(vapeTransaction($account, ['city' => 'Mount', 'region' => 'IL'])->locationAgreementWithVendor($vendor))->toBeTrue()
        ->and(vapeTransaction($account, ['city' => 'Elgin', 'region' => 'IL'])->locationAgreementWithVendor($vendor))->toBeFalse()
        // same city name in a different state is a different place
        ->and(vapeTransaction($account, ['city' => 'Mount Prospect', 'region' => 'PA'])->locationAgreementWithVendor($vendor))->toBeFalse()
        // no location on the transaction -> not comparable
        ->and(vapeTransaction($account, null)->locationAgreementWithVendor($vendor))->toBeNull();

    // a full state name on the vendor must not read as conflict
    $spelledOut = vapeVendor(['business_name' => 'Spelled Out Corp', 'city' => 'Mount Prospect', 'state' => 'Illinois']);
    expect(vapeTransaction($account, ['city' => 'Mount Prospect', 'region' => 'IL'])->locationAgreementWithVendor($spelledOut))->toBeTrue();
});

it('pins a location only when street address and zip are both present', function () {
    expect(vapeVendor(['address' => '1522 N Elmhurst Rd', 'zip_code' => '60056'])->hasPinnedLocation())->toBeTrue()
        ->and(vapeVendor(['business_name' => 'City Only', 'city' => 'Chicago', 'state' => 'IL'])->hasPinnedLocation())->toBeFalse()
        ->and(vapeVendor(['business_name' => 'Zip Only', 'zip_code' => '60056'])->hasPinnedLocation())->toBeFalse();
});

it('assigns a pinned vendor alias when the transaction has no location', function () {
    $account = locationFixture();
    $vendor = vapeVendor(['address' => '1522 N Elmhurst Rd', 'city' => 'Mount Prospect', 'state' => 'IL', 'zip_code' => '60056']);
    vapeAlias($vendor);

    $transaction = vapeTransaction($account, null);

    app(TransactionController::class)->add_vendor_to_transactions();

    expect($transaction->refresh()->vendor_id)->toBe($vendor->id);
});

it('refuses a pinned vendor alias when the charge happened somewhere else', function () {
    $account = locationFixture();
    $vendor = vapeVendor(['address' => '1522 N Elmhurst Rd', 'city' => 'Mount Prospect', 'state' => 'IL', 'zip_code' => '60056']);
    vapeAlias($vendor);

    $transaction = vapeTransaction($account, ['city' => 'Elgin', 'region' => 'IL', 'postal_code' => '60120']);

    app(TransactionController::class)->add_vendor_to_transactions();

    expect($transaction->refresh()->vendor_id)->toBeNull();
});

it('never vetoes on a soft location (city/state without street address)', function () {
    $account = locationFixture();
    $vendor = vapeVendor(['city' => 'Mount Prospect', 'state' => 'IL']);
    vapeAlias($vendor);

    $transaction = vapeTransaction($account, ['city' => 'Elgin', 'region' => 'IL']);

    app(TransactionController::class)->add_vendor_to_transactions();

    expect($transaction->refresh()->vendor_id)->toBe($vendor->id);
});

it('disambiguates a generic descriptor between two vendors by location', function () {
    $account = locationFixture();
    $mountProspect = vapeVendor(['business_name' => 'Smoke N Vape MP', 'address' => '1522 N Elmhurst Rd', 'city' => 'Mount Prospect', 'state' => 'IL', 'zip_code' => '60056']);
    $elgin = vapeVendor(['business_name' => 'Smoke N Vape Elgin', 'address' => '10 Main St', 'city' => 'Elgin', 'state' => 'IL', 'zip_code' => '60120']);
    vapeAlias($mountProspect);
    vapeAlias($elgin);

    $transaction = vapeTransaction($account, ['city' => 'Elgin', 'region' => 'IL']);

    app(TransactionController::class)->add_vendor_to_transactions();

    expect($transaction->refresh()->vendor_id)->toBe($elgin->id);
});

it('leaves an ambiguous generic descriptor unmatched when nothing can pick a winner', function () {
    $account = locationFixture();
    $mountProspect = vapeVendor(['business_name' => 'Smoke N Vape MP', 'address' => '1522 N Elmhurst Rd', 'city' => 'Mount Prospect', 'state' => 'IL', 'zip_code' => '60056']);
    $elgin = vapeVendor(['business_name' => 'Smoke N Vape Elgin', 'address' => '10 Main St', 'city' => 'Elgin', 'state' => 'IL', 'zip_code' => '60120']);
    vapeAlias($mountProspect);
    vapeAlias($elgin);

    $transaction = vapeTransaction($account, null);

    app(TransactionController::class)->add_vendor_to_transactions();

    expect($transaction->refresh()->vendor_id)->toBeNull();
});

it('still respects amount_sign rules in the alias loop', function () {
    $account = locationFixture();
    $vendor = vapeVendor();
    $alias = vapeAlias($vendor);
    $alias->update(['amount_sign' => 2]); // only negative amounts (money in)

    $transaction = vapeTransaction($account, null, 33.08);

    app(TransactionController::class)->add_vendor_to_transactions();

    expect($transaction->refresh()->vendor_id)->toBeNull();
});

it('keeps an alias-backed assignment even when the location conflicts — human decisions win', function () {
    $account = locationFixture();
    $vendor = vapeVendor(['address' => '1522 N Elmhurst Rd', 'city' => 'Mount Prospect', 'state' => 'IL', 'zip_code' => '60056']);
    vapeAlias($vendor);

    // A human explicitly assigned this Elgin charge to the pinned vendor.
    // PART 2 must NOT clear it every 10 minutes — the location gate only
    // blocks new automatic matches, never reverts standing assignments.
    $transaction = vapeTransaction($account, ['city' => 'Elgin', 'region' => 'IL']);
    $transaction->update(['vendor_id' => $vendor->id]);

    app(TransactionController::class)->add_vendor_to_transactions();

    expect($transaction->refresh()->vendor_id)->toBe($vendor->id);
});

it('treats bank-truncated trailing fragments and abbreviations as the same city', function () {
    $account = locationFixture();
    $vendor = vapeVendor(['address' => '1522 N Elmhurst Rd', 'city' => 'Mount Prospect', 'state' => 'IL']);

    // Capital One really sends both "Prospect" (trailing word) and
    // "Mt Prospect" for Mount Prospect charges.
    expect(vapeTransaction($account, ['city' => 'Prospect', 'region' => 'IL'])->locationAgreementWithVendor($vendor))->toBeTrue()
        ->and(vapeTransaction($account, ['city' => 'Mt Prospect', 'region' => 'IL'])->locationAgreementWithVendor($vendor))->toBeTrue()
        ->and(vapeTransaction($account, ['city' => 'Mount', 'region' => 'IL'])->locationAgreementWithVendor($vendor))->toBeTrue();
});

it('keeps the legacy longest-desc winner for nested aliases of unpinned vendors', function () {
    $account = locationFixture();
    $short = vapeVendor(['business_name' => 'Short Alias Vendor']);
    $long = vapeVendor(['business_name' => 'Long Alias Vendor']);

    VendorTransaction::create(['vendor_id' => $short->id, 'desc' => 'SMOKE N', 'options' => json_encode('/i')]);
    vapeAlias($long); // 'SMOKE N VAPE' — longer, must win as before the rewrite

    $transaction = vapeTransaction($account, null);

    app(TransactionController::class)->add_vendor_to_transactions();

    expect($transaction->refresh()->vendor_id)->toBe($long->id);
});

it('never lets a truncated city fragment PICK a winner between colliding vendors', function () {
    $account = locationFixture();
    // Charge really happened in Park Ridge, but Capital One sent just "Park".
    // "Park" suffix-matches "Highland Park" — that leniency must avoid vetoes
    // only, never decide the assignment.
    $highlandPark = vapeVendor(['business_name' => 'Smoke N Vape HP', 'address' => '1 Central Ave', 'city' => 'Highland Park', 'state' => 'IL', 'zip_code' => '60035']);
    $parkRidge = vapeVendor(['business_name' => 'Smoke N Vape PR', 'address' => '2 Main St', 'city' => 'Park Ridge', 'state' => 'IL', 'zip_code' => '60068']);
    vapeAlias($highlandPark);
    vapeAlias($parkRidge);

    $transaction = vapeTransaction($account, ['city' => 'Park', 'region' => 'IL']);

    app(TransactionController::class)->add_vendor_to_transactions();

    expect($transaction->refresh()->vendor_id)->toBeNull();
});

it('pins leading-zero zips despite the int zip_code column', function () {
    $vendor = vapeVendor(['business_name' => 'Hoboken Vendor', 'address' => '1 Washington St', 'zip_code' => '07030']);

    // The int column stores 7030; normalizedZip() restores the leading zero.
    expect($vendor->refresh()->normalizedZip())->toBe('07030')
        ->and($vendor->hasPinnedLocation())->toBeTrue();
});
