<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PlaidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * AppAuthTest's appTeamUser(). $role is the vendor_user pivot's role_id
 * (1 = Admin, 2 = Member) — BankPolicy gates everything on it.
 *
 * @return array{vendor: Vendor, user: User}
 */
function appBooksBanksTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

function appBooksBank(Vendor $vendor, array $overrides = []): Bank
{
    return Bank::create(array_merge([
        'name' => 'Test Credit Union',
        'vendor_id' => $vendor->id,
        'plaid_access_token' => 'access-'.uniqid(),
        'plaid_ins_id' => 'ins_'.uniqid(),
    ], $overrides));
}

function appBooksBankAccount(Bank $bank, Vendor $vendor, array $overrides = []): BankAccount
{
    return BankAccount::create(array_merge([
        'bank_id' => $bank->id,
        'vendor_id' => $vendor->id,
        'account_number' => random_int(1000, 9999),
        'plaid_account_id' => 'acc_'.uniqid(),
        'type' => 'Checking',
    ], $overrides));
}

it('needs a token to list banks', function () {
    $this->getJson('/api/app/v1/banks')->assertUnauthorized();
});

it('refuses a Member listing banks, like the web', function () {
    ['user' => $member] = appBooksBanksTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->getJson('/api/app/v1/banks')->assertForbidden();
});

it('lists an Admin\'s own banks with account balances, but never another tenant\'s', function () {
    ['vendor' => $vendor, 'user' => $admin] = appBooksBanksTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $bank = appBooksBank($vendor, ['name' => 'My Credit Union']);
    appBooksBankAccount($bank, $vendor, [
        'options' => ['balances' => ['available' => 1234.56], 'last_balance_update' => '2026-10-01'],
    ]);

    $otherVendor = Vendor::factory()->create();
    appBooksBank($otherVendor, ['name' => 'Someone Else\'s Bank']);

    $response = $this->getJson('/api/app/v1/banks')->assertOk();
    $names = collect($response->json('data'))->pluck('name');

    expect($names->all())->toBe(['My Credit Union'])
        ->and($response->json('data.0.error'))->toBeNull()
        ->and((float) $response->json('data.0.accounts.0.balance'))->toBe(1234.56);
});

it('flags a bank with a Plaid connection error', function () {
    ['vendor' => $vendor, 'user' => $admin] = appBooksBanksTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    appBooksBank($vendor, [
        'name' => 'Broken Bank',
        'plaid_options' => ['error' => ['error_code' => 'ITEM_LOGIN_REQUIRED', 'display_message' => 'Reconnect this bank.']],
    ]);

    $response = $this->getJson('/api/app/v1/banks')->assertOk();

    expect($response->json('data.0.error.code'))->toBe('ITEM_LOGIN_REQUIRED')
        ->and($response->json('data.0.error.message'))->toBe('Reconnect this bank.');
});

it('shows a bank\'s accounts and uncleared checks, with a web_path for the "Open in Hive" reconnect flow', function () {
    ['vendor' => $vendor, 'user' => $admin] = appBooksBanksTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $this->mock(PlaidService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('institutionSupportsStatements')->andReturn(false);
    });

    $bank = appBooksBank($vendor);
    $account = appBooksBankAccount($bank, $vendor, ['type' => 'Checking']);

    Check::create([
        'bank_account_id' => $account->id,
        'vendor_id' => $vendor->id,
        'check_type' => 'Check',
        'check_number' => 1001,
        'amount' => 250.00,
        'date' => now()->format('Y-m-d'),
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $admin->id,
    ]);

    $response = $this->getJson("/api/app/v1/banks/{$bank->id}")->assertOk();

    expect($response->json('data.id'))->toBe($bank->id)
        ->and($response->json('data.web_path'))->toBe("/banks/{$bank->id}")
        ->and($response->json('data.supports_statements'))->toBeFalse()
        ->and((float) $response->json('data.accounts.0.checks.0.amount'))->toBe(250.0);
});

it('404s a bank that does not exist, and hides another tenant\'s bank the same way', function () {
    ['user' => $admin] = appBooksBanksTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $this->mock(PlaidService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('institutionSupportsStatements')->andReturn(false);
    });

    $this->getJson('/api/app/v1/banks/999999')->assertNotFound();

    $otherVendor = Vendor::factory()->create();
    $otherBank = appBooksBank($otherVendor);

    $this->getJson("/api/app/v1/banks/{$otherBank->id}")->assertNotFound();
});
