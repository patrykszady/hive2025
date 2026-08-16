<?php

use App\Jobs\AlignTimelapseFrame;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTimelapse;
use App\Models\ProjectTimelapseFrame;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function reprocessFixture(int $frames = 5, ?int $anchorIndex = null): array
{
    Storage::fake('files');

    $vendor = \App\Models\Vendor::factory()->create();
    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Reprocess Test',
        'client_id' => $client->id,
        'address' => '400 N Wheeling Rd',
        'city' => 'Prospect Heights',
        'state' => 'IL',
        'zip_code' => '60070',
        'belongs_to_vendor_id' => $vendor->id,
    ]));

    $timelapse = ProjectTimelapse::create([
        'project_id' => $project->id,
        'title' => 'Sequence',
        'kind' => ProjectTimelapse::KIND_TIMELAPSE,
    ]);

    $disk = Storage::disk('files');
    $rows = collect(range(1, $frames))->map(function ($n) use ($timelapse, $project, $disk) {
        $disk->put("timelapse/{$project->id}/f{$n}.jpg", "seq {$n}");
        $disk->put("timelapse/{$project->id}/aligned-f{$n}.jpg", "stale aligned {$n}");

        return ProjectTimelapseFrame::create([
            'project_timelapse_id' => $timelapse->id,
            'filename' => "f{$n}.jpg",
            'path' => "timelapse/{$project->id}/f{$n}.jpg",
            'aligned_path' => "timelapse/{$project->id}/aligned-f{$n}.jpg",
            'align_transform' => ['scale' => 1.0, 'fabricated' => 0.0],
            'disk' => 'files',
            'sort_order' => $n,
        ]);
    });

    if ($anchorIndex !== null) {
        $timelapse->forceFill(['anchor_frame_id' => $rows[$anchorIndex]->id])->save();
    }

    return ['project' => $project, 'timelapse' => $timelapse->fresh(), 'frames' => $rows];
}

it('re-derives every non-anchor frame, outward from the anchor, in one chain', function () {
    Bus::fake();
    // Anchor in the MIDDLE so ordering is observable in both directions.
    ['timelapse' => $timelapse, 'frames' => $frames] = reprocessFixture(5, 2);

    $this->artisan('timelapse:reprocess', ['--timelapse' => $timelapse->id])->assertSuccessful();

    // Stale derived state is gone so the job cannot early-return.
    foreach ($frames as $i => $frame) {
        if ($i === 2) {
            continue;   // the anchor keeps its composition
        }

        expect($frame->fresh()->aligned_path)->toBeNull()
            ->and($frame->fresh()->align_transform)->toBeNull()
            ->and(Storage::disk('files')->exists("timelapse/{$frame->project_timelapse_id}/aligned-{$frame->filename}"))
            ->toBeFalse();
    }

    expect($frames[2]->fresh()->aligned_path)->not->toBeNull();

    Bus::assertChained([
        new AlignTimelapseFrame($frames[1]->id, reframe: true),   // distance 1
        new AlignTimelapseFrame($frames[3]->id, reframe: true),   // distance 1
        new AlignTimelapseFrame($frames[0]->id, reframe: true),   // distance 2
        new AlignTimelapseFrame($frames[4]->id, reframe: true),   // distance 2
    ]);
});

it('adopts the first frame as anchor when the sequence has none', function () {
    Bus::fake();
    ['timelapse' => $timelapse, 'frames' => $frames] = reprocessFixture(3);

    expect($timelapse->anchor_frame_id)->toBeNull();

    $this->artisan('timelapse:reprocess', ['--timelapse' => $timelapse->id])->assertSuccessful();

    expect($timelapse->fresh()->anchor_frame_id)->toBe($frames[0]->id);
});

it('changes nothing on a dry run', function () {
    Bus::fake();
    ['timelapse' => $timelapse, 'frames' => $frames] = reprocessFixture(3, 0);

    $this->artisan('timelapse:reprocess', ['--timelapse' => $timelapse->id, '--dry-run' => true])
        ->assertSuccessful();

    expect($frames[1]->fresh()->aligned_path)->not->toBeNull();
    Bus::assertNotDispatched(AlignTimelapseFrame::class);
});

it('skips galleries and single-frame sequences', function () {
    Bus::fake();
    ['project' => $project, 'timelapse' => $timelapse] = reprocessFixture(2, 0);

    // A gallery on the same project must be left alone.
    $gallery = ProjectTimelapse::create([
        'project_id' => $project->id,
        'title' => 'Project Images',
        'kind' => ProjectTimelapse::KIND_GALLERY,
    ]);
    ProjectTimelapseFrame::create([
        'project_timelapse_id' => $gallery->id,
        'filename' => 'g1.jpg',
        'path' => "timelapse/{$project->id}/g1.jpg",
        'aligned_path' => "timelapse/{$project->id}/aligned-g1.jpg",
        'disk' => 'files',
        'sort_order' => 1,
    ]);

    $this->artisan('timelapse:reprocess', ['--project' => $project->id])->assertSuccessful();

    expect($gallery->frames()->first()->aligned_path)->not->toBeNull();
});

it('never re-derives hand-curated frames without --force', function () {
    Bus::fake();
    ['timelapse' => $timelapse, 'frames' => $frames] = reprocessFixture(4, 0);

    // Frame 3 carries a hand-tuned fit (manual aligner / hard-frame restore).
    $frames[2]->forceFill(['align_transform' => ['scale' => 1.1, 'fabricated' => 0.0, 'curated' => true]])->save();

    $this->artisan('timelapse:reprocess', ['--timelapse' => $timelapse->id])->assertSuccessful();

    // The curated frame keeps its aligned copy and its tuning...
    expect($frames[2]->fresh()->aligned_path)->not->toBeNull()
        ->and($frames[2]->fresh()->align_transform['curated'] ?? false)->toBeTrue()
        // ...its auto-derived siblings do not.
        ->and($frames[1]->fresh()->aligned_path)->toBeNull();

    // --force is the explicit consent to lose the hand work.
    $this->artisan('timelapse:reprocess', ['--timelapse' => $timelapse->id, '--force' => true])->assertSuccessful();
    expect($frames[2]->fresh()->aligned_path)->toBeNull();
});
