<?php

use App\Livewire\AppSidebar;
use App\Models\Bank;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows the bank error badge in both sidebar locations', function (): void {
    $vendor = Vendor::factory()->create([
        'business_name' => 'Test Vendor',
    ]);

    $user = new User();
    $user->forceFill([
        'first_name' => 'Sidebar',
        'last_name' => 'Admin',
        'email' => 'sidebar-admin@example.test',
        'cell_phone' => '2245551212',
        'password' => null,
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->save();

    $vendor->users()->attach($user->id, [
        'role_id' => 1,
    ]);

    Bank::query()->create([
        'name' => 'Errored Bank',
        'vendor_id' => $vendor->id,
        'plaid_access_token' => 'access-token',
        'plaid_item_id' => 'item-1',
        'plaid_ins_id' => 'ins-1',
        'plaid_options' => [
            'error' => [
                'error_code' => 'ITEM_LOGIN_REQUIRED',
            ],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(AppSidebar::class)
        ->assertSeeInOrder(['Banks', 'Error', 'Home', 'Banks', 'Error']);
});
