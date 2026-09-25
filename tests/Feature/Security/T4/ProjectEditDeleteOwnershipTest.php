<?php

use App\Livewire\Projects\DeletedProjectsTable;
use App\Livewire\Projects\ProjectCreate;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Vendor A owns a project. Vendor B is a genuinely invited sub — attached to
 * the project via project_vendor (ProjectScope makes the project visible to
 * them) — with its own Admin who must still not be able to edit/delete/restore
 * a project it doesn't own.
 */
function sec4_projectFixture(): array
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

    $foreignClient = Client::factory()->create();
    $foreignClient->vendors()->attach($vendorB->id);

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Original Project',
        'client_id' => $client->id,
        'address' => '1 Test St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
        'belongs_to_vendor_id' => $vendorA->id,
    ]));
    $project->vendors()->attach($vendorA->id, ['client_id' => $client->id]);
    // Vendor B is genuinely invited onto the project (subcontractor).
    $project->vendors()->attach($vendorB->id, ['client_id' => $client->id]);

    return compact('vendorA', 'vendorB', 'adminA', 'adminB', 'client', 'foreignClient', 'project');
}

it('refuses an invited sub renaming the GC project', function () {
    $fx = sec4_projectFixture();

    Livewire::actingAs($fx['adminB'])
        ->test(ProjectCreate::class)
        ->call('editProject', $fx['project']->id)
        ->set('form.project_name', 'Hijacked Name')
        ->call('edit')
        ->assertForbidden();

    expect($fx['project']->fresh()->project_name)->toBe('Original Project');
});

it('refuses an invited sub deleting the GC project', function () {
    $fx = sec4_projectFixture();

    Livewire::actingAs($fx['adminB'])
        ->test(ProjectCreate::class)
        ->call('editProject', $fx['project']->id)
        ->call('delete')
        ->assertForbidden();

    expect($fx['project']->fresh())->not->toBeNull()
        ->and($fx['project']->fresh()->trashed())->toBeFalse();
});

it('refuses an invited sub restoring the GC project once trashed', function () {
    $fx = sec4_projectFixture();
    $fx['project']->delete();

    Livewire::actingAs($fx['adminB'])
        ->test(DeletedProjectsTable::class)
        ->call('restoreProject', $fx['project']->id)
        ->assertForbidden();

    expect($fx['project']->fresh()->trashed())->toBeTrue();
});

it('refuses re-clienting a project to a client outside the tenant', function () {
    $fx = sec4_projectFixture();

    // ProjectForm::update() is exercised directly (rather than through
    // ProjectCreate::edit()->set('form.client_id', ...)): that path also runs
    // ProjectCreate::updated(), which looks the id up in the (already
    // tenant-scoped) client dropdown and errors out before ever reaching the
    // server-side write this test targets. A raw id can still reach
    // ProjectForm::update() by other routes (e.g. a crafted Livewire
    // payload), which is exactly what this checks.
    $testable = Livewire::actingAs($fx['adminA'])->test(ProjectCreate::class);
    $testable->call('editProject', $fx['project']->id);
    $testable->instance()->form->client_id = $fx['foreignClient']->id;

    expect(fn () => $testable->instance()->form->update())->toThrow(\Illuminate\Validation\ValidationException::class);
    expect((int) $fx['project']->fresh()->client_id)->toBe($fx['client']->id);
});

it('lets the owning vendor edit and delete its own project', function () {
    $fx = sec4_projectFixture();

    Livewire::actingAs($fx['adminA'])
        ->test(ProjectCreate::class)
        ->call('editProject', $fx['project']->id)
        ->set('form.project_name', 'Renamed By Owner')
        ->call('edit')
        ->assertHasNoErrors();

    expect($fx['project']->fresh()->project_name)->toBe('Renamed By Owner');

    // Delete requires no financial rows on the project (policy business rule) — true here.
    Livewire::actingAs($fx['adminA'])
        ->test(ProjectCreate::class)
        ->call('editProject', $fx['project']->id)
        ->call('delete');

    expect($fx['project']->fresh()->trashed())->toBeTrue();
});
