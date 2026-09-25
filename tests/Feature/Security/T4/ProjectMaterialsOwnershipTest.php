<?php

use App\Livewire\Projects\ProjectMaterials;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function sec4_materialsFixture(): array
{
    Storage::fake('files');

    $vendorA = Vendor::factory()->create();
    $vendorA->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();

    $vendorB = Vendor::factory()->create();
    $vendorB->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();

    $admin = User::factory()->create();
    $admin->primary_vendor_id = $vendorA->id;
    $admin->registration = ['registered' => true];
    $admin->save();
    $vendorA->users()->attach($admin->id, ['role_id' => 1]);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendorA->id);

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Sec4 Materials Project',
        'client_id' => $client->id,
        'address' => '1 Test St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
        'belongs_to_vendor_id' => $vendorA->id,
    ]));
    $project->vendors()->attach($vendorA->id, ['client_id' => $client->id]);

    return compact('vendorA', 'vendorB', 'admin', 'client', 'project');
}

it('refuses a material receipt attributed to an unrelated vendor', function () {
    $fx = sec4_materialsFixture();

    Livewire::actingAs($fx['admin'])
        ->test(ProjectMaterials::class, ['project' => $fx['project']])
        ->set('belongs_to_vendor_id', $fx['vendorB']->id)
        ->set('file', UploadedFile::fake()->create('order.pdf', 10))
        ->call('uploadReceipt')
        ->assertHasErrors('belongs_to_vendor_id');

    expect(Expense::withoutGlobalScopes()->where('belongs_to_vendor_id', $fx['vendorB']->id)->count())->toBe(0);
    expect(\Illuminate\Support\Facades\DB::table('project_vendor')
        ->where('project_id', $fx['project']->id)
        ->where('vendor_id', $fx['vendorB']->id)
        ->exists())->toBeFalse();
});

it('lets a legitimate material receipt attribute to a vendor already linked to the tenant', function () {
    $fx = sec4_materialsFixture();
    $fx['vendorA']->vendors()->attach($fx['vendorB']->id);

    // The OCR pipeline (ReceiptController::extractReceipt -> Azure) is
    // external; fake the flow's document analysis by binding a lightweight
    // stub for the controller so no real HTTP call is made.
    $this->partialMock(\App\Http\Controllers\ReceiptController::class, function ($mock) {
        $mock->shouldReceive('extractReceipt')->andReturn(['fields' => ['total' => 12.5, 'merchant_name' => null]]);
    });
    $this->partialMock(\App\Http\Controllers\CompanyEmailController::class, function ($mock) {
        $mock->shouldReceive('saveExpenseReceipt')->andReturnNull();
    });

    Livewire::actingAs($fx['admin'])
        ->test(ProjectMaterials::class, ['project' => $fx['project']])
        ->set('belongs_to_vendor_id', $fx['vendorB']->id)
        ->set('file', UploadedFile::fake()->create('order.pdf', 10))
        ->call('uploadReceipt')
        ->assertHasNoErrors();

    $expense = Expense::withoutGlobalScopes()->where('project_id', $fx['project']->id)->latest('id')->first();
    expect($expense)->not->toBeNull()
        ->and((int) $expense->belongs_to_vendor_id)->toBe($fx['vendorB']->id);

    expect(\Illuminate\Support\Facades\DB::table('project_vendor')
        ->where('project_id', $fx['project']->id)
        ->where('vendor_id', $fx['vendorB']->id)
        ->exists())->toBeTrue();
});
