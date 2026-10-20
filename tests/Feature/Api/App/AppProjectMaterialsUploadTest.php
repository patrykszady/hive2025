<?php

use App\Models\Client;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
    Storage::fake('files');
});

/** @return array{vendor: Vendor, user: User} */
function appJobsMaterialsTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $vendor->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

function appJobsMaterialsProject(Vendor $vendor): Project
{
    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Materials Test',
        'client_id' => $client->id,
        'address' => '1 Oak St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
        'belongs_to_vendor_id' => $vendor->id,
    ]));
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    return $project;
}

it('needs a token to upload a materials receipt', function () {
    $this->postJson('/api/app/v1/projects/1/materials', [])->assertUnauthorized();
});

it('rejects a missing file, like the web\'s rules()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appJobsMaterialsTeamUser();
    Sanctum::actingAs($admin, ['app']);

    $project = appJobsMaterialsProject($vendor);

    $this->postJson("/api/app/v1/projects/{$project->id}/materials", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});

it('blocks a Member, same viewMaterials gate as the index', function () {
    ['vendor' => $vendor, 'user' => $member] = appJobsMaterialsTeamUser(2);
    Sanctum::actingAs($member, ['app']);

    $project = appJobsMaterialsProject($vendor);

    $this->post("/api/app/v1/projects/{$project->id}/materials", [
        'file' => UploadedFile::fake()->create('order.pdf', 10),
    ])->assertForbidden();
});

it('OCRs the receipt and creates a material-order Expense, like ProjectMaterials::uploadReceipt()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appJobsMaterialsTeamUser();
    Sanctum::actingAs($admin, ['app']);

    $project = appJobsMaterialsProject($vendor);

    $this->partialMock(\App\Http\Controllers\ReceiptController::class, function ($mock) {
        $mock->shouldReceive('extractReceipt')->andReturn(['fields' => ['total' => 42.5, 'merchant_name' => null, 'invoice_number' => 'INV-1']]);
    });
    $this->partialMock(\App\Http\Controllers\CompanyEmailController::class, function ($mock) {
        $mock->shouldReceive('saveExpenseReceipt')->andReturnNull();
    });

    // saveExpenseReceipt (the piece that persists the ExpenseReceipts row
    // with is_material_order=true) is mocked away, same as
    // ProjectMaterialsOwnershipTest's "legitimate" case — so the response's
    // `data` is correctly null (AppProjectMaterialsReader::one() only
    // returns a row once a material-order receipt exists); what matters
    // here is the Expense row itself, which is real.
    $this->post("/api/app/v1/projects/{$project->id}/materials", [
        'file' => UploadedFile::fake()->create('order.pdf', 10),
    ])->assertCreated();

    $expense = Expense::withoutGlobalScopes()->where('project_id', $project->id)->latest('id')->first();
    expect($expense)->not->toBeNull()
        ->and((float) $expense->amount)->toBe(42.5)
        ->and((string) $expense->invoice)->toBe('INV-1')
        ->and((int) $expense->belongs_to_vendor_id)->toBe($vendor->id)
        ->and((int) $expense->created_by_user_id)->toBe($admin->id);
});

it('refuses a receipt attributed to an unrelated vendor, same guard as the web', function () {
    ['vendor' => $vendor, 'user' => $admin] = appJobsMaterialsTeamUser();
    Sanctum::actingAs($admin, ['app']);

    $project = appJobsMaterialsProject($vendor);
    $unrelatedVendor = Vendor::factory()->create();

    $this->postJson("/api/app/v1/projects/{$project->id}/materials", [
        'belongs_to_vendor_id' => $unrelatedVendor->id,
        'file' => UploadedFile::fake()->create('order.pdf', 10),
    ])->assertStatus(422)->assertJsonValidationErrors(['belongs_to_vendor_id']);

    expect(Expense::withoutGlobalScopes()->where('belongs_to_vendor_id', $unrelatedVendor->id)->count())->toBe(0);
});
