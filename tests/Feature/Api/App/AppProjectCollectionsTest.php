<?php

use App\Jobs\AlignTimelapseFrame;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTimelapse;
use App\Models\ProjectTimelapseFrame;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * AppPaymentsTest's appPaymentsTeamUser().
 *
 * @return array{vendor: Vendor, admin: User, member: User}
 */
function appCollectionsTeamUsers(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $admin = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($admin->id, ['role_id' => 1]);
    $member = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($member->id, ['role_id' => 2]);

    return compact('vendor', 'admin', 'member');
}

function appCollectionsProject(Vendor $vendor): Project
{
    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Bathroom Remodel',
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

it('needs a token for every collections endpoint', function () {
    $this->getJson('/api/app/v1/projects/1/collections')->assertUnauthorized();
    $this->postJson('/api/app/v1/projects/1/collections')->assertUnauthorized();
    $this->deleteJson('/api/app/v1/projects/1/collections/1')->assertUnauthorized();
    $this->getJson('/api/app/v1/projects/1/collections/1/frames')->assertUnauthorized();
    $this->postJson('/api/app/v1/projects/1/frames/1/move')->assertUnauthorized();
    $this->deleteJson('/api/app/v1/projects/1/frames/1')->assertUnauthorized();
    $this->postJson('/api/app/v1/projects/1/collections/from-selection')->assertUnauthorized();
});

it('lists collections with the catch-all album first and a signed latest-frame url', function () {
    Storage::fake('files');
    ['vendor' => $vendor, 'member' => $member] = appCollectionsTeamUsers();
    Sanctum::actingAs($member, ['app']);

    $project = appCollectionsProject($vendor);
    $timelapse = ProjectTimelapse::create(['project_id' => $project->id, 'title' => 'Kitchen', 'kind' => ProjectTimelapse::KIND_TIMELAPSE, 'sort_order' => 1]);
    Storage::disk('files')->put("timelapse/{$project->id}/a.jpg", 'a');
    ProjectTimelapseFrame::create([
        'project_timelapse_id' => $timelapse->id, 'filename' => 'a.jpg',
        'path' => "timelapse/{$project->id}/a.jpg", 'disk' => 'files', 'sort_order' => 1,
    ]);

    $response = $this->getJson("/api/app/v1/projects/{$project->id}/collections")->assertOk();

    // Project Images (the auto-created catch-all) leads even though Kitchen
    // was created first.
    $titles = $response->json('data.*.title');
    expect($titles[0])->toBe('Project Images')
        ->and($titles)->toContain('Kitchen');

    $kitchen = collect($response->json('data'))->firstWhere('title', 'Kitchen');
    expect($kitchen['frame_count'])->toBe(1)
        ->and($kitchen['is_timelapse'])->toBeTrue()
        ->and($kitchen['can_delete'])->toBeFalse() // a Member may not curate
        ->and($kitchen['latest_frame']['url'])->toBeString();

    $this->get($kitchen['latest_frame']['url'])->assertOk();

    $album = collect($response->json('data'))->firstWhere('title', 'Project Images');
    expect($album['can_delete'])->toBeFalse(); // never deletable, admin or not
});

it('creates a collection — any viewer, not just an admin — and refuses a duplicate name', function () {
    ['vendor' => $vendor, 'member' => $member] = appCollectionsTeamUsers();
    Sanctum::actingAs($member, ['app']);
    $project = appCollectionsProject($vendor);

    $response = $this->postJson("/api/app/v1/projects/{$project->id}/collections", ['title' => 'Backyard'])
        ->assertCreated();

    expect($response->json('data.title'))->toBe('Backyard');

    $this->postJson("/api/app/v1/projects/{$project->id}/collections", ['title' => 'Backyard'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('title');

    expect(ProjectTimelapse::where('project_id', $project->id)->where('title', 'Backyard')->count())->toBe(1);
});

it('deletes a collection for an admin only, and never the catch-all album', function () {
    ['vendor' => $vendor, 'admin' => $admin, 'member' => $member] = appCollectionsTeamUsers();
    $project = appCollectionsProject($vendor);
    $timelapse = ProjectTimelapse::create(['project_id' => $project->id, 'title' => 'Kitchen', 'kind' => ProjectTimelapse::KIND_TIMELAPSE]);
    $album = ProjectTimelapse::generalFor($project);

    Sanctum::actingAs($member, ['app']);
    $this->deleteJson("/api/app/v1/projects/{$project->id}/collections/{$timelapse->id}")->assertForbidden();

    Sanctum::actingAs($admin, ['app']);
    $this->deleteJson("/api/app/v1/projects/{$project->id}/collections/{$album->id}")->assertForbidden();
    $this->deleteJson("/api/app/v1/projects/{$project->id}/collections/{$timelapse->id}")->assertOk();

    expect(ProjectTimelapse::find($timelapse->id))->toBeNull()
        ->and(ProjectTimelapse::withTrashed()->find($timelapse->id)->trashed())->toBeTrue()
        ->and(ProjectTimelapse::find($album->id))->not->toBeNull();
});

it('lists a collection\'s frames in play order for native playback', function () {
    Storage::fake('files');
    ['vendor' => $vendor, 'member' => $member] = appCollectionsTeamUsers();
    Sanctum::actingAs($member, ['app']);
    $project = appCollectionsProject($vendor);

    $timelapse = ProjectTimelapse::create(['project_id' => $project->id, 'title' => 'Back Wall', 'kind' => ProjectTimelapse::KIND_TIMELAPSE]);
    foreach (['c', 'a', 'b'] as $i => $name) {
        Storage::disk('files')->put("timelapse/{$project->id}/{$name}.jpg", $name);
        ProjectTimelapseFrame::create([
            'project_timelapse_id' => $timelapse->id, 'filename' => "{$name}.jpg",
            'path' => "timelapse/{$project->id}/{$name}.jpg", 'disk' => 'files',
            'sort_order' => ['c' => 3, 'a' => 1, 'b' => 2][$name],
        ]);
    }

    $response = $this->getJson("/api/app/v1/projects/{$project->id}/collections/{$timelapse->id}/frames")->assertOk();

    expect($response->json('data'))->toHaveCount(3);
    foreach ($response->json('data') as $frame) {
        $this->get($frame['url'])->assertOk();
    }
});

it('moves and deletes frames for an admin only', function () {
    Storage::fake('files');
    ['vendor' => $vendor, 'admin' => $admin, 'member' => $member] = appCollectionsTeamUsers();
    $project = appCollectionsProject($vendor);
    $timelapse = ProjectTimelapse::create(['project_id' => $project->id, 'title' => 'Back Wall', 'kind' => ProjectTimelapse::KIND_TIMELAPSE]);

    $frames = collect(['a', 'b'])->map(function ($name, $i) use ($project, $timelapse) {
        Storage::disk('files')->put("timelapse/{$project->id}/{$name}.jpg", $name);

        return ProjectTimelapseFrame::create([
            'project_timelapse_id' => $timelapse->id, 'filename' => "{$name}.jpg",
            'path' => "timelapse/{$project->id}/{$name}.jpg", 'disk' => 'files', 'sort_order' => $i + 1,
        ]);
    });

    Sanctum::actingAs($member, ['app']);
    $this->postJson("/api/app/v1/projects/{$project->id}/frames/{$frames[1]->id}/move", ['direction' => -1])->assertForbidden();
    $this->deleteJson("/api/app/v1/projects/{$project->id}/frames/{$frames[0]->id}")->assertForbidden();

    Sanctum::actingAs($admin, ['app']);
    $this->postJson("/api/app/v1/projects/{$project->id}/frames/{$frames[1]->id}/move", ['direction' => 2])
        ->assertStatus(422)
        ->assertJsonValidationErrors('direction');

    $this->postJson("/api/app/v1/projects/{$project->id}/frames/{$frames[1]->id}/move", ['direction' => -1])->assertOk();
    expect($timelapse->frames()->orderBy('sort_order')->pluck('filename')->all())->toBe(['b.jpg', 'a.jpg']);

    $this->deleteJson("/api/app/v1/projects/{$project->id}/frames/{$frames[0]->id}")->assertOk();
    expect(ProjectTimelapseFrame::find($frames[0]->id))->toBeNull();
});

it('builds a timelapse from a selection of at least two photos, admin-only', function () {
    Bus::fake();
    Storage::fake('files');
    ['vendor' => $vendor, 'admin' => $admin, 'member' => $member] = appCollectionsTeamUsers();
    $project = appCollectionsProject($vendor);
    $album = ProjectTimelapse::generalFor($project);

    $frames = collect(['1', '2'])->map(function ($name) use ($project, $album) {
        Storage::disk('files')->put("timelapse/{$project->id}/photo-{$name}.jpg", $name);
        Storage::disk('files')->put("timelapse/{$project->id}/original-photo-{$name}.jpg", "archive {$name}");

        return ProjectTimelapseFrame::create([
            'project_timelapse_id' => $album->id, 'filename' => "photo-{$name}.jpg",
            'path' => "timelapse/{$project->id}/photo-{$name}.jpg",
            'original_path' => "timelapse/{$project->id}/original-photo-{$name}.jpg",
            'disk' => 'files', 'shot_at' => now()->subMinutes((int) $name), 'sort_order' => (int) $name,
        ]);
    });

    Sanctum::actingAs($member, ['app']);
    $this->postJson("/api/app/v1/projects/{$project->id}/collections/from-selection", ['frame_ids' => $frames->pluck('id')->all()])
        ->assertForbidden();

    Sanctum::actingAs($admin, ['app']);
    $this->postJson("/api/app/v1/projects/{$project->id}/collections/from-selection", ['frame_ids' => [$frames->first()->id]])
        ->assertStatus(422)
        ->assertJson(['reason' => 'too_few']);

    $response = $this->postJson("/api/app/v1/projects/{$project->id}/collections/from-selection", ['frame_ids' => $frames->pluck('id')->all()])
        ->assertCreated();

    expect($response->json('data.kind'))->toBe(ProjectTimelapse::KIND_TIMELAPSE)
        ->and($response->json('data.frame_count'))->toBe(2);

    Bus::assertChained([
        AlignTimelapseFrame::class,
    ]);
});

it('403s and 404s out-of-scope projects the same way the photos endpoint does', function () {
    ['member' => $member] = appCollectionsTeamUsers();
    Sanctum::actingAs($member, ['app']);

    $otherVendor = Vendor::factory()->create();
    $otherProject = appCollectionsProject($otherVendor);

    $this->getJson("/api/app/v1/projects/{$otherProject->id}/collections")->assertForbidden();
    $this->getJson('/api/app/v1/projects/999999999/collections')->assertNotFound();
});

it('uploads a captured photo into the chosen collection, falling back to the catch-all album', function () {
    Storage::fake('files');
    ['vendor' => $vendor, 'member' => $member] = appCollectionsTeamUsers();
    Sanctum::actingAs($member, ['app']);
    $project = appCollectionsProject($vendor);

    $kitchen = ProjectTimelapse::create(['project_id' => $project->id, 'title' => 'Kitchen', 'kind' => ProjectTimelapse::KIND_TIMELAPSE]);

    $response = $this->postJson("/api/app/v1/projects/{$project->id}/photos", [
        'photo' => \Illuminate\Http\UploadedFile::fake()->image('progress.jpg', 100, 100),
        'collection_id' => $kitchen->id,
    ])->assertCreated();

    $frame = ProjectTimelapseFrame::findOrFail($response->json('data.id'));
    expect($frame->project_timelapse_id)->toBe($kitchen->id)
        ->and($response->json('data.collection_id'))->toBe($kitchen->id);

    // A foreign project's collection id falls back to the catch-all album.
    $foreignProject = appCollectionsProject(Vendor::factory()->create());
    $foreign = ProjectTimelapse::create(['project_id' => $foreignProject->id, 'title' => 'Not Yours', 'kind' => ProjectTimelapse::KIND_TIMELAPSE]);

    $second = $this->postJson("/api/app/v1/projects/{$project->id}/photos", [
        'photo' => \Illuminate\Http\UploadedFile::fake()->image('progress2.jpg', 100, 100),
        'collection_id' => $foreign->id,
    ])->assertCreated();

    $secondFrame = ProjectTimelapseFrame::findOrFail($second->json('data.id'));
    expect($secondFrame->timelapse->title)->toBe('Project Images');
});
