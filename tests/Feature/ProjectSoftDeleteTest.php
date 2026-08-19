<?php

use App\Models\Client;
use App\Models\Estimate;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Deleting a project must never destroy it.
 *
 * In Aug 2026 the UI's delete called forceDelete(): project 427 and its client
 * were removed outright, absent from every backup, leaving estimate 268
 * pointing at nothing and rendering its number as "1-268". These pin the
 * recoverable behaviour that replaced it.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->vendor = Vendor::factory()->create();
    $this->client = Client::factory()->create();
});

function softDeleteProbeProject(): Project
{
    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Soft Delete Probe',
        'client_id' => test()->client->id,
        'address' => '1 Test Way',
        'city' => 'Prospect Heights',
        'state' => 'IL',
        'zip_code' => '60070',
        'belongs_to_vendor_id' => test()->vendor->id,
    ]));

    // withoutEvents skips the observer's created() hook, so seed the two rows
    // this suite asserts survive a soft delete.
    $project->vendors()->attach(test()->vendor->id, ['client_id' => test()->client->id]);
    \App\Models\ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => test()->vendor->id,
        'status_code' => 2,
        'start_date' => today()->format('Y-m-d'),
    ]);

    return $project;
}

it('soft deletes rather than destroying the row', function () {
    $project = softDeleteProbeProject();

    $project->delete();

    // The row survives — this is the whole point.
    expect(DB::table('projects')->where('id', $project->id)->exists())->toBeTrue()
        ->and(Project::find($project->id))->toBeNull()
        ->and(Project::withTrashed()->find($project->id))->not->toBeNull();
});

it('cascades estimates so they stop showing on /estimates, and restores them with the project', function () {
    $project = softDeleteProbeProject();

    $estimate = Estimate::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $this->vendor->id,
    ]);

    // An estimate disabled on its own, well before the project was deleted.
    $previouslyDisabled = Estimate::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $this->vendor->id,
    ]);
    $previouslyDisabled->delete();
    DB::table('estimates')->where('id', $previouslyDisabled->id)
        ->update(['deleted_at' => now()->subWeek()]);

    $project->delete();

    expect(Estimate::withTrashed()->find($estimate->id)->trashed())->toBeTrue();

    Project::withTrashed()->find($project->id)->restore();

    expect(Project::find($project->id))->not->toBeNull()
        ->and(Estimate::find($estimate->id))->not->toBeNull()
        // Restoring the project must not resurrect what was disabled separately.
        ->and(Estimate::find($previouslyDisabled->id))->toBeNull();
});

it('keeps status history and the vendor pivot while trashed', function () {
    $project = softDeleteProbeProject();

    $statusesBefore = DB::table('project_status')->where('project_id', $project->id)->count();

    $project->delete();

    expect(DB::table('project_status')->where('project_id', $project->id)->count())->toBe($statusesBefore)
        ->and(DB::table('project_vendor')->where('project_id', $project->id)->count())->toBe(1);
});

it('never calls forceDelete from the project delete path', function () {
    // A guard on intent: the form is the only project delete the UI reaches,
    // and this is exactly the line that cost us project 427.
    $source = file_get_contents(app_path('Livewire/Forms/ProjectForm.php'));

    preg_match('/public function delete\(\).*?\n    \}/s', $source, $matches);

    expect($matches[0] ?? '')->not->toBeEmpty()
        ->and($matches[0])->not->toContain('forceDelete');
});
