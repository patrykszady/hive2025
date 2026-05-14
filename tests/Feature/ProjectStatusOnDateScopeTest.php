<?php

use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Models\Vendor;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $vendor = Vendor::factory()->create();
    $this->user = User::create([
        'first_name' => 'Test',
        'last_name' => 'Owner',
        'email' => 'owner-' . uniqid() . '@example.com',
        'cell_phone' => (string) random_int(2000000000, 9999999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $this->actingAs($this->user);
    $this->vendorId = $vendor->id;
});

function makeStatus(int $projectId, int $code, string $startDate, int $vendorId): ProjectStatus
{
    return ProjectStatus::create([
        'project_id' => $projectId,
        'belongs_to_vendor_id' => $vendorId,
        'status_code' => $code,
        'start_date' => $startDate,
    ]);
}

function makeProject(): Project
{
    return Project::create([
        'project_name' => 'Test Project ' . uniqid(),
        'client_id' => 1,
        'address' => '123 Main St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => 60601,
    ]);
}

it('includes a project whose latest status on the given date is Active', function () {
    $project = makeProject();
    makeStatus($project->id, 4, '2026-01-01', $this->vendorId); // Prep
    makeStatus($project->id, 6, '2026-02-01', $this->vendorId); // Active
    makeStatus($project->id, 7, '2026-03-01', $this->vendorId); // Complete

    $ids = Project::statusOnDate([6, 8], '2026-02-15')->pluck('id')->all();

    expect($ids)->toContain($project->id);
});

it('excludes a project that was Complete on the given date', function () {
    $project = makeProject();
    makeStatus($project->id, 6, '2026-01-01', $this->vendorId); // Active
    makeStatus($project->id, 7, '2026-02-01', $this->vendorId); // Complete

    $ids = Project::statusOnDate([6, 8], '2026-02-15')->pluck('id')->all();

    expect($ids)->not->toContain($project->id);
});

it('excludes a project whose first status starts after the given date', function () {
    $project = makeProject();
    makeStatus($project->id, 6, '2026-03-01', $this->vendorId); // Active starts later

    $ids = Project::statusOnDate([6, 8], '2026-02-15')->pluck('id')->all();

    expect($ids)->not->toContain($project->id);
});

it('includes a project whose status on the date is Service Call', function () {
    $project = makeProject();
    makeStatus($project->id, 7, '2026-01-01', $this->vendorId); // Complete
    makeStatus($project->id, 8, '2026-02-01', $this->vendorId); // Service Call

    $ids = Project::statusOnDate([6, 8], '2026-02-15')->pluck('id')->all();

    expect($ids)->toContain($project->id);
});
