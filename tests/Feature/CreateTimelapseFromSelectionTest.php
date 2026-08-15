<?php

use App\Livewire\Projects\TimelapseStudio;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTimelapse;
use App\Models\ProjectTimelapseFrame;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** An album with $count photos, plus the owning-vendor Admin who may curate. */
function selectionFixture(int $count = 3): array
{
    Storage::fake('files');

    $vendor = Vendor::factory()->create();
    $client = Client::factory()->create();
    $admin = User::factory()->create();
    $admin->primary_vendor_id = $vendor->id;
    $admin->registration = ['registered' => true];
    $admin->save();
    $vendor->users()->attach($admin->id, ['role_id' => 1]);   // 1 = Admin

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Selection Test',
        'client_id' => $client->id,
        'address' => '400 N Wheeling Rd',
        'city' => 'Prospect Heights',
        'state' => 'IL',
        'zip_code' => '60070',
        'belongs_to_vendor_id' => $vendor->id,
    ]));
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    $album = ProjectTimelapse::create([
        'project_id' => $project->id,
        'title' => 'Project Images',
        'kind' => ProjectTimelapse::KIND_GALLERY,
    ]);

    $disk = Storage::disk('files');
    $frames = collect(range(1, $count))->map(function ($n) use ($album, $project, $disk) {
        $name = "photo-{$n}.jpg";
        $disk->put("timelapse/{$project->id}/{$name}", "pixels {$n}");
        $disk->put("timelapse/{$project->id}/original-{$name}", "archive {$n}");

        return ProjectTimelapseFrame::create([
            'project_timelapse_id' => $album->id,
            'filename' => $name,
            'path' => "timelapse/{$project->id}/{$name}",
            'original_path' => "timelapse/{$project->id}/original-{$name}",
            'disk' => 'files',
            // Deliberately NOT in id order — shot order must win.
            'shot_at' => now()->subHours($n),
            'sort_order' => $n,
        ]);
    });

    return compact('project', 'album', 'admin', 'frames');
}

it('builds a timelapse from selected photos, in shot order, copying not moving', function () {
    Bus::fake();
    ['project' => $project, 'album' => $album, 'admin' => $admin, 'frames' => $frames] = selectionFixture(3);

    Livewire::actingAs($admin)
        ->test(TimelapseStudio::class, ['project' => $project])
        ->call('createTimelapseFromSelection', $frames->pluck('id')->all());

    $timelapse = ProjectTimelapse::where('project_id', $project->id)
        ->where('kind', ProjectTimelapse::KIND_TIMELAPSE)->firstOrFail();
    $built = $timelapse->frames()->orderBy('sort_order')->get();

    // The album is untouched — a record of what was shot.
    expect($album->frames()->count())->toBe(3)
        ->and($built)->toHaveCount(3)
        // Oldest first, regardless of the order they were tapped or created.
        ->and($built->pluck('shot_at')->map->timestamp->all())
        ->toBe($frames->sortBy('shot_at')->pluck('shot_at')->map->timestamp->values()->all())
        // Sequence copies are the sequence's own (alignment rewrites them)…
        ->and($built->pluck('path')->intersect($frames->pluck('path'))->all())->toBe([])
        // …while the immutable archive original is shared, so alignment has
        // full resolution and deleting the sequence can't orphan the album.
        ->and($built->pluck('original_path')->sort()->values()->all())
        ->toBe($frames->pluck('original_path')->sort()->values()->all())
        // Frame #1 anchors the sequence.
        ->and($timelapse->fresh()->anchor_frame_id)->toBe($built->first()->id);

    foreach ($built as $frame) {
        expect(Storage::disk('files')->exists($frame->path))->toBeTrue();
    }

    Bus::assertChained([
        new \App\Jobs\AlignTimelapseFrame($built[1]->id, reframe: true),
        new \App\Jobs\AlignTimelapseFrame($built[2]->id, reframe: true),
    ]);
});

it('refuses a selection of fewer than two photos', function () {
    Bus::fake();
    ['project' => $project, 'admin' => $admin, 'frames' => $frames] = selectionFixture(2);

    Livewire::actingAs($admin)
        ->test(TimelapseStudio::class, ['project' => $project])
        ->call('createTimelapseFromSelection', [$frames->first()->id]);

    expect(ProjectTimelapse::where('project_id', $project->id)
        ->where('kind', ProjectTimelapse::KIND_TIMELAPSE)->count())->toBe(0);
    Bus::assertNotDispatched(\App\Jobs\AlignTimelapseFrame::class);
});

it('ignores frames from other projects and is admin-only', function () {
    Bus::fake();
    ['project' => $project, 'admin' => $admin, 'frames' => $frames] = selectionFixture(2);
    ['frames' => $foreign] = selectionFixture(2);

    // A frame from someone else's project cannot be smuggled into the build.
    Livewire::actingAs($admin)
        ->test(TimelapseStudio::class, ['project' => $project])
        ->call('createTimelapseFromSelection', [$frames->first()->id, $foreign->first()->id]);

    expect(ProjectTimelapse::where('project_id', $project->id)
        ->where('kind', ProjectTimelapse::KIND_TIMELAPSE)->count())->toBe(0);

    // And a non-admin viewer may not curate at all.
    $viewer = User::factory()->create();

    Livewire::actingAs($viewer)
        ->test(TimelapseStudio::class, ['project' => $project])
        ->call('createTimelapseFromSelection', $frames->pluck('id')->all())
        ->assertForbidden();
});

it('names each new sequence uniquely', function () {
    Bus::fake();
    ['project' => $project, 'admin' => $admin, 'frames' => $frames] = selectionFixture(2);

    $component = Livewire::actingAs($admin)->test(TimelapseStudio::class, ['project' => $project]);
    $component->call('createTimelapseFromSelection', $frames->pluck('id')->all());
    $component->call('createTimelapseFromSelection', $frames->pluck('id')->all());

    expect(ProjectTimelapse::where('project_id', $project->id)
        ->where('kind', ProjectTimelapse::KIND_TIMELAPSE)
        ->pluck('title')->sort()->values()->all())
        ->toBe(['Timelapse', 'Timelapse 2']);
});
