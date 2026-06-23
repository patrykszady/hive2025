<?php

use App\Models\Expense;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows a second same-day refund for the same order when amount differs', function () {
    Expense::create([
        'amount' => -95.35,
        'date' => '2026-05-23',
        'project_id' => null,
        'distribution_id' => null,
        'created_by_user_id' => 0,
        'invoice' => '111-5753528-6757032',
        'vendor_id' => 54,
        'note' => null,
        'belongs_to_vendor_id' => 1,
    ]);

    $newRefundAmount = 29.69;

    $existingRefund = Expense::query()
        ->where('belongs_to_vendor_id', 1)
        ->where('vendor_id', 54)
        ->whereNull('deleted_at')
        ->where('invoice', '111-5753528-6757032')
        ->where('amount', -1 * $newRefundAmount)
        ->where('date', '2026-05-23')
        ->exists();

    expect($existingRefund)->toBeFalse();
});

it('still blocks duplicate refund when order date and amount are identical', function () {
    Expense::create([
        'amount' => -29.69,
        'date' => '2026-05-23',
        'project_id' => null,
        'distribution_id' => null,
        'created_by_user_id' => 0,
        'invoice' => '111-5753528-6757032',
        'vendor_id' => 54,
        'note' => null,
        'belongs_to_vendor_id' => 1,
    ]);

    $newRefundAmount = 29.69;

    $existingRefund = Expense::query()
        ->where('belongs_to_vendor_id', 1)
        ->where('vendor_id', 54)
        ->whereNull('deleted_at')
        ->where('invoice', '111-5753528-6757032')
        ->where('amount', -1 * $newRefundAmount)
        ->where('date', '2026-05-23')
        ->exists();

    expect($existingRefund)->toBeTrue();
});
