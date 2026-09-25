<?php

use App\Livewire\Banks\BankIndex;
use App\Livewire\Banks\BankShow;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * /banks fanned out into a fresh accounts query PLUS one checks query per
 * account-type group for EACH nested BankShow (38 queries for a handful of
 * banks). These pin: BankIndex batches every bank's accounts/checks into 2
 * queries and hands the exact same shape down; BankShow build the identical
 * structure on its own when reached directly (banks.show route).
 */
function perf_banksUser(): User
{
    $vendor = Vendor::factory()->create(['business_type' => 'GC']);

    $user = User::query()->create([
        'first_name' => 'Bank', 'last_name' => 'Admin',
        'email' => 'bank-admin-'.uniqid().'@example.test',
        'cell_phone' => '224'.rand(1000000, 9999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);

    return $user;
}

function perf_makeBankWithAccountsAndChecks(User $user, string $name, int $accountCount): Bank
{
    $bank = Bank::create([
        'name' => $name,
        'vendor_id' => $user->vendor->id,
        'plaid_access_token' => 'access-'.uniqid(),
        'plaid_ins_id' => 'ins-'.uniqid(),
    ]);

    for ($i = 0; $i < $accountCount; $i++) {
        $account = BankAccount::create([
            'vendor_id' => $user->vendor->id,
            'bank_id' => $bank->id,
            'account_number' => 1000 + $i,
            'plaid_account_id' => 'acc-'.uniqid(),
            'type' => 'checking',
        ]);

        Check::create([
            'check_type' => 'Check',
            'check_number' => (string) (100 + $i),
            'date' => now()->subMonth(),
            'bank_account_id' => $account->id,
            'user_id' => $user->id,
            'vendor_id' => $user->vendor->id,
            'belongs_to_vendor_id' => $user->vendor->id,
            'created_by_user_id' => $user->id,
            'amount' => 42.50,
        ]);
    }

    return $bank;
}

it('batches accounts and checks for every bank into 2 queries instead of N per bank', function () {
    $user = perf_banksUser();
    $this->actingAs($user);

    perf_makeBankWithAccountsAndChecks($user, 'First Bank', 2);
    perf_makeBankWithAccountsAndChecks($user, 'Second Bank', 3);
    perf_makeBankWithAccountsAndChecks($user, 'Third Bank', 2);

    DB::enableQueryLog();
    $html = Livewire::test(BankIndex::class)->html();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::flushQueryLog();
    DB::disableQueryLog();

    expect($html)->toContain('First Bank')->toContain('Second Bank')->toContain('Third Bank')
        ->and($html)->toContain('checking'); // account type badge

    // Scoped to the bank_id IN (...) batch query BankIndex::accountsByBank()
    // runs — a separate, unrelated `bank_accounts` existence check other
    // layout chrome (not owned by this track) also runs on every page is
    // not what's being measured here.
    $accountQueries = $queries->filter(fn ($sql) => str_contains($sql, 'from "bank_accounts"') && str_contains($sql, 'bank_id" in'));
    $checkQueries = $queries->filter(fn ($sql) => str_contains($sql, 'from "checks"'));

    // One batched accounts query and one batched checks query for the
    // whole page — not one of each per bank (3 banks would have been 6+).
    expect($accountQueries)->toHaveCount(1)
        ->and($checkQueries)->toHaveCount(1);
});

it('keeps the batched query count flat as the number of banks grows', function () {
    $user = perf_banksUser();
    $this->actingAs($user);

    for ($i = 0; $i < 6; $i++) {
        perf_makeBankWithAccountsAndChecks($user, "Bank {$i}", 2);
    }

    DB::enableQueryLog();
    Livewire::test(BankIndex::class)->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::flushQueryLog();
    DB::disableQueryLog();

    $accountQueries = $queries->filter(fn ($sql) => str_contains($sql, 'from "bank_accounts"') && str_contains($sql, 'bank_id" in'));
    $checkQueries = $queries->filter(fn ($sql) => str_contains($sql, 'from "checks"'));

    expect($accountQueries)->toHaveCount(1)
        ->and($checkQueries)->toHaveCount(1);
});

it('still builds the correct accounts/checks structure when BankShow is reached standalone', function () {
    $user = perf_banksUser();
    $this->actingAs($user);

    $bank = perf_makeBankWithAccountsAndChecks($user, 'Standalone Bank', 1);

    // No :accounts prop — the banks.show route reaches this directly.
    Livewire::test(BankShow::class, ['bank' => $bank])
        ->assertOk()
        ->assertSee('Standalone Bank')
        ->assertSee('checking')
        ->assertSee('100'); // check_number
});

it('the index page renders identical account/check content to the standalone page', function () {
    $user = perf_banksUser();
    $this->actingAs($user);

    $bank = perf_makeBankWithAccountsAndChecks($user, 'Compare Bank', 2);

    $indexHtml = Livewire::test(BankIndex::class)->html();
    $standaloneHtml = Livewire::test(BankShow::class, ['bank' => $bank])->html();

    foreach (['1000', '1001', '100', '101'] as $needle) {
        expect($indexHtml)->toContain($needle)
            ->and($standaloneHtml)->toContain($needle);
    }
});
