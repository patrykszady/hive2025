<?php

use App\Livewire\Expenses\ExpenseCreate;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Two independent vendors, each with an Admin, plus a project owned by
 * vendor A. Vendor B is a total stranger — not linked to A via vendors_vendor,
 * not on A's project.
 *
 * NOTE: ExpenseCreate's Blade view (resources/views/livewire/expenses/form.blade.php)
 * has a pre-existing, unrelated bug — its nested `<livewire:expenses.expense-splits-create
 * :wire:key="...">` tag throws "Invalid Livewire child tag name" on any SECOND render
 * within a Livewire::test() call/set cycle (reproduced even with none of this track's
 * changes in play). To exercise the real component logic (where the fixes under test
 * live) without tripping that unrelated view bug, these tests call the mounted
 * instance's public methods directly (`->instance()->save()`) instead of going through
 * Livewire::test()->call(), which never triggers Livewire's own re-render step.
 */
function sec4_expenseFixture(): array
{
    $vendorA = Vendor::factory()->create();
    $vendorA->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();

    $vendorB = Vendor::factory()->create();
    $vendorB->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();

    $makeAdmin = function (Vendor $vendor) {
        $user = User::factory()->create();
        $user->primary_vendor_id = $vendor->id;
        $user->registration = ['registered' => true];
        $user->save();
        $vendor->users()->attach($user->id, ['role_id' => 1]);

        return $user;
    };

    $adminA = $makeAdmin($vendorA);
    $adminB = $makeAdmin($vendorB);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendorA->id);

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Sec4 Expense Project',
        'client_id' => $client->id,
        'address' => '1 Test St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
        'belongs_to_vendor_id' => $vendorA->id,
    ]));
    $project->vendors()->attach($vendorA->id, ['client_id' => $client->id]);

    return compact('vendorA', 'vendorB', 'adminA', 'adminB', 'client', 'project');
}

function sec4_expenseComponent(array $fx): \App\Livewire\Expenses\ExpenseCreate
{
    return Livewire::actingAs($fx['adminA'])
        ->test(ExpenseCreate::class, ['embedded' => true])
        ->instance();
}

it('refuses a material-order expense attributed to an unrelated vendor', function () {
    $fx = sec4_expenseFixture();
    $merchant = Vendor::factory()->create();
    $component = sec4_expenseComponent($fx);

    $component->form->is_material_order = true;
    $component->form->belongs_to_vendor_id = $fx['vendorB']->id;
    $component->form->amount = '100.00';
    $component->form->date = now()->format('Y-m-d');
    $component->form->vendor_id = $merchant->id;
    $component->form->project_id = $fx['project']->id;

    expect(fn () => $component->save())->toThrow(ValidationException::class);

    expect(Expense::withoutGlobalScopes()->where('belongs_to_vendor_id', $fx['vendorB']->id)->count())->toBe(0);

    // No cross-vendor pivot was written either.
    expect(\Illuminate\Support\Facades\DB::table('project_vendor')
        ->where('project_id', $fx['project']->id)
        ->where('vendor_id', $fx['vendorB']->id)
        ->exists())->toBeFalse();
});

it('refuses an expense on a project outside the tenant', function () {
    $fx = sec4_expenseFixture();
    $merchant = Vendor::factory()->create();

    $otherClient = Client::factory()->create();
    $otherClient->vendors()->attach($fx['vendorB']->id);
    $foreignProject = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Vendor B Project',
        'client_id' => $otherClient->id,
        'address' => '2 Test St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
        'belongs_to_vendor_id' => $fx['vendorB']->id,
    ]));
    $foreignProject->vendors()->attach($fx['vendorB']->id, ['client_id' => $otherClient->id]);

    $component = sec4_expenseComponent($fx);
    $component->form->amount = '50.00';
    $component->form->date = now()->format('Y-m-d');
    $component->form->vendor_id = $merchant->id;
    $component->form->project_id = $foreignProject->id;

    expect(fn () => $component->save())->toThrow(ValidationException::class);

    expect(Expense::withoutGlobalScopes()->where('project_id', $foreignProject->id)->count())->toBe(0);
});

it('lets a legitimate material order attribute an expense to a linked vendor and invites them to the project', function () {
    $fx = sec4_expenseFixture();
    $merchant = Vendor::factory()->create();

    // Vendor A has legitimately linked Vendor B (e.g. a registered sub).
    $fx['vendorA']->vendors()->attach($fx['vendorB']->id);

    $component = sec4_expenseComponent($fx);
    $component->form->is_material_order = true;
    $component->form->belongs_to_vendor_id = $fx['vendorB']->id;
    $component->form->amount = '250.00';
    $component->form->date = now()->format('Y-m-d');
    $component->form->vendor_id = $merchant->id;
    $component->form->project_id = $fx['project']->id;
    $component->save();

    $expense = Expense::withoutGlobalScopes()->where('project_id', $fx['project']->id)->latest('id')->first();

    expect($expense)->not->toBeNull()
        ->and((int) $expense->belongs_to_vendor_id)->toBe($fx['vendorB']->id);

    // Vendor B was invited onto the project.
    expect(\Illuminate\Support\Facades\DB::table('project_vendor')
        ->where('project_id', $fx['project']->id)
        ->where('vendor_id', $fx['vendorB']->id)
        ->exists())->toBeTrue();
});

it('lets a plain expense on the hub vendor save normally', function () {
    $fx = sec4_expenseFixture();
    $merchant = Vendor::factory()->create();

    $component = sec4_expenseComponent($fx);
    $component->form->amount = '75.00';
    $component->form->date = now()->format('Y-m-d');
    $component->form->vendor_id = $merchant->id;
    $component->form->project_id = $fx['project']->id;
    $component->save();

    $expense = Expense::where('project_id', $fx['project']->id)->latest('id')->first();
    expect($expense)->not->toBeNull()
        ->and((int) $expense->belongs_to_vendor_id)->toBe($fx['vendorA']->id);
});

it('refuses uploadReceipt attributed to an unrelated vendor', function () {
    $fx = sec4_expenseFixture();

    $component = sec4_expenseComponent($fx);
    $component->upload_file = \Illuminate\Http\UploadedFile::fake()->create('receipt.pdf', 10);
    $component->upload_is_material_order = true;
    $component->upload_belongs_to_vendor_id = $fx['vendorB']->id;

    $component->uploadReceipt();

    expect($component->getErrorBag()->has('upload_belongs_to_vendor_id'))->toBeTrue()
        ->and(Expense::withoutGlobalScopes()->where('belongs_to_vendor_id', $fx['vendorB']->id)->count())->toBe(0);
});
