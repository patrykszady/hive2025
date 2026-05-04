<?php

use App\Livewire\Expenses\ExpenseIndex;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('marks transactions ready on initial expenses mount', function (): void {
    $vendor = Vendor::factory()->create();
    $user = User::query()->create([
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'expense-index-ready@example.test',
        'cell_phone' => '2245550001',
        'password' => null,
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);

    $this->actingAs($user);

    $component = app(ExpenseIndex::class);
    $component->view = null;
    $component->mount();

    expect($component->transactionsReady)->toBeTrue();
});

it('does not auto-mark transactions ready for embedded non-index views', function (): void {
    $vendor = Vendor::factory()->create();
    $user = User::query()->create([
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'expense-index-not-ready@example.test',
        'cell_phone' => '2245550002',
        'password' => null,
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);

    $this->actingAs($user);

    $component = app(ExpenseIndex::class);
    $component->view = 'projects.show';
    $component->mount();

    expect($component->transactionsReady)->toBeFalse();
});
