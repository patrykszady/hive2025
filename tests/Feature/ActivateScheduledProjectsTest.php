<?php

use App\Models\Estimate;
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
        'email' => 'owner-'.uniqid().'@example.com',
        'cell_phone' => (string) random_int(2000000000, 9999999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $this->actingAs($this->user);
    $this->vendorId = $vendor->id;
});

function activationProject(int $vendorId, string $scheduledStart, string $estimateStart): Project
{
    $project = Project::create([
        'project_name' => 'Test Project '.uniqid(),
        'client_id' => 1,
        'address' => '123 Main St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => 60601,
    ]);

    // ProjectObserver auto-creates an "Estimate" status row dated today —
    // remove it so the fixture controls the status history completely.
    ProjectStatus::withoutGlobalScopes()->where('project_id', $project->id)->delete();

    ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendorId,
        'status_code' => 5, // Scheduled
        'start_date' => $scheduledStart,
    ]);

    Estimate::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendorId,
        'options' => ['start_date' => $estimateStart],
    ]);

    return $project;
}

it('activates a scheduled project whose estimate start is tomorrow or earlier', function () {
    $this->travelTo('2026-06-29 09:00:00');
    $project = activationProject($this->vendorId, '2026-06-30', '2026-06-30');

    $this->artisan('projects:activate-scheduled')->assertSuccessful();

    $latest = $project->fresh()->latestStatus;
    expect($latest->status_code)->toBe(6)
        ->and($latest->start_date->toDateString())->toBe('2026-06-30');
});

it('heals a stale reschedule so the Active row outranks the old Scheduled date', function () {
    // Scheduled for 7/7, then the estimate was rescheduled to 6/30 without the
    // Scheduled row being updated — the exact freeze that stuck project 373.
    $this->travelTo('2026-06-30 09:00:00');
    $project = activationProject($this->vendorId, '2026-07-07', '2026-06-30');

    $this->artisan('projects:activate-scheduled')->assertSuccessful();

    $latest = $project->fresh()->latestStatus;
    expect($latest->status_code)->toBe(6)
        // the stale Scheduled row was pulled back to the estimate start
        ->and(ProjectStatus::withoutGlobalScopes()->where('project_id', $project->id)->where('status_code', 5)->first()->start_date->toDateString())->toBe('2026-06-30');
});

it('never inserts duplicate Active rows on reruns', function () {
    $this->travelTo('2026-06-30 09:00:00');
    $project = activationProject($this->vendorId, '2026-07-07', '2026-06-30');

    $this->artisan('projects:activate-scheduled')->assertSuccessful();
    $this->artisan('projects:activate-scheduled')->assertSuccessful();
    $this->travelTo('2026-07-01 09:00:00');
    $this->artisan('projects:activate-scheduled')->assertSuccessful();

    expect(ProjectStatus::withoutGlobalScopes()->where('project_id', $project->id)->where('status_code', 6)->count())->toBe(1);
});

it('heals a project already stuck with duplicate Active rows without adding more', function () {
    // Reproduces the live bad state: stale 7/7 Scheduled row + a pile of
    // daily-inserted 6/30 Active rows that never became the latest status.
    $this->travelTo('2026-07-16 09:00:00');
    $project = activationProject($this->vendorId, '2026-07-07', '2026-06-30');
    foreach (range(1, 3) as $i) {
        ProjectStatus::create([
            'project_id' => $project->id,
            'belongs_to_vendor_id' => $this->vendorId,
            'status_code' => 6,
            'start_date' => '2026-06-30',
        ]);
    }

    $this->artisan('projects:activate-scheduled')->assertSuccessful();

    expect($project->fresh()->latestStatus->status_code)->toBe(6)
        ->and(ProjectStatus::withoutGlobalScopes()->where('project_id', $project->id)->where('status_code', 6)->count())->toBe(3);
});

it('does not activate before the estimate start window', function () {
    $this->travelTo('2026-06-20 09:00:00');
    $project = activationProject($this->vendorId, '2026-06-30', '2026-06-30');

    $this->artisan('projects:activate-scheduled')->assertSuccessful();

    expect($project->fresh()->latestStatus->status_code)->toBe(5);
});
