<?php

use App\Livewire\ProjectStatus\StatusCreate;
use App\Models\Estimate;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Models\Vendor;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $vendor = Vendor::factory()->create();
    $this->user = User::create([
        'first_name' => 'Test',
        'last_name' => 'Owner',
        'email' => 'owner-'.uniqid().'@example.com',
        'cell_phone' => (string) random_int(2000000000, 9999999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $this->actingAs($this->user);
    $this->vendorId = $vendor->id;
});

function supersedeProject(int $vendorId, string $scheduledStart): Project
{
    $project = Project::create([
        'project_name' => 'Test Project '.uniqid(),
        'client_id' => 1,
        'address' => '123 Main St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => 60601,
    ]);

    ProjectStatus::withoutGlobalScopes()->where('project_id', $project->id)->delete();

    ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendorId,
        'status_code' => 5,
        'start_date' => $scheduledStart,
    ]);

    Estimate::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendorId,
        'options' => ['start_date' => $scheduledStart],
    ]);

    return $project;
}

function supersedeStartDate(Project $project, int $statusCode): string
{
    return ProjectStatus::withoutGlobalScopes()
        ->where('project_id', $project->id)
        ->where('status_code', $statusCode)
        ->firstOrFail()
        ->start_date
        ->toDateString();
}

it('lets a status set today outrank the Active row the nightly job dated tomorrow', function () {
    $this->travelTo('2026-10-20 09:00:00');
    $project = supersedeProject($this->vendorId, '2026-10-21');

    $this->artisan('projects:activate-scheduled')->assertSuccessful();
    expect($project->fresh()->latestStatus->status_code)->toBe(6);

    $component = Livewire::test(StatusCreate::class, ['project' => $project])
        ->set('project_status', 4)
        ->call('update_project');

    expect($project->fresh()->latestStatus->status_code)->toBe(4)
        ->and($component->get('statuses')->last()->status_code)->toBe(4)
        ->and(supersedeStartDate($project, 5))->toBe('2026-10-20')
        ->and(supersedeStartDate($project, 6))->toBe('2026-10-20');

    $this->travelTo('2026-10-21 09:00:00');
    $this->artisan('projects:activate-scheduled')->assertSuccessful();

    expect($project->fresh()->latestStatus->status_code)->toBe(4);
});

it('leaves later-dated rows alone for backdated and future-dated statuses', function () {
    $this->travelTo('2026-10-20 09:00:00');
    $project = supersedeProject($this->vendorId, '2026-10-27');

    ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $this->vendorId,
        'status_code' => 4,
        'start_date' => '2026-10-15',
    ]);
    ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $this->vendorId,
        'status_code' => 6,
        'start_date' => '2026-10-25',
    ]);

    expect(supersedeStartDate($project, 5))->toBe('2026-10-27')
        ->and($project->fresh()->latestStatus->status_code)->toBe(5);
});

it('never moves another vendor\'s status history', function () {
    $this->travelTo('2026-10-20 09:00:00');
    $project = supersedeProject($this->vendorId, '2026-10-27');
    $otherVendor = Vendor::factory()->create();

    $otherRow = ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $otherVendor->id,
        'status_code' => 11,
        'start_date' => '2026-10-27',
    ]);

    ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $this->vendorId,
        'status_code' => 4,
        'start_date' => '2026-10-20',
    ]);

    expect(supersedeStartDate($project, 5))->toBe('2026-10-20')
        ->and(ProjectStatus::withoutGlobalScopes()->find($otherRow->id)->start_date->toDateString())->toBe('2026-10-27');
});
