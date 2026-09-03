<?php

use App\Models\Client;
use App\Models\Payment;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A standalone payment (no transaction, no parent, no children) returned a
 * base collect([$this]) from Payment::payments while every other case
 * returned an Eloquent collection. grouped_transaction_payments then called
 * ->load() on it and the payment page 500ed: "Method
 * Illuminate\Support\Collection::load does not exist" (2026-09-02).
 */
it('groups a standalone payment without blowing up on ->load()', function () {
    $vendor = Vendor::query()->create([
        'business_name' => 'GS Construction', 'business_type' => 'Sub', 'business_email' => 'gc@example.test',
        'address' => '123 Main St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);
    $user = User::query()->create([
        'first_name' => 'Pay', 'last_name' => 'Tester', 'email' => 'standalone-payment@example.test',
        'cell_phone' => '7005550101', 'password' => bcrypt('password'),
    ]);
    $user->forceFill(['primary_vendor_id' => $vendor->id])->saveQuietly();
    $this->actingAs($user);

    $client = Client::query()->create([
        'business_name' => 'Owner', 'address' => '1 Oak St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);
    $project = Project::query()->create([
        'project_name' => 'Standalone', 'client_id' => $client->id,
        'address' => '1 Oak St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);

    $payment = Payment::create([
        'project_id' => $project->id, 'amount' => 2500.00, 'date' => '2026-09-01',
        'reference' => 'CHK 1042', 'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $user->id,
    ]);

    expect($payment->payments)->toBeInstanceOf(EloquentCollection::class);

    $groups = $payment->grouped_transaction_payments;

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['id'])->toBe($payment->id)
        ->and((float) $groups[0]['totalAmount'])->toBe(2500.0)
        ->and($groups[0]['payments'])->toHaveCount(1);
});
