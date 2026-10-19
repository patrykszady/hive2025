<?php

use App\Jobs\ProcessTimelapseFrame;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTimelapse;
use App\Models\ProjectTimelapseFrame;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * AppPaymentsTest's appPaymentsTeamUser().
 *
 * @return array{vendor: Vendor, user: User}
 */
function appJobsPhotosTeamUser(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return compact('vendor', 'user');
}

function appJobsPhotosProject(Vendor $vendor): Project
{
    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Kitchen Remodel',
        'client_id' => $client->id,
        'address' => '123 Main St',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => '60062',
        'belongs_to_vendor_id' => $vendor->id,
    ]));

    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    return $project;
}

it('needs a token to list or upload project photos', function () {
    $this->getJson('/api/app/v1/projects/1/photos')->assertUnauthorized();
    $this->postJson('/api/app/v1/projects/1/photos')->assertUnauthorized();
});

it('lists a project\'s photos with session-less signed URLs', function () {
    Storage::fake('files');

    ['vendor' => $vendor, 'user' => $user] = appJobsPhotosTeamUser();
    Sanctum::actingAs($user, ['app']);

    $project = appJobsPhotosProject($vendor);
    $album = ProjectTimelapse::create(['project_id' => $project->id, 'title' => 'Project Images', 'kind' => ProjectTimelapse::KIND_GALLERY]);

    Storage::disk('files')->put("timelapse/{$project->id}/shot.jpg", 'FAKE-IMAGE-BYTES');
    $frame = ProjectTimelapseFrame::create([
        'project_timelapse_id' => $album->id,
        'filename' => 'shot.jpg',
        'path' => "timelapse/{$project->id}/shot.jpg",
        'disk' => 'files',
        'shot_at' => now(),
        'sort_order' => 1,
    ]);

    $response = $this->getJson("/api/app/v1/projects/{$project->id}/photos")->assertOk();

    $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $frame->id);

    $this->get($response->json('data.0.url'))->assertOk();
    $this->get($response->json('data.0.thumb_url'))->assertOk();
});

it('uploads a new photo exactly like the web\'s camera upload — same storage, deferred processing job', function () {
    Storage::fake('files');
    Queue::fake();

    ['vendor' => $vendor, 'user' => $user] = appJobsPhotosTeamUser();
    Sanctum::actingAs($user, ['app']);

    $project = appJobsPhotosProject($vendor);

    $photo = UploadedFile::fake()->image('progress.jpg', 100, 100);

    $response = $this->postJson("/api/app/v1/projects/{$project->id}/photos", [
        'photo' => $photo,
    ])->assertCreated();

    $frame = ProjectTimelapseFrame::query()->findOrFail($response->json('data.id'));

    expect($frame->disk)->toBe('files')
        ->and($frame->path)->toStartWith("timelapse/{$project->id}/")
        ->and($frame->original_path)->toStartWith("timelapse/{$project->id}/original-")
        ->and($frame->taken_by_user_id)->toBe($user->id)
        ->and($frame->timelapse->project_id)->toBe($project->id)
        ->and($frame->timelapse->kind)->toBe(ProjectTimelapse::KIND_GALLERY);

    Storage::disk('files')->assertExists($frame->original_path);

    Queue::assertPushed(ProcessTimelapseFrame::class, fn ($job) => $job->frameId === $frame->id);
});

it('403s uploading to another vendor\'s project and 404s a nonexistent one', function () {
    ['user' => $user] = appJobsPhotosTeamUser();
    Sanctum::actingAs($user, ['app']);

    $otherVendor = Vendor::factory()->create();
    $otherProject = appJobsPhotosProject($otherVendor);

    $this->getJson("/api/app/v1/projects/{$otherProject->id}/photos")->assertForbidden();
    $this->getJson('/api/app/v1/projects/999999999/photos')->assertNotFound();
});
