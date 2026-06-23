<?php

use App\Http\Controllers\ReceiptController;

it('runs amazon full sync for all accounts', function () {
    $controller = Mockery::mock(ReceiptController::class);
    $controller->shouldReceive('amazon_orders_api')
        ->once()
        ->with(true, null)
        ->andReturnNull();

    app()->instance(ReceiptController::class, $controller);

    $this->artisan('amazon:orders-api-full-sync')
        ->expectsOutput('Running Amazon full sync for all connected Amazon receipt accounts...')
        ->expectsOutput('Amazon full sync completed.')
        ->assertSuccessful();
});

it('runs amazon full sync for a specific vendor id', function () {
    $controller = Mockery::mock(ReceiptController::class);
    $controller->shouldReceive('amazon_orders_api')
        ->once()
        ->with(true, 1)
        ->andReturnNull();

    app()->instance(ReceiptController::class, $controller);

    $this->artisan('amazon:orders-api-full-sync --vendor-id=1')
        ->expectsOutput('Running Amazon full sync for belongs_to_vendor_id 1...')
        ->expectsOutput('Amazon full sync completed.')
        ->assertSuccessful();
});

it('supports dry run without executing sync', function () {
    $controller = Mockery::mock(ReceiptController::class);
    $controller->shouldNotReceive('amazon_orders_api');

    app()->instance(ReceiptController::class, $controller);

    $this->artisan('amazon:orders-api-full-sync --vendor-id=1 --dry-run')
        ->expectsOutput('Dry run: would run Amazon full sync for belongs_to_vendor_id 1.')
        ->assertSuccessful();
});
