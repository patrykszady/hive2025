<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeCheck3825Fixture(): array
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
        'id' => 3825,
        'check_type' => 'Transfer',
        'check_number' => '3825',
        'date' => '2026-05-15',
        'amount' => 200.00,
        'bank_account_id' => $bankAccount->id,
        'belongs_to_vendor_id' => $vendor->id,
        'user_id' => $user->id,
        'created_by_user_id' => $user->id,
    ]);

    $pairA = Transaction::forceCreate([
        'id' => 28744,
        'transaction_date' => '2026-05-13',
        'amount' => 100.00,
        'bank_account_id' => $bankAccount->id,
        'check_number' => '1010101',
        'plaid_merchant_description' => 'Venmo',
        'check_id' => null,
    ]);

    $pairB = Transaction::forceCreate([
        'id' => 28762,
        'transaction_date' => '2026-05-13',
        'amount' => 100.00,
        'bank_account_id' => $bankAccount->id,
        'check_number' => '1010101',
        'plaid_merchant_description' => 'Venmo',
        'check_id' => null,
    ]);

    $wrong = Transaction::forceCreate([
        'id' => 28789,
        'transaction_date' => '2026-05-16',
        'amount' => 200.00,
        'bank_account_id' => $bankAccount->id,
        'check_number' => '1010101',
        'plaid_merchant_description' => 'Venmo',
        'check_id' => 3825,
    ]);

    return compact('check', 'pairA', 'pairB', 'wrong');
}

it('relinks check 3825 to the correct pair and unlinks the wrong transaction', function () {
    ['check' => $check, 'pairA' => $pairA, 'pairB' => $pairB, 'wrong' => $wrong] = makeCheck3825Fixture();

    $this->artisan('app:fix-check3825-transfer-links')->assertSuccessful();

    expect($pairA->refresh()->check_id)->toBe(3825)
        ->and($pairB->refresh()->check_id)->toBe(3825)
        ->and($wrong->refresh()->check_id)->toBeNull();
});

it('is idempotent when check 3825 is already correct', function () {
    makeCheck3825Fixture();

    Transaction::withoutGlobalScopes()->where('id', 28789)->update(['check_id' => null]);
    Transaction::withoutGlobalScopes()->whereIn('id', [28744, 28762])->update(['check_id' => 3825]);

    $this->artisan('app:fix-check3825-transfer-links')->assertSuccessful();

    expect(Transaction::withoutGlobalScopes()->find(28744)->check_id)->toBe(3825)
        ->and(Transaction::withoutGlobalScopes()->find(28762)->check_id)->toBe(3825)
        ->and(Transaction::withoutGlobalScopes()->find(28789)->check_id)->toBeNull();
});
