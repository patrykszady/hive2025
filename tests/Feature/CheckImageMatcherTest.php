<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\CheckImage;
use App\Models\Transaction;
use App\Services\CheckImageMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function matcherBankAccount(int $last4 = 4903, ?int $bankId = null): BankAccount
{
    $bank = $bankId ? Bank::find($bankId) : Bank::create([
        'name'               => 'Citibank',
        'vendor_id'          => 1,
        'plaid_ins_id'       => 'ins_5',
        'plaid_access_token' => 'test-token',
        'plaid_item_id'      => 'test-item-' . uniqid(),
    ]);

    return BankAccount::create([
        'vendor_id'        => 1,
        'bank_id'          => $bank->id,
        'account_number'   => $last4,
        'type'             => 'Checking',
        'plaid_account_id' => 'test-account-' . uniqid(),
    ]);
}

function matcherCheck(array $attributes): Check
{
    return Check::create(array_merge(['created_by_user_id' => 0], $attributes));
}

function matcherImage(array $overrides = []): CheckImage
{
    return CheckImage::create(array_merge([
        'image_filename' => '0800854903_' . ($overrides['check_number'] ?? 1001) . '_test_' . uniqid() . '.png',
        'check_number'   => 1001,
        'amount'         => 500.00,
        'check_date'     => '2026-06-15',
        'account_number' => '0800854903',
    ], $overrides));
}

it('links an image to the unique check matching number, amount, and account', function (): void {
    $account = matcherBankAccount();

    $check = matcherCheck([
        'check_type'           => 'Check',
        'check_number'         => 1001,
        'date'                 => '2026-06-10',
        'amount'               => 500.00,
        'bank_account_id'      => $account->id,
        'belongs_to_vendor_id' => 1,
    ]);

    $image  = matcherImage();
    $result = app(CheckImageMatcher::class)->match($image);

    expect($result['status'])->toBe(CheckImage::STATUS_MATCHED_CHECK)
        ->and($image->fresh()->check_id)->toBe($check->id)
        ->and($image->fresh()->bank_account_id)->toBe($account->id)
        ->and($image->fresh()->belongs_to_vendor_id)->toBe(1);
});

it('ignores non-Check types and returned checks sharing the number', function (): void {
    $account = matcherBankAccount();

    matcherCheck([
        'check_type'           => 'Transfer',
        'check_number'         => 1001,
        'date'                 => '2026-06-10',
        'amount'               => 500.00,
        'bank_account_id'      => $account->id,
        'belongs_to_vendor_id' => 1,
    ]);
    matcherCheck([
        'check_type'           => 'Returned Check',
        'check_number'         => 1001,
        'date'                 => '2026-06-12',
        'amount'               => -500.00,
        'bank_account_id'      => $account->id,
        'belongs_to_vendor_id' => 1,
    ]);
    $real = matcherCheck([
        'check_type'           => 'Check',
        'check_number'         => 1001,
        'date'                 => '2026-06-10',
        'amount'               => 500.00,
        'bank_account_id'      => $account->id,
        'belongs_to_vendor_id' => 1,
    ]);

    $image  = matcherImage();
    $result = app(CheckImageMatcher::class)->match($image);

    expect($result['status'])->toBe(CheckImage::STATUS_MATCHED_CHECK)
        ->and($image->fresh()->check_id)->toBe($real->id);
});

it('rejects checks written after the cleared date or older than the lookback window', function (): void {
    $account = matcherBankAccount();

    matcherCheck([
        'check_type'           => 'Check',
        'check_number'         => 1001,
        'date'                 => '2026-07-15', // written a month AFTER clearing — impossible
        'amount'               => 500.00,
        'bank_account_id'      => $account->id,
        'belongs_to_vendor_id' => 1,
    ]);

    $image  = matcherImage();
    $result = app(CheckImageMatcher::class)->match($image);

    expect($result['status'])->toBe(CheckImage::STATUS_UNMATCHED)
        ->and($image->fresh()->check_id)->toBeNull();
});

it('links by number only when the amount differs, flagged for review', function (): void {
    $account = matcherBankAccount();

    $check = matcherCheck([
        'check_type'           => 'Check',
        'check_number'         => 1001,
        'date'                 => '2026-06-10',
        'amount'               => 555.00, // differs from image amount 500.00
        'bank_account_id'      => $account->id,
        'belongs_to_vendor_id' => 1,
    ]);

    $image  = matcherImage();
    $result = app(CheckImageMatcher::class)->match($image);

    expect($result['status'])->toBe(CheckImage::STATUS_MATCHED_CHECK_NUMBER_ONLY)
        ->and($image->fresh()->check_id)->toBe($check->id)
        ->and($image->fresh()->match_details['image_amount'])->not->toBeNull();
});

it('falls back to the unique cleared transaction when no check exists', function (): void {
    $account = matcherBankAccount();

    $transaction = Transaction::create([
        'transaction_date' => '2026-06-15',
        'amount'           => 500.00,
        'bank_account_id'  => $account->id,
        'check_number'     => '1001',
    ]);

    // Sentinel + returned-check rows that must be excluded.
    Transaction::create([
        'transaction_date' => '2026-06-15',
        'amount'           => 500.00,
        'bank_account_id'  => $account->id,
        'check_number'     => '1010101',
    ]);
    Transaction::create([
        'transaction_date'          => '2026-06-16',
        'amount'                    => 500.00,
        'bank_account_id'           => $account->id,
        'check_number'              => '1001',
        'plaid_merchant_description'=> 'CHECK 1001 RETURNED',
    ]);

    $image  = matcherImage();
    $result = app(CheckImageMatcher::class)->match($image);

    expect($result['status'])->toBe(CheckImage::STATUS_MATCHED_TRANSACTION)
        ->and($image->fresh()->transaction_id)->toBe($transaction->id)
        ->and($image->fresh()->check_id)->toBeNull();
});

it('adopts the check link from a matched transaction that already has one', function (): void {
    $account = matcherBankAccount();

    $check = matcherCheck([
        'check_type'           => 'Check',
        'check_number'         => 9999, // number mismatch — image can't find it directly
        'date'                 => '2026-06-10',
        'amount'               => 500.00,
        'bank_account_id'      => $account->id,
        'belongs_to_vendor_id' => 1,
    ]);
    $transaction = Transaction::create([
        'transaction_date' => '2026-06-15',
        'amount'           => 500.00,
        'bank_account_id'  => $account->id,
        'check_number'     => '1001',
        'check_id'         => $check->id,
    ]);

    $image  = matcherImage();
    $result = app(CheckImageMatcher::class)->match($image);

    expect($result['status'])->toBe(CheckImage::STATUS_MATCHED_TRANSACTION)
        ->and($image->fresh()->transaction_id)->toBe($transaction->id)
        ->and($image->fresh()->check_id)->toBe($check->id);
});

it('marks ambiguous when two checks share number and amount, and never guesses', function (): void {
    $account = matcherBankAccount();

    // Same account, duplicate number+amount (void + rewrite scenario).
    $first  = matcherCheck([
        'check_type' => 'Check', 'check_number' => 1001, 'date' => '2026-06-09',
        'amount' => 500.00, 'bank_account_id' => $account->id, 'belongs_to_vendor_id' => 1,
    ]);
    $second = matcherCheck([
        'check_type' => 'Check', 'check_number' => 1001, 'date' => '2026-06-10',
        'amount' => 500.00, 'bank_account_id' => $account->id, 'belongs_to_vendor_id' => 1,
    ]);

    $image  = matcherImage();
    $result = app(CheckImageMatcher::class)->match($image);

    expect($result['status'])->toBe(CheckImage::STATUS_AMBIGUOUS)
        ->and($image->fresh()->check_id)->toBeNull()
        ->and($image->fresh()->match_details['candidate_ids'])->toContain($first->id, $second->id);
});

it('prefers the transaction-bearing duplicate across re-linked accounts of the same bank', function (): void {
    $old = matcherBankAccount();
    $new = matcherBankAccount(4903, $old->bank_id);

    $stale  = matcherCheck([
        'check_type' => 'Check', 'check_number' => 1001, 'date' => '2026-06-10',
        'amount' => 500.00, 'bank_account_id' => $old->id, 'belongs_to_vendor_id' => 1,
    ]);
    $active = matcherCheck([
        'check_type' => 'Check', 'check_number' => 1001, 'date' => '2026-06-10',
        'amount' => 500.00, 'bank_account_id' => $new->id, 'belongs_to_vendor_id' => 1,
    ]);
    Transaction::create([
        'transaction_date' => '2026-06-15', 'amount' => 500.00,
        'bank_account_id' => $new->id, 'check_number' => '1001', 'check_id' => $active->id,
    ]);

    $image  = matcherImage();
    $result = app(CheckImageMatcher::class)->match($image);

    expect($result['status'])->toBe(CheckImage::STATUS_MATCHED_CHECK)
        ->and($image->fresh()->check_id)->toBe($active->id);
});

it('never re-matches manual or already linked images', function (): void {
    $account = matcherBankAccount();
    matcherCheck([
        'check_type' => 'Check', 'check_number' => 1001, 'date' => '2026-06-10',
        'amount' => 500.00, 'bank_account_id' => $account->id, 'belongs_to_vendor_id' => 1,
    ]);

    $manual = matcherImage(['match_status' => CheckImage::STATUS_MANUAL]);
    $result = app(CheckImageMatcher::class)->match($manual);

    expect($result['status'])->toBe(CheckImage::STATUS_MANUAL)
        ->and($manual->fresh()->check_id)->toBeNull();
});

it('dry-run reports the match without persisting anything', function (): void {
    $account = matcherBankAccount();
    $check   = matcherCheck([
        'check_type' => 'Check', 'check_number' => 1001, 'date' => '2026-06-10',
        'amount' => 500.00, 'bank_account_id' => $account->id, 'belongs_to_vendor_id' => 1,
    ]);

    $image  = matcherImage();
    $result = app(CheckImageMatcher::class)->match($image, dryRun: true);

    expect($result['status'])->toBe(CheckImage::STATUS_MATCHED_CHECK)
        ->and($result['details']['check_id'])->toBe($check->id)
        ->and($image->fresh()->check_id)->toBeNull()
        ->and($image->fresh()->match_status)->toBe(CheckImage::STATUS_PENDING);
});
