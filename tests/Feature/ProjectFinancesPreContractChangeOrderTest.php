<?php

use App\Models\Bid;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateSection;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Without a signed contract the Estimate figure is the sum of every section,
 * so a change-order bid tied to one of those sections must not be added on
 * top — project 382 showed Estimate $6,297 + Change Order $3,927 that way.
 */
function financesFixture(): array
{
    $vendor = Vendor::query()->create([
        'business_name' => 'GS Construction', 'business_type' => 'Sub', 'business_email' => 'gc@example.test',
        'address' => '123 Main St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);
    $user = User::query()->create([
        'first_name' => 'Fin', 'last_name' => 'Tester', 'email' => 'finances@example.test',
        'cell_phone' => '7005550102', 'password' => bcrypt('password'),
    ]);
    $user->forceFill(['primary_vendor_id' => $vendor->id])->saveQuietly();
    test()->actingAs($user);

    $project = Project::query()->create([
        'project_name' => 'Family Room',
        'client_id' => Client::query()->create(['business_name' => 'Owner', 'address' => '1 Oak St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601'])->id,
        'address' => '1 Oak St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);
    $project->forceFill(['belongs_to_vendor_id' => $vendor->id])->saveQuietly();

    $estimate = Estimate::withoutGlobalScopes()->create(['project_id' => $project->id, 'belongs_to_vendor_id' => $vendor->id]);
    $familyRoom = EstimateSection::create(['estimate_id' => $estimate->id, 'name' => 'Family Room', 'total' => 3927]);
    EstimateSection::create(['estimate_id' => $estimate->id, 'name' => 'Powder Room', 'total' => 2370]);

    // The stale artefact: a change-order bid for a section of the unsigned estimate.
    $changeOrder = Bid::create(['project_id' => $project->id, 'vendor_id' => $vendor->id, 'type' => 2, 'amount' => 3927]);
    $familyRoom->forceFill(['bid_id' => $changeOrder->id])->saveQuietly();

    return [$project->fresh(), $vendor, $changeOrder];
}

it('does not add a change order on top of the unsigned estimate that already contains its section', function () {
    [$project, $vendor] = financesFixture();

    $finances = $project->financesForVendor($vendor->id);

    expect($finances['estimate'])->toBe(6297.0)
        ->and($finances['change_orders'])->toBe(0.0)
        ->and($finances['total_project'])->toBe(6297.0);
});

it('counts the change order once a signed contract (base bid) exists', function () {
    [$project, $vendor] = financesFixture();
    Bid::create(['project_id' => $project->id, 'vendor_id' => $vendor->id, 'type' => 1, 'amount' => 2370]);

    $finances = $project->fresh()->financesForVendor($vendor->id);

    expect($finances['estimate'])->toBe(2370.0)
        ->and($finances['change_orders'])->toBe(3927.0)
        ->and($finances['total_project'])->toBe(6297.0);
});

it('the migration drops the stale bid and unlinks its section', function () {
    [$project, $vendor, $changeOrder] = financesFixture();

    (require database_path('migrations/2026_09_03_120000_unlink_pre_contract_change_orders.php'))->up();

    expect(Bid::withoutGlobalScopes()->find($changeOrder->id))->toBeNull()
        ->and(EstimateSection::withoutGlobalScopes()->where('estimate_id', $project->estimates()->withoutGlobalScopes()->value('id'))->whereNotNull('bid_id')->count())->toBe(0)
        ->and($project->fresh()->financesForVendor($vendor->id)['total_project'])->toBe(6297.0);
});
