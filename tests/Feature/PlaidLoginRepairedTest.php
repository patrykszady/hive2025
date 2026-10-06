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
