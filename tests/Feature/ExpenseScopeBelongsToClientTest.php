<?php

use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeAdminUserForVendor(Vendor $vendor): User
{
    static $i = 0;
    $i++;

    $user = User::query()->create([
        'first_name' => 'Admin',
        'last_name' => 'User'.$i,
        'email' => 'admin'.$i.'@example.com',
        'cell_phone' => 2249000000 + $i,
        'primary_vendor_id' => $vendor->id,
        'vendor_role' => 'Admin',
    ]);

    $user->vendors()->attach($vendor->id);

    return $user;
}

it('hides expenses with belongs_to_client_id set from the vendor expense scope', function () {
    $vendor = Vendor::query()->create(['business_name' => 'Test Vendor Co', 'business_type' => 'Contractor']);
    $user = makeAdminUserForVendor($vendor);
    auth()->login($user);

    $client = \App\Models\Client::withoutGlobalScopes()->create(['address' => '1 Test St']);
    $project = Project::query()->create([
        'project_name' => 'Test Project',
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '1 Test St',
        'city' => 'Test',
        'state' => 'IL',
        'zip_code' => '60000',
    ]);

    $vendorOnlyExpense = Expense::query()->create([
        'amount' => 100.00,
        'date' => now()->toDateString(),
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $user->id,
    ]);

    $clientOwnedExpense = Expense::query()->create([
        'amount' => 200.00,
        'date' => now()->toDateString(),
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'belongs_to_vendor_id' => $vendor->id,
        'belongs_to_client_id' => 999,
        'created_by_user_id' => $user->id,
    ]);

    $visible = Expense::query()->pluck('id')->all();

    expect($visible)->toContain($vendorOnlyExpense->id)
        ->not->toContain($clientOwnedExpense->id);
});

it('still finds client-owned expenses when bypassing the scope', function () {
    $vendor = Vendor::query()->create(['business_name' => 'Test Vendor Co 2', 'business_type' => 'Contractor']);
    $user = makeAdminUserForVendor($vendor);
    auth()->login($user);

    $client = \App\Models\Client::withoutGlobalScopes()->create(['address' => '2 Test St']);
    $project = Project::query()->create([
        'project_name' => 'Material Project',
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '2 Test St',
        'city' => 'Test',
        'state' => 'IL',
        'zip_code' => '60000',
    ]);

    $clientOwnedExpense = Expense::query()->create([
        'amount' => 500.00,
        'date' => now()->toDateString(),
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'belongs_to_vendor_id' => $vendor->id,
        'belongs_to_client_id' => 777,
        'created_by_user_id' => $user->id,
    ]);

    $found = Expense::withoutGlobalScope(\App\Scopes\ExpenseScope::class)
        ->where('id', $clientOwnedExpense->id)
        ->first();

    expect($found)->not->toBeNull()
        ->and($found->belongs_to_client_id)->toBe(777);
});
