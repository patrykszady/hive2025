<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\Timesheet;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeCheck3826Fixture(): array
{
    config(['scout.driver' => 'collection']);

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
        'first_name' => 'Patryk',
        'last_name' => 'Szady',
        'email' => 'check-3826-'.uniqid().'@example.com',
        'cell_phone' => '5551234567',
        'password' => bcrypt('password'),
        'primary_vendor_id' => $vendor->id,
    ]);

    $duplicateCheck = Check::forceCreate([
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

    $targetCheck = Check::forceCreate([
        'id' => 3826,
        'check_type' => 'Transfer',
        'check_number' => '3826',
        'date' => '2026-05-16',
        'amount' => 400.00,
        'bank_account_id' => $bankAccount->id,
        'belongs_to_vendor_id' => $vendor->id,
        'user_id' => $user->id,
        'created_by_user_id' => $user->id,
    ]);

    foreach ([
        ['id' => 7080, 'project_id' => 362, 'hours' => 3, 'amount' => 150],
        ['id' => 7081, 'project_id' => 369, 'hours' => 1, 'amount' => 50],
        ['id' => 7082, 'project_id' => 359, 'hours' => 1, 'amount' => 50],
        ['id' => 7083, 'project_id' => 364, 'hours' => 2, 'amount' => 100],
        ['id' => 7084, 'project_id' => 387, 'hours' => 1, 'amount' => 50],
    ] as $timesheet) {
        Timesheet::forceCreate([
            'id' => $timesheet['id'],
            'date' => '2026-05-11',
            'user_id' => $user->id,
            'vendor_id' => $vendor->id,
            'project_id' => $timesheet['project_id'],
            'hours' => $timesheet['hours'],
            'amount' => $timesheet['amount'],
            'hourly' => 50,
            'check_id' => 3826,
            'created_by_user_id' => $user->id,
        ]);
    }

    $first = Transaction::forceCreate([
        'id' => 28744,
        'transaction_date' => '2026-05-13',
        'amount' => 100.00,
        'bank_account_id' => $bankAccount->id,
        'check_number' => '1010101',
        'plaid_merchant_description' => 'Venmo',
        'check_id' => 3825,
    ]);

    $second = Transaction::forceCreate([
        'id' => 28762,
        'transaction_date' => '2026-05-13',
        'amount' => 100.00,
        'bank_account_id' => $bankAccount->id,
        'check_number' => '1010101',
        'plaid_merchant_description' => 'Venmo',
        'check_id' => 3825,
    ]);

    $third = Transaction::forceCreate([
        'id' => 28789,
        'transaction_date' => '2026-05-16',
        'amount' => 200.00,
        'bank_account_id' => $bankAccount->id,
        'check_number' => '1010101',
        'plaid_merchant_description' => 'Venmo',
        'check_id' => null,
    ]);

    return compact('duplicateCheck', 'targetCheck', 'first', 'second', 'third');
}

it('dry-runs the check 3826 transfer relink without changing data', function () {
    makeCheck3826Fixture();

    $this->artisan('app:fix-check3826-transfer-links --dry-run')
        ->expectsOutput('DRY RUN — no changes will be made.')
        ->assertSuccessful();

    expect(Transaction::withoutGlobalScopes()->find(28744)->check_id)->toBe(3825)
        ->and(Transaction::withoutGlobalScopes()->find(28762)->check_id)->toBe(3825)
        ->and(Transaction::withoutGlobalScopes()->find(28789)->check_id)->toBeNull()
        ->and(Check::withoutGlobalScopes()->withTrashed()->find(3825)?->trashed())->toBeFalse();
});

it('relinks check 3826 to the correct transfers and soft-deletes duplicate check 3825', function () {
    makeCheck3826Fixture();

    $this->artisan('app:fix-check3826-transfer-links')->assertSuccessful();

    expect(Transaction::withoutGlobalScopes()->find(28744)->check_id)->toBe(3826)
        ->and(Transaction::withoutGlobalScopes()->find(28762)->check_id)->toBe(3826)
        ->and(Transaction::withoutGlobalScopes()->find(28789)->check_id)->toBe(3826)
        ->and(Check::withoutGlobalScopes()->withTrashed()->find(3825)?->trashed())->toBeTrue();
});

it('is idempotent when check 3826 is already correct and duplicate check 3825 is already deleted', function () {
    makeCheck3826Fixture();

    Transaction::withoutGlobalScopes()->whereIn('id', [28744, 28762, 28789])->update(['check_id' => 3826]);

    $duplicateCheck = Check::withoutGlobalScopes()->find(3825);
    $duplicateCheck?->delete();

    $this->artisan('app:fix-check3826-transfer-links')->assertSuccessful();

    expect(Transaction::withoutGlobalScopes()->find(28744)->check_id)->toBe(3826)
        ->and(Transaction::withoutGlobalScopes()->find(28762)->check_id)->toBe(3826)
        ->and(Transaction::withoutGlobalScopes()->find(28789)->check_id)->toBe(3826)
        ->and(Check::withoutGlobalScopes()->withTrashed()->find(3825)?->trashed())->toBeTrue();
});