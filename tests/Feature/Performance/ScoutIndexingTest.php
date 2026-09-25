<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Transaction::toSearchableArray() unconditionally lazy-loaded `payments`
 * during a full `scout:import` reindex (~936k lazy loads logged). These pin:
 * with the relation already eager-loaded (as makeAllSearchableUsing() now
 * does for a bulk reindex), no query fires; without it, a single-record
 * ->searchable() call still gets the right answer via a cheap EXISTS
 * instead of loading every payment row.
 */
function perf_scoutUser(): User
{
    $vendor = Vendor::factory()->create(['business_type' => 'GC']);

    $user = User::query()->create([
        'first_name' => 'Scout', 'last_name' => 'Admin',
        'email' => 'scout-admin-'.uniqid().'@example.test',
        'cell_phone' => '224'.rand(1000000, 9999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);

    return $user;
}

function perf_scoutTransactionWithPayments(User $user, int $paymentCount): Transaction
{
    $bank = Bank::create(['name' => 'Fixture Bank', 'vendor_id' => $user->vendor->id, 'plaid_ins_id' => 'ins-'.uniqid()]);
    $account = BankAccount::create([
        'vendor_id' => $user->vendor->id, 'bank_id' => $bank->id, 'account_number' => 1,
        'plaid_account_id' => 'acc-'.uniqid(), 'type' => 'checking',
    ]);

    $transaction = Transaction::create([
        'transaction_date' => now()->subDay(),
        'amount' => 100,
        'bank_account_id' => $account->id,
    ]);

    for ($i = 0; $i < $paymentCount; $i++) {
        Payment::create([
            'amount' => 10,
            'date' => now(),
            'transaction_id' => $transaction->id,
            'belongs_to_vendor_id' => $user->vendor->id,
            'created_by_user_id' => $user->id,
        ]);
    }

    return $transaction;
}

it('marks HAS_PAYMENTS from the eager-loaded relation without an extra query', function () {
    $user = perf_scoutUser();
    $this->actingAs($user);

    $transaction = perf_scoutTransactionWithPayments($user, 3);
    $transaction->load('payments');

    DB::enableQueryLog();
    $array = $transaction->toSearchableArray();
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();

    expect($array['deposit'])->toBe('HAS_PAYMENTS')
        ->and($queries->filter(fn ($q) => str_contains($q['query'], 'from "payments"')))->toHaveCount(0);
});

it('falls back to a cheap EXISTS check (not a full load) when payments aren\'t pre-loaded', function () {
    $user = perf_scoutUser();
    $this->actingAs($user);

    $transaction = perf_scoutTransactionWithPayments($user, 3);
    $fresh = Transaction::withoutGlobalScopes()->find($transaction->id);
    expect($fresh->relationLoaded('payments'))->toBeFalse();

    DB::enableQueryLog();
    $array = $fresh->toSearchableArray();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $paymentQueries = $queries->filter(fn ($sql) => str_contains($sql, 'from "payments"'));

    expect($array['deposit'])->toBe('HAS_PAYMENTS')
        ->and($paymentQueries)->toHaveCount(1)
        ->and($paymentQueries->first())->toContain('exists'); // cheap EXISTS, not a full row load
});

it('marks NOT_DEPOSIT/NO_PAYMENTS correctly with no payments either way', function () {
    $user = perf_scoutUser();
    $this->actingAs($user);

    $withoutPayments = perf_scoutTransactionWithPayments($user, 0);

    $loaded = Transaction::withoutGlobalScopes()->with('payments')->find($withoutPayments->id);
    expect($loaded->toSearchableArray()['deposit'])->toBe('NOT_DEPOSIT');

    $unloaded = Transaction::withoutGlobalScopes()->find($withoutPayments->id);
    expect($unloaded->relationLoaded('payments'))->toBeFalse()
        ->and($unloaded->toSearchableArray()['deposit'])->toBe('NOT_DEPOSIT');
});

it('makeAllSearchableUsing eager-loads payments for a bulk reindex', function () {
    $method = new ReflectionMethod(Transaction::class, 'makeAllSearchableUsing');
    $method->setAccessible(true);

    $query = Transaction::query()->withoutGlobalScopes();
    $result = $method->invoke(new Transaction(), $query);

    expect($result->getEagerLoads())->toHaveKey('payments');
});

it('Project::toSearchableArray still resolves the correct latest status after removing the no-op guard', function () {
    $user = perf_scoutUser();
    $this->actingAs($user);

    $client = \App\Models\Client::factory()->create();
    $project = Project::factory()->create(['belongs_to_vendor_id' => $user->vendor->id, 'client_id' => $client->id]);
    // ProjectObserver auto-creates a status_code=2 status on project
    // creation — make this one clearly the latest by start_date.
    \App\Models\ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $user->vendor->id,
        'status_code' => 6,
        'start_date' => now()->addDay()->format('Y-m-d'),
    ]);

    $array = $project->fresh()->toSearchableArray();

    expect($array['latest_status_code'])->toBe(6);
});
