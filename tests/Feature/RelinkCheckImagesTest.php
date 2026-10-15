<?php

use App\Models\BankAccount;
use App\Models\Check;
use App\Models\CheckImage;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// =========================================================================
// On prod every matched check image had lost check_id and transaction_id
// while keeping its match in match_details (2026-10-15), so no scan showed
// on a check page. cu:relink-check-images puts the links back.
// =========================================================================

function relinkImage(array $attributes): CheckImage
{
    return CheckImage::create($attributes + [
        'image_filename' => 'img-'.uniqid().'.png',
        'belongs_to_vendor_id' => 1,
        'check_number' => 2470,
        'amount' => 2700,
    ]);
}

it('restores the check and transaction a matched image recorded, and leaves the rest alone', function () {
    $company = Vendor::factory()->create();
    $account = BankAccount::create(['vendor_id' => $company->id, 'bank_id' => 1, 'account_number' => '4903', 'plaid_account_id' => 'plaid-'.uniqid(), 'type' => 'checking']);
    $check = Check::create(['check_type' => 'Check', 'check_number' => 2470, 'date' => '2025-08-29', 'amount' => 2700, 'bank_account_id' => $account->id, 'vendor_id' => $company->id, 'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => 1]);
    $deleted = Check::create(['check_type' => 'Check', 'check_number' => 2471, 'date' => '2025-08-30', 'amount' => 100, 'bank_account_id' => $account->id, 'vendor_id' => $company->id, 'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => 1]);
    $deleted->delete();
    $transaction = Transaction::create(['transaction_date' => '2025-09-02', 'posted_date' => '2025-09-02', 'amount' => 2700, 'bank_account_id' => $account->id, 'plaid_merchant_description' => 'CHECK 2470', 'check_number' => '2470', 'plaid_transaction_id' => uniqid('tx_')]);

    $lost = relinkImage(['match_status' => CheckImage::STATUS_MATCHED_CHECK, 'match_details' => ['check_id' => $check->id, 'transaction_id' => $transaction->id]]);
    $toDeleted = relinkImage(['match_status' => CheckImage::STATUS_MATCHED_CHECK, 'match_details' => ['check_id' => $deleted->id]]);
    $transactionOnly = relinkImage(['match_status' => CheckImage::STATUS_MATCHED_TRANSACTION, 'match_details' => ['check_id' => null, 'transaction_id' => $transaction->id]]);
    $pending = relinkImage(['match_status' => CheckImage::STATUS_PENDING, 'match_details' => ['check_id' => $check->id]]);

    $this->artisan('cu:relink-check-images', ['--dry-run' => true])->expectsOutputToContain('[dry run] Restored 2 image link(s); skipped 1.')->assertSuccessful();
    expect($lost->fresh()->check_id)->toBeNull();

    $this->artisan('cu:relink-check-images')->expectsOutputToContain('Restored 2 image link(s); skipped 1.')->assertSuccessful();

    expect($lost->fresh())->toMatchArray(['check_id' => $check->id, 'transaction_id' => $transaction->id])
        ->and($toDeleted->fresh()->check_id)->toBeNull()
        ->and($transactionOnly->fresh())->toMatchArray(['check_id' => null, 'transaction_id' => $transaction->id])
        ->and($pending->fresh()->check_id)->toBeNull()
        ->and($check->checkImages()->pluck('id')->all())->toBe([$lost->id]);

    // Run again: nothing left to restore.
    $this->artisan('cu:relink-check-images')->expectsOutputToContain('Restored 0 image link(s); skipped 1.')->assertSuccessful();
});
