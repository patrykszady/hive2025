<?php

use App\Livewire\Expenses\ExpensesAssociated;
use App\Models\Expense;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The Link Expenses picker only suggested expenses with the same amount within
 * three months, or the same vendor on the same day, so 28701 ($1,500 to GS
 * Construction on 10/07) and 28703 (-$44.99 Sixt refund on 10/06) could never
 * find each other (Patryk, 2026-10-11). Now every expense of the same vendor
 * is suggested, and a search box reaches anything else.
 */
function associatedFixture(): array
{
    $company = Vendor::factory()->create(['business_name' => 'GS Construction & Remodeling, Inc']);
    $sixt = Vendor::factory()->create(['business_name' => 'Sixt Car Rental']);
    $user = User::query()->create([
        'first_name' => 'Patryk', 'last_name' => 'Szady', 'email' => 'associate-'.uniqid().'@example.test',
        'cell_phone' => '2245550001', 'password' => null, 'primary_vendor_id' => $company->id,
    ]);
    $user->vendors()->attach($company->id, ['role_id' => 1]);
    $company->vendors()->attach([$sixt->id]);

    $expense = fn (array $attributes) => Expense::create(array_merge([
        'project_id' => null, 'distribution_id' => null, 'created_by_user_id' => $user->id,
        'belongs_to_vendor_id' => $company->id, 'note' => null, 'invoice' => null,
    ], $attributes));

    $owner = $expense(['amount' => 1500.00, 'date' => '2026-10-07', 'vendor_id' => $company->id]);
    $refund = $expense(['amount' => -44.99, 'date' => '2026-10-06', 'vendor_id' => $sixt->id, 'invoice' => 'SX-7781']);
    $decoy = $expense(['amount' => 1500.00, 'date' => '2026-09-20', 'vendor_id' => $sixt->id]);
    $oldSameVendor = $expense(['amount' => 320.00, 'date' => '2026-03-01', 'vendor_id' => $company->id]);

    return [$user, $owner, $refund, $decoy, $oldSameVendor];
}

it('suggests the usual matches, and finds anything else by id, amount, vendor or invoice', function (): void {
    [$user, $owner, $refund, $decoy, $oldSameVendor] = associatedFixture();
    $this->actingAs($user);

    $component = Livewire::test(ExpensesAssociated::class, ['expense' => $owner])
        ->call('addAssociatedExpense', $owner->id);

    // Suggestions: the same-amount decoy and the same vendor's older expense
    // (newest first), never the refund.
    expect($component->instance()->candidates->pluck('id')->all())->toBe([$decoy->id, $oldSameVendor->id]);

    foreach ([(string) $refund->id, '44.99', '-44.99', 'Sixt', 'SX-7781'] as $term) {
        $ids = $component->set('search', $term)->instance()->candidates->pluck('id')->all();
        expect($ids)->toContain($refund->id)->and($ids)->not->toContain($owner->id);
    }

    expect($component->set('search', 'nothing like this')->instance()->candidates)->toBeEmpty();
});

it('links a searched expense the same way a suggested one is linked', function (): void {
    [$user, $owner, $refund] = associatedFixture();
    $this->actingAs($user);

    Livewire::test(ExpensesAssociated::class, ['expense' => $owner])
        ->call('addAssociatedExpense', $owner->id)
        ->set('search', 'Sixt')
        ->set('associate_expense', $refund->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($owner->fresh()->parent_expense_id)->toBe($refund->id)
        ->and($refund->fresh()->associated_expenses->pluck('id')->all())->toContain($owner->id);
});
