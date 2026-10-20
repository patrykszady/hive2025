<?php

use App\Livewire\Expenses\ExpenseCreate;
use App\Models\Client;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\ExpenseSplits;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Proves App\Livewire\Forms\ExpenseForm::save_splits() still behaves
 * exactly as before it was extracted into the shared
 * App\Services\Expenses\ExpenseSplitsWriter (now also used by the app API's
 * AppExpenseDirectory) — same public method, same signature, same stored
 * values: existing split rows are updated BY ID (not deleted/recreated),
 * and the web's own "D:{id}" distribution selector still works on a split.
 *
 * Uses the same ->instance()->method() pattern as
 * tests/Feature/Security/T4/ExpenseOwnershipTest.php to dodge the Blade
 * view's unrelated "Invalid Livewire child tag name" re-render bug.
 */
function saveSplitsFixture(): array
{
    $vendor = Vendor::factory()->create();
    $admin = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($admin->id, ['role_id' => 1]);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    $projectA = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Job A', 'client_id' => $client->id, 'address' => '1 A St',
        'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601', 'belongs_to_vendor_id' => $vendor->id,
    ]));
    $projectA->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    $projectB = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Job B', 'client_id' => $client->id, 'address' => '2 B St',
        'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601', 'belongs_to_vendor_id' => $vendor->id,
    ]));
    $projectB->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    $distribution = Distribution::create(['name' => 'Owner Draw', 'vendor_id' => $vendor->id]);

    return compact('vendor', 'admin', 'projectA', 'projectB', 'distribution');
}

function saveSplitsComponent(array $fx): ExpenseCreate
{
    return Livewire::actingAs($fx['admin'])
        ->test(ExpenseCreate::class, ['embedded' => true])
        ->instance();
}

it('updates existing split rows by id instead of deleting and recreating them', function () {
    $fx = saveSplitsFixture();
    $merchant = Vendor::factory()->create();

    $expense = Expense::create([
        'amount' => 100, 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'belongs_to_vendor_id' => $fx['vendor']->id, 'created_by_user_id' => $fx['admin']->id,
    ]);
    $splitA = ExpenseSplits::create([
        'expense_id' => $expense->id, 'amount' => 60, 'project_id' => $fx['projectA']->id,
        'belongs_to_vendor_id' => $fx['vendor']->id, 'created_by_user_id' => $fx['admin']->id,
    ]);
    $splitB = ExpenseSplits::create([
        'expense_id' => $expense->id, 'amount' => 40, 'project_id' => $fx['projectB']->id,
        'belongs_to_vendor_id' => $fx['vendor']->id, 'created_by_user_id' => $fx['admin']->id,
    ]);

    $component = saveSplitsComponent($fx);
    // Loads the existing expense (setExpense() needs $this->expense
    // initialized before update() can run) and its splits, same as opening
    // the edit modal on the web would.
    $component->editExpense($expense);
    $component->form->amount = '100.00';
    $component->form->date = now()->format('Y-m-d');
    $component->form->vendor_id = $merchant->id;
    $component->form->project_id = null;
    $component->form->split = true;
    $component->split = true;
    // Shaped exactly as ExpenseSplitsCreate::addSplits() would leave it —
    // each row carrying its persisted id and an 'items' key (even null).
    $component->expense_splits = [
        ['id' => $splitA->id, 'amount' => 70, 'project_id' => $fx['projectA']->id, 'items' => null, 'reimbursment' => 'None', 'note' => 'Updated A'],
        ['id' => $splitB->id, 'amount' => 30, 'project_id' => 'D:'.$fx['distribution']->id, 'items' => null, 'reimbursment' => 'Client', 'note' => null],
    ];

    $component->form->update();

    expect(ExpenseSplits::whereKey($splitA->id)->exists())->toBeTrue()
        ->and(ExpenseSplits::whereKey($splitB->id)->exists())->toBeTrue()
        ->and($expense->splits()->count())->toBe(2);

    $splitA->refresh();
    $splitB->refresh();
    expect((float) $splitA->amount)->toBe(70.0)
        ->and($splitA->note)->toBe('Updated A')
        ->and($splitA->project_id)->toBe($fx['projectA']->id)
        // The web's own "D:{id}" encoding still resolves to a distribution
        // split, exactly as before extraction.
        ->and($splitB->distribution_id)->toBe($fx['distribution']->id)
        ->and($splitB->project_id)->toBeNull()
        ->and($splitB->getRawOriginal('reimbursment'))->toBe('Client')
        // belongs_to_vendor_id is the acting (hub) vendor — unchanged by
        // extraction.
        ->and((int) $splitA->belongs_to_vendor_id)->toBe($fx['vendor']->id);
});

it('deletes every split when the expense is switched from Split to a direct project', function () {
    $fx = saveSplitsFixture();
    $merchant = Vendor::factory()->create();

    $expense = Expense::create([
        'amount' => 100, 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'belongs_to_vendor_id' => $fx['vendor']->id, 'created_by_user_id' => $fx['admin']->id,
    ]);
    ExpenseSplits::create(['expense_id' => $expense->id, 'amount' => 60, 'project_id' => $fx['projectA']->id, 'belongs_to_vendor_id' => $fx['vendor']->id, 'created_by_user_id' => $fx['admin']->id]);
    ExpenseSplits::create(['expense_id' => $expense->id, 'amount' => 40, 'project_id' => $fx['projectB']->id, 'belongs_to_vendor_id' => $fx['vendor']->id, 'created_by_user_id' => $fx['admin']->id]);

    $component = saveSplitsComponent($fx);
    $component->editExpense($expense);
    $component->form->amount = '100.00';
    $component->form->date = now()->format('Y-m-d');
    $component->form->vendor_id = $merchant->id;
    // Switched away from Split onto a direct project.
    $component->form->project_id = $fx['projectA']->id;
    $component->form->split = false;
    $component->split = false;

    $component->form->update();

    expect($expense->splits()->count())->toBe(0)
        ->and($expense->refresh()->project_id)->toBe($fx['projectA']->id);
});
