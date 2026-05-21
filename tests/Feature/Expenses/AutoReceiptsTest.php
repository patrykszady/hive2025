<?php

use App\Livewire\Expenses\AutoReceipts;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function autoReceiptsUser(): User
{
    $vendor = Vendor::factory()->create();

    $user = User::query()->create([
        'first_name' => 'Auto',
        'last_name' => 'Receipts',
        'email' => 'auto-receipts-'.uniqid().'@example.test',
        'cell_phone' => '224' . rand(1000000, 9999999),
        'password' => null,
        'primary_vendor_id' => $vendor->id,
    ]);
    // role_id 1 = Admin
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);

    return $user;
}

function makeReceiptFor(User $user, string $filename, int $minutesAgo): ExpenseReceipts
{
    $expense = Expense::query()->create([
        'amount' => 12.34,
        'date' => now()->subDay(),
        'vendor_id' => $user->vendor->id,
        'belongs_to_vendor_id' => $user->vendor->id,
        'created_by_user_id' => $user->id,
    ]);

    $receipt = new ExpenseReceipts([
        'expense_id' => $expense->id,
        'receipt_filename' => $filename,
        'is_material_order' => false,
    ]);
    $receipt->timestamps = false;
    $receipt->created_at = now()->subMinutes($minutesAgo);
    $receipt->updated_at = now()->subMinutes($minutesAgo);
    $receipt->save();

    return $receipt;
}

it('lists auto-fetched receipts newest first and paginates one at a time', function () {
    $user = autoReceiptsUser();
    $this->actingAs($user);

    $oldest = makeReceiptFor($user, 'oldest.pdf', 300);
    $middle = makeReceiptFor($user, 'middle.pdf', 200);
    $newest = makeReceiptFor($user, 'newest.pdf', 100);

    Livewire::test(AutoReceipts::class)
        ->assertSet('position', 1)
        ->assertSee('Receipt 1 of 3', false)
        ->assertSee('Batch 1 of 3', false)
        ->assertSee('newest.pdf')
        ->call('next')
        ->assertSet('position', 2)
        ->assertSee('middle.pdf')
        ->call('next')
        ->assertSet('position', 3)
        ->assertSee('oldest.pdf')
        ->call('next')
        ->assertSet('position', 3) // clamps at end
        ->call('previous')
        ->assertSet('position', 2)
        ->assertSee('middle.pdf')
        ->call('goTo', 1)
        ->assertSee('newest.pdf');
});

it('shows an empty-state message when no receipts exist', function () {
    $user = autoReceiptsUser();
    $this->actingAs($user);

    Livewire::test(AutoReceipts::class)
        ->assertSee('No auto-fetched receipts found');
});

it('does not show receipts belonging to other vendors', function () {
    $userA = autoReceiptsUser();
    $userB = autoReceiptsUser();

    makeReceiptFor($userB, 'other-vendor.pdf', 5);

    $this->actingAs($userA);

    Livewire::test(AutoReceipts::class)
        ->assertSee('No auto-fetched receipts found')
        ->assertDontSee('other-vendor.pdf');
});
