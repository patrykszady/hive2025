<?php

use App\Jobs\ProcessPlaidTransactionSync;
use App\Models\Bank;
use App\Models\Vendor;
use App\Services\PlaidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * 2026-10-05: Citibank was reconnected through Link's update mode and the
 * banks page went on showing ITEM_LOGIN_REQUIRED. Plaid sends no webhook for
 * a repair made through our own update flow, and syncs skip a bank in error,
 * so nothing ever cleared it. The bank now asks Plaid how the Item is.
 */
function erroredBank(): Bank
{
    $vendor = Vendor::factory()->create(['business_name' => 'GS Test Co']);

    return Bank::create([
        'name' => 'Citibank',
        'vendor_id' => $vendor->id,
        'plaid_ins_id' => 'ins_5',
        'plaid_item_id' => 'item-'.uniqid(),
        'plaid_access_token' => 'access-'.uniqid(),
        'plaid_options' => ['error' => ['error' => true, 'error_type' => 'ITEM_ERROR', 'error_code' => 'ITEM_LOGIN_REQUIRED', 'error_message' => 'login required']],
    ]);
}

function plaidItemAnswers(array $item): void
{
    $mock = Mockery::mock(PlaidService::class)->makePartial();
    $mock->shouldReceive('getItem')->andReturn($item);
    app()->instance(PlaidService::class, $mock);
}

it('clears a repaired login error at once and starts a catch-up sync', function () {
    Queue::fake();
    $bank = erroredBank();
    plaidItemAnswers(['item' => ['item_id' => $bank->plaid_item_id, 'error' => null]]);

    $result = app(PlaidService::class)->refreshItemStatus($bank);

    expect($result)->toMatchArray(['checked' => true, 'repaired' => true])
        ->and($bank->fresh()->error)->toBeFalse()
        ->and($bank->fresh()->plaid_options['repaired_at'] ?? null)->not->toBeNull();
    Queue::assertPushed(ProcessPlaidTransactionSync::class, fn ($job) => $job->bank->is($bank) && $job->webhookCode === 'LOGIN_REPAIRED');
});

it('keeps the error, stored fresh, when Plaid still reports one', function () {
    Queue::fake();
    $bank = erroredBank();
    plaidItemAnswers(['item' => ['item_id' => $bank->plaid_item_id, 'error' => ['error_type' => 'ITEM_ERROR', 'error_code' => 'ITEM_LOCKED', 'error_message' => 'locked']]]);

    app(PlaidService::class)->refreshItemStatus($bank);

    expect($bank->fresh()->error['error_code'])->toBe('ITEM_LOCKED');
    Queue::assertNotPushed(ProcessPlaidTransactionSync::class);
});

it('changes nothing when Plaid cannot be asked', function () {
    Queue::fake();
    $bank = erroredBank();
    plaidItemAnswers(['error' => true, 'error_message' => 'timeout']);

    expect(app(PlaidService::class)->refreshItemStatus($bank)['checked'])->toBeFalse()
        ->and($bank->fresh()->error['error_code'])->toBe('ITEM_LOGIN_REQUIRED');
});

it('clears the error on a LOGIN_REPAIRED webhook', function () {
    Queue::fake();
    $bank = erroredBank();
    plaidItemAnswers(['item' => ['item_id' => $bank->plaid_item_id, 'error' => null]]);

    $this->postJson('/webhooks/plaid', ['webhook_type' => 'ITEM', 'webhook_code' => 'LOGIN_REPAIRED', 'item_id' => $bank->plaid_item_id, 'environment' => 'sandbox'])
        ->assertOk();

    expect($bank->fresh()->error)->toBeFalse();
    Queue::assertPushed(ProcessPlaidTransactionSync::class);
});

it('re-checks every bank shown in error on demand', function () {
    Queue::fake();
    $bank = erroredBank();
    plaidItemAnswers(['item' => ['item_id' => $bank->plaid_item_id, 'error' => null]]);

    $this->artisan('banks:refresh-plaid-status')
        ->expectsOutputToContain('ITEM_LOGIN_REQUIRED cleared, sync started')
        ->assertSuccessful();

    expect($bank->fresh()->error)->toBeFalse();
});

it('does not call a just-repaired bank stale on the hourly check, and catches it up', function () {
    Queue::fake();
    $bank = erroredBank();
    $mock = Mockery::mock(PlaidService::class)->makePartial();
    $mock->shouldReceive('getItem')->andReturn([
        'item' => ['item_id' => $bank->plaid_item_id, 'error' => null],
        'status' => ['transactions' => ['last_failed_update' => now()->subHours(2)->toIso8601String(), 'last_successful_update' => now()->subDays(6)->toIso8601String()]],
    ]);
    app()->instance(PlaidService::class, $mock);

    app(App\Http\Controllers\TransactionController::class)->plaid_item_status();

    expect($bank->fresh()->error)->toBeFalse()
        ->and($bank->fresh()->plaid_options['repaired_at'] ?? null)->not->toBeNull();
    Queue::assertPushed(ProcessPlaidTransactionSync::class, fn ($job) => $job->bank->is($bank));

    // An hour later: still Plaid's old update dates, but repaired within three days.
    app(App\Http\Controllers\TransactionController::class)->plaid_item_status();
    expect($bank->fresh()->error)->toBeFalse();
});

/** A bank of an active company, healthy or in ITEM_LOGIN_REQUIRED. */
function syncBank(bool $inError, string $name = 'Citibank'): Bank
{
    $vendor = Vendor::factory()->create(['business_name' => $name.' Co']);
    $vendor->forceFill(['registration' => ['registered' => true, 'registration_date' => now()->toDateString()]])->save();

    return Bank::create([
        'name' => $name,
        'vendor_id' => $vendor->id,
        'plaid_ins_id' => 'ins_'.uniqid(),
        'plaid_item_id' => 'item-'.uniqid(),
        'plaid_access_token' => 'access-'.uniqid(),
        'plaid_options' => ['error' => $inError ? ['error' => true, 'error_type' => 'ITEM_ERROR', 'error_code' => 'ITEM_LOGIN_REQUIRED'] : false],
    ]);
}

it('syncs every healthy bank daily, and re-checks the ones in error', function () {
    Queue::fake();
    $healthy = syncBank(false, 'Capital One');
    $repaired = syncBank(true, 'Citibank');
    $stillBroken = syncBank(true, 'PSFCU');

    $mock = Mockery::mock(PlaidService::class)->makePartial();
    $mock->shouldReceive('getItem')->andReturnUsing(fn (string $token) => $token === $stillBroken->plaid_access_token
        ? ['item' => ['error' => ['error_type' => 'ITEM_ERROR', 'error_code' => 'ITEM_LOGIN_REQUIRED']]]
        : ['item' => ['error' => null]]);
    app()->instance(PlaidService::class, $mock);
    $this->mock(App\Http\Controllers\PlaidTransactionSyncController::class)
        ->shouldReceive('syncBank')->once()->withArgs(fn (Bank $bank) => $bank->is($healthy));

    $this->artisan('plaid:sync-transactions', ['--all' => true])
        ->expectsOutputToContain("Bank {$repaired->id} (Citibank): ITEM_LOGIN_REQUIRED cleared by Plaid — catch-up sync queued")
        ->expectsOutputToContain("Skipped bank {$stillBroken->id} (PSFCU): still ITEM_LOGIN_REQUIRED")
        ->expectsOutputToContain('Sync complete: 2 synced, 1 skipped, 0 errors')
        ->assertSuccessful();

    Queue::assertPushed(ProcessPlaidTransactionSync::class, fn ($job) => $job->bank->is($repaired) && $job->webhookCode === 'LOGIN_REPAIRED');
    Queue::assertNotPushed(ProcessPlaidTransactionSync::class, fn ($job) => $job->bank->is($stillBroken));
    expect($repaired->fresh()->error)->toBeFalse()
        ->and($stillBroken->fresh()->error['error_code'])->toBe('ITEM_LOGIN_REQUIRED');
});

it('runs the daily Plaid sync at 4 am Chicago in production only', function () {
    $event = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'plaid:sync-transactions'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 4 * * *')
        ->and($event->timezone)->toBe('America/Chicago')
        ->and($event->environments)->toBe(['production']);
});
