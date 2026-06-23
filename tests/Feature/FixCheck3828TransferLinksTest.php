<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeCheck3828Fixture(): array
{
    $vendor = Vendor::factory()->create(['business_name' => 'Owner LLC']);

    $bank = Bank::create([
        'name' => 'Citibank',
        'vendor_id' => $vendor->id,
        'plaid_ins_id' => 'ins_5',
    ]);

    $bankAccount = BankAccount::create([
        'vendor_id' => $vendor->id,
        'bank_id' => $bank->id,
        'account_number' => '4903',
        'plaid_account_id' => 'acc_'.uniqid(),
        'type' => 'checking',
    ]);

    $user = User::query()->create([
        'first_name' => 'Check',
        'last_name' => 'Owner',
        'email' => 'check-owner-'.uniqid().'@example.com',
        'cell_phone' => '5551234567',
        'password' => bcrypt('password'),
        'primary_vendor_id' => $vendor->id,
    ]);

    $check = Check::forceCreate([
        'id' => 3828,
        'check_type' => 'Transfer',
        'check_number' => '3828',
        'date' => '2026-06-01',
        'amount' => 400.00,
        'bank_account_id' => $bankAccount->id,
        'belongs_to_vendor_id' => $vendor->id,
        'user_id' => $user->id,
        'created_by_user_id' => $user->id,
    ]);

    $before = collect([
        ['id' => 28815, 'date' => '2026-05-25', 'amount' => 100.00],
        ['id' => 28869, 'date' => '2026-05-28', 'amount' => 100.00],
        ['id' => 28913, 'date' => '2026-05-31', 'amount' => 200.00],
    ])->map(fn ($t) => Transaction::forceCreate([
        'id' => $t['id'],
        'transaction_date' => $t['date'],
        'amount' => $t['amount'],
        'bank_account_id' => $bankAccount->id,
        'check_number' => '1010101',
        'plaid_merchant_description' => 'Venmo',
        // 28913 starts already linked (it is part of both the wrong and correct combos);
        // 28815/28869 start unlinked.
        'check_id' => $t['id'] === 28913 ? 3828 : null,
    ]));

    // Wrong post-check transfer that was originally linked.
    $wrong = Transaction::forceCreate([
        'id' => 28916,
        'transaction_date' => '2026-06-03',
        'amount' => 200.00,
        'bank_account_id' => $bankAccount->id,
        'check_number' => '1010101',
        'plaid_merchant_description' => 'Venmo',
        'check_id' => 3828,
    ]);

    return compact('check', 'before', 'wrong');
}

it('relinks check 3828 to the three before-check transfers and unlinks the post-check transfer', function () {
    makeCheck3828Fixture();

    $this->artisan('app:fix-check3828-transfer-links')->assertSuccessful();

    expect(Transaction::withoutGlobalScopes()->find(28815)->check_id)->toBe(3828)
        ->and(Transaction::withoutGlobalScopes()->find(28869)->check_id)->toBe(3828)
        ->and(Transaction::withoutGlobalScopes()->find(28913)->check_id)->toBe(3828)
        ->and(Transaction::withoutGlobalScopes()->find(28916)->check_id)->toBeNull();
});

it('is idempotent when check 3828 is already correct', function () {
    makeCheck3828Fixture();

    Transaction::withoutGlobalScopes()->where('id', 28916)->update(['check_id' => null]);
    Transaction::withoutGlobalScopes()->whereIn('id', [28815, 28869, 28913])->update(['check_id' => 3828]);

    $this->artisan('app:fix-check3828-transfer-links')->assertSuccessful();

    expect(Transaction::withoutGlobalScopes()->find(28815)->check_id)->toBe(3828)
        ->and(Transaction::withoutGlobalScopes()->find(28869)->check_id)->toBe(3828)
        ->and(Transaction::withoutGlobalScopes()->find(28913)->check_id)->toBe(3828)
        ->and(Transaction::withoutGlobalScopes()->find(28916)->check_id)->toBeNull();
});
