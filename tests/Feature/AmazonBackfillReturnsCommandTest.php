<?php

use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\TransactionController;

it('fails when no date window is provided', function () {
    $this->artisan('amazon:backfill-returns')
        ->expectsOutput('Please provide --start-date and --end-date (or one date and it will be used for both).')
        ->assertFailed();
});

it('supports dry run for a specific vendor', function () {
    $receiptController = Mockery::mock(ReceiptController::class);
    $receiptController->shouldNotReceive('amazon_orders_api');

    $transactionController = Mockery::mock(TransactionController::class);
    $transactionController->shouldNotReceive('add_expense_to_transactions');

    app()->instance(ReceiptController::class, $receiptController);
    app()->instance(TransactionController::class, $transactionController);

    $this->artisan('amazon:backfill-returns --vendor-id=1 --start-date=2026-05-21 --end-date=2026-06-01 --dry-run')
        ->expectsOutput('Dry run: would backfill Amazon returns for belongs_to_vendor_id 1 from 2026-05-21 to 2026-06-01, then run add_expense_to_transactions.')
        ->assertSuccessful();
});

it('runs backfill sync and linking for a specific vendor', function () {
    $receiptController = Mockery::mock(ReceiptController::class);
    $receiptController->shouldReceive('amazon_orders_api')
        ->once()
        ->with(true, 1, '2026-05-21', '2026-06-01')
        ->andReturnNull();

    $transactionController = Mockery::mock(TransactionController::class);
    $transactionController->shouldReceive('add_expense_to_transactions')
        ->once()
        ->andReturnNull();

    app()->instance(ReceiptController::class, $receiptController);
    app()->instance(TransactionController::class, $transactionController);

    $this->artisan('amazon:backfill-returns --vendor-id=1 --start-date=2026-05-21 --end-date=2026-06-01')
        ->expectsOutput('Running Amazon return backfill for belongs_to_vendor_id 1 from 2026-05-21 to 2026-06-01...')
        ->expectsOutput('Amazon sync completed. Running transaction-to-expense linking...')
        ->expectsOutput('Amazon return backfill completed.')
        ->assertSuccessful();
});
