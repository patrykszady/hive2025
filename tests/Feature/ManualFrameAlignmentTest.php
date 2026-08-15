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
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

/*
 * The studio's manual aligner: a human pans/zooms a frame over the anchor
 * (onion skin) and the transform is applied verbatim — the automatic pass
 * only ever removes minor handheld wobble, and re-framing is a human's call.
 */

function manualAlignFixture(string $role = 'Admin'): array
{
    Storage::fake('files');

    $vendor = Vendor::factory()->create();
    $vendor->forceFill([
        'business_type' => 'LLC',
        'registration' => ['registered' => true],
    ])->save();

    $user = new User();
    $user->forceFill([
        'first_name' => 'Crew',
        'last_name' => 'Member',
        'email' => 'manual.align.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => $role === 'Admin' ? 1 : 2]);

    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Manual Align Test',
        'client_id' => $client->id,
        'address' => '400 N Wheeling Rd',
        'city' => 'Prospect Heights',
        'state' => 'IL',
        'zip_code' => '60070',
        'belongs_to_vendor_id' => $vendor->id,
    ]));
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    $timelapse = ProjectTimelapse::create([
        'project_id' => $project->id,
        'title' => 'Back',
        'kind' => 'timelapse',
    ]);

    return ['vendor' => $vendor, 'user' => $user, 'project' => $project, 'timelapse' => $timelapse];
}

function manualAlignFrameRow(ProjectTimelapse $timelapse, string $filename, int $sortOrder): ProjectTimelapseFrame
{
    return ProjectTimelapseFrame::create([
        'project_timelapse_id' => $timelapse->id,
        'filename' => $filename,
        'path' => "timelapse/{$timelapse->project_id}/{$filename}",
        'disk' => 'files',
        'sort_order' => $sortOrder,
    ]);
}

function manualAlignPython(): ?string
{
    $python = (string) config('services.timelapse_align.python');

    if (! is_executable($python)) {
        return null;
    }

    $probe = new Process([$python, '-c', 'import cv2']);
    $probe->run();

    return $probe->isSuccessful() ? $python : null;
}

it('applies the pan/zoom/turn a human set and records the aligned copy', function () {
    $python = manualAlignPython();

    if ($python === null) {
        $this->markTestSkipped('OpenCV venv not available on this machine.');
    }

    $fx = manualAlignFixture();
    $disk = Storage::disk('files');
    $dir = $disk->path("timelapse/{$fx['project']->id}");
    @mkdir($dir, 0777, true);

    // A textured anchor and a copy whose content sits 40px left / 25px up of
    // it — the "stance moved" shot the AUTOMATIC pass refuses to re-frame.
    $gen = new Process([$python, '-c', <<<PY
import cv2, numpy as np
rng = np.random.default_rng(21)
ref = np.full((600, 800, 3), 235, np.uint8)
for _ in range(150):
    x, y = int(rng.integers(0, 740)), int(rng.integers(0, 540))
    w, h = int(rng.integers(12, 60)), int(rng.integers(12, 60))
    color = tuple(int(c) for c in rng.integers(0, 220, 3))
    cv2.rectangle(ref, (x, y), (x + w, y + h), color, -1)
shifted = cv2.warpAffine(ref, np.float32([[1, 0, -40], [0, 1, -25]]), (800, 600),
                         borderMode=cv2.BORDER_REPLICATE)
cv2.imwrite(r'{$dir}/anchor.jpg', ref)
cv2.imwrite(r'{$dir}/moved.jpg', shifted)
PY]);
    $gen->mustRun();

    manualAlignFrameRow($fx['timelapse'], 'anchor.jpg', 1);
    $moved = manualAlignFrameRow($fx['timelapse'], 'moved.jpg', 2);

    // The human drags the frame 40px right / 25px down over the onion skin.
    Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->call('applyManualAlignment', $moved->id, 1.0, 40.0, 25.0)
        ->assertHasNoErrors();

    $moved->refresh();

    expect($moved->aligned_path)->toBe("timelapse/{$fx['project']->id}/aligned-moved.jpg")
        ->and($disk->exists($moved->aligned_path))->toBeTrue();

    // Interior window (clear of the honest border the shift leaves): the
    // applied transform must land the content back on the anchor.
    $measure = new Process([$python, '-c', <<<PY
import cv2, numpy as np
m = 60
ref = cv2.imread(r'{$dir}/anchor.jpg').astype(np.int16)[m:-m, m:-m]
orig = cv2.imread(r'{$dir}/moved.jpg').astype(np.int16)[m:-m, m:-m]
aligned = cv2.imread(r'{$dir}/aligned-moved.jpg').astype(np.int16)[m:-m, m:-m]
print(float(np.mean(np.abs(ref - orig))), float(np.mean(np.abs(ref - aligned))))
PY]);
    $measure->mustRun();
    [$before, $after] = array_map('floatval', explode(' ', trim($measure->getOutput())));

    expect($after)->toBeLessThan($before * 0.35);
});

it('turns a tilted frame level, matching the modal preview composition', function () {
    $python = manualAlignPython();

    if ($python === null) {
        $this->markTestSkipped('OpenCV venv not available on this machine.');
    }

    $fx = manualAlignFixture();
    $disk = Storage::disk('files');
    $dir = $disk->path("timelapse/{$fx['project']->id}");
    @mkdir($dir, 0777, true);

    // A textured anchor and the same scene shot 6 degrees off level.
    (new Process([$python, '-c', <<<PY
import cv2, numpy as np
rng = np.random.default_rng(31)
ref = np.full((600, 800, 3), 235, np.uint8)
for _ in range(150):
    x, y = int(rng.integers(0, 740)), int(rng.integers(0, 540))
    w, h = int(rng.integers(12, 60)), int(rng.integers(12, 60))
    cv2.rectangle(ref, (x, y), (x + w, y + h), tuple(int(c) for c in rng.integers(0, 220, 3)), -1)
# tilted about its own CENTRE, the pivot the modal and apply_mode share
th = np.radians(6.0)
R = np.array([[np.cos(th), -np.sin(th)], [np.sin(th), np.cos(th)]])
C = np.array([400.0, 300.0])
m = np.hstack([R, (C - R @ C).reshape(2, 1)]).astype(np.float32)
cv2.imwrite(r'{$dir}/anchor.jpg', ref)
cv2.imwrite(r'{$dir}/tilted.jpg', cv2.warpAffine(ref, m, (800, 600), borderMode=cv2.BORDER_REPLICATE))
PY]))->mustRun();

    manualAlignFrameRow($fx['timelapse'], 'anchor.jpg', 1);
    $tilted = manualAlignFrameRow($fx['timelapse'], 'tilted.jpg', 2);

    // The human levels the horizon with the Turn slider: -6 degrees, and the
    // turn pivots about the image CENTRE — a corner pivot would swing the
    // whole frame out of view instead of levelling it.
    Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->call('applyManualAlignment', $tilted->id, 1.0, 0.0, 0.0, -6.0)
        ->assertHasNoErrors();

    expect($tilted->fresh()->aligned_path)->not->toBeNull();

    $measure = new Process([$python, '-c', <<<PY
import cv2, numpy as np
m = 60
ref = cv2.imread(r'{$dir}/anchor.jpg').astype(np.int16)[m:-m, m:-m]
tilt = cv2.imread(r'{$dir}/tilted.jpg').astype(np.int16)[m:-m, m:-m]
fixed = cv2.imread(r'{$dir}/aligned-tilted.jpg').astype(np.int16)[m:-m, m:-m]
print(float(np.mean(np.abs(ref - tilt))), float(np.mean(np.abs(ref - fixed))))
PY]);
    $measure->mustRun();
    [$before, $after] = array_map('floatval', explode(' ', trim($measure->getOutput())));

    // Levelled, not merely nudged — the turn must undo most of the tilt.
    expect($after)->toBeLessThan($before * 0.25);
});

it('rejects an absurd turn', function () {
    $fx = manualAlignFixture();
    Storage::disk('files')->put("timelapse/{$fx['project']->id}/a.jpg", 'a');
    Storage::disk('files')->put("timelapse/{$fx['project']->id}/b.jpg", 'b');
    manualAlignFrameRow($fx['timelapse'], 'a.jpg', 1);
    $second = manualAlignFrameRow($fx['timelapse'], 'b.jpg', 2);

    Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->call('applyManualAlignment', $second->id, 1.0, 0.0, 0.0, 90.0)
        ->assertStatus(422);

    expect($second->fresh()->aligned_path)->toBeNull();
});

it('opens the aligner modal for a non-anchor frame and renders it', function () {
    $fx = manualAlignFixture();
    Storage::disk('files')->put("timelapse/{$fx['project']->id}/a.jpg", 'a');
    Storage::disk('files')->put("timelapse/{$fx['project']->id}/b.jpg", 'b');

    manualAlignFrameRow($fx['timelapse'], 'a.jpg', 1);
    $second = manualAlignFrameRow($fx['timelapse'], 'b.jpg', 2);

    Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->call('openFrameAligner', $second->id)
        ->assertSet('showAlignModal', true)
        ->assertSet('alignFrameId', $second->id)
        ->assertSee('Align frame')
        ->assertSee('Save alignment');
});

it('refuses to align the anchor onto itself', function () {
    $fx = manualAlignFixture();
    Storage::disk('files')->put("timelapse/{$fx['project']->id}/a.jpg", 'a');

    $anchor = manualAlignFrameRow($fx['timelapse'], 'a.jpg', 1);

    Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->call('openFrameAligner', $anchor->id)
        ->assertSet('showAlignModal', false)
        ->call('applyManualAlignment', $anchor->id, 1.1, 10.0, 10.0);

    expect($anchor->fresh()->aligned_path)->toBeNull();
});

it('rejects garbage transforms', function () {
    $fx = manualAlignFixture();
    Storage::disk('files')->put("timelapse/{$fx['project']->id}/a.jpg", 'a');
    Storage::disk('files')->put("timelapse/{$fx['project']->id}/b.jpg", 'b');

    manualAlignFrameRow($fx['timelapse'], 'a.jpg', 1);
    $second = manualAlignFrameRow($fx['timelapse'], 'b.jpg', 2);

    Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->call('applyManualAlignment', $second->id, 12.0, 0.0, 0.0)
        ->assertStatus(422);

    expect($second->fresh()->aligned_path)->toBeNull();
});

it('is admin-only', function () {
    $fx = manualAlignFixture('Member');
    Storage::disk('files')->put("timelapse/{$fx['project']->id}/a.jpg", 'a');
    Storage::disk('files')->put("timelapse/{$fx['project']->id}/b.jpg", 'b');

    manualAlignFrameRow($fx['timelapse'], 'a.jpg', 1);
    $second = manualAlignFrameRow($fx['timelapse'], 'b.jpg', 2);

    Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->call('openFrameAligner', $second->id)
        ->assertForbidden();

    Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->call('applyManualAlignment', $second->id, 1.0, 0.0, 0.0)
        ->assertForbidden();

    Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->call('clearAlignment', $second->id)
        ->assertForbidden();
});

it('404s for a frame from another project', function () {
    $fx = manualAlignFixture();

    $other = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Other Project',
        'client_id' => Client::factory()->create()->id,
        'address' => '1 Elsewhere Ln',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
        'belongs_to_vendor_id' => $fx['vendor']->id,
    ]));
    $otherTimelapse = ProjectTimelapse::create([
        'project_id' => $other->id,
        'title' => 'Elsewhere',
        'kind' => 'timelapse',
    ]);
    $foreign = manualAlignFrameRow($otherTimelapse, 'x.jpg', 1);

    $component = Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']]);

    expect(fn () => $component->call('applyManualAlignment', $foreign->id, 1.0, 0.0, 0.0))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class)
        ->and($foreign->fresh()->aligned_path)->toBeNull();
});

it('clears an alignment back to the original shot', function () {
    $fx = manualAlignFixture();
    $disk = Storage::disk('files');
    $disk->put("timelapse/{$fx['project']->id}/a.jpg", 'a');
    $disk->put("timelapse/{$fx['project']->id}/b.jpg", 'original-bytes');
    $disk->put("timelapse/{$fx['project']->id}/aligned-b.jpg", 'aligned-bytes');

    manualAlignFrameRow($fx['timelapse'], 'a.jpg', 1);
    $second = manualAlignFrameRow($fx['timelapse'], 'b.jpg', 2);
    $second->forceFill(['aligned_path' => "timelapse/{$fx['project']->id}/aligned-b.jpg"])->save();

    Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->call('openFrameAligner', $second->id)
        ->call('clearAlignment', $second->id)
        // The aligner stays open — restoring the original is where a fresh
        // adjustment STARTS, so closing it would throw the user out mid-edit.
        ->assertSet('showAlignModal', true)
        ->assertSet('alignFrameId', $second->id)
        ->assertDispatched('alignment-cleared')
        ->assertSee('Save alignment');

    expect($second->fresh()->aligned_path)->toBeNull()
        ->and($second->fresh()->align_transform)->toBeNull();
    $disk->assertMissing("timelapse/{$fx['project']->id}/aligned-b.jpg");
});

it('serves the raw sequence copy to the editor, aligned copy to viewers', function () {
    $fx = manualAlignFixture();
    $disk = Storage::disk('files');
    $disk->put("timelapse/{$fx['project']->id}/b.jpg", 'raw-bytes');
    $disk->put("timelapse/{$fx['project']->id}/aligned-b.jpg", 'aligned-bytes');

    $frame = manualAlignFrameRow($fx['timelapse'], 'b.jpg', 1);
    $frame->forceFill(['aligned_path' => "timelapse/{$fx['project']->id}/aligned-b.jpg"])->save();

    $this->actingAs($fx['user']);

    expect($this->get(route('projects.timelapse.frame', [$frame, 'raw' => 1]))->getContent())->toBe('raw-bytes')
        ->and($this->get(route('projects.timelapse.frame', $frame))->getContent())->toBe('aligned-bytes');
});

it('re-grades the frame it just aligned so it does not stand out tonally', function () {
    $python = manualAlignPython();

    if ($python === null) {
        $this->markTestSkipped('OpenCV venv not available on this machine.');
    }

    $fx = manualAlignFixture();
    $disk = Storage::disk('files');
    $dir = $disk->path("timelapse/{$fx['project']->id}");
    @mkdir($dir, 0777, true);

    (new Process([$python, '-c', <<<PY
import cv2, numpy as np
rng = np.random.default_rng(41)
ref = np.full((600, 800, 3), 200, np.uint8)
for _ in range(140):
    x, y = int(rng.integers(0, 740)), int(rng.integers(0, 540))
    w, h = int(rng.integers(12, 60)), int(rng.integers(12, 60))
    cv2.rectangle(ref, (x, y), (x + w, y + h), tuple(int(c) for c in rng.integers(0, 220, 3)), -1)
cv2.imwrite(r'{$dir}/anchor.jpg', ref)
cv2.imwrite(r'{$dir}/moved.jpg', np.clip(ref.astype(np.int16) - 30, 0, 255).astype(np.uint8))
PY]))->mustRun();

    $anchor = manualAlignFrameRow($fx['timelapse'], 'anchor.jpg', 1);
    $moved = manualAlignFrameRow($fx['timelapse'], 'moved.jpg', 2);

    Bus::fake([\App\Jobs\HarmonizeTimelapseFrameColor::class]);

    Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->call('applyManualAlignment', $moved->id, 1.0, 0.0, 0.0, 0.0)
        ->assertHasNoErrors();

    // Hand-aligning changes the frame's shape, so its colours are re-graded
    // toward the anchor exactly as the automatic path does.
    Bus::assertDispatched(
        \App\Jobs\HarmonizeTimelapseFrameColor::class,
        fn ($job) => $job->frameId === $moved->id && $job->anchorFrameId === $anchor->id,
    );
});

it('offers the anchor choice inside the frame aligner, not on the thumbnail', function () {
    $fx = manualAlignFixture();
    $disk = Storage::disk('files');
    $disk->put("timelapse/{$fx['project']->id}/a.jpg", 'a');
    $disk->put("timelapse/{$fx['project']->id}/b.jpg", 'b');

    $first = manualAlignFrameRow($fx['timelapse'], 'a.jpg', 1);
    $second = manualAlignFrameRow($fx['timelapse'], 'b.jpg', 2);
    $second->forceFill([
        'aligned_path' => "timelapse/{$fx['project']->id}/aligned-b.jpg",
        'align_transform' => ['scale' => 1.4, 'rotation' => 2.0, 'tx' => 10, 'ty' => 5, 'preview_width' => 1920],
    ])->save();
    $disk->put("timelapse/{$fx['project']->id}/aligned-b.jpg", 'aligned');

    $component = Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->call('toggleEditCollection', $fx['timelapse']->id);

    // Not chrome on the thumbnail…
    $component->assertDontSee('Use as anchor');

    // …but offered once the frame itself is open.
    $component->call('openFrameAligner', $second->id)
        ->assertSee('Use as anchor');

    Bus::fake([\App\Jobs\AlignTimelapseFrame::class]);

    $component->call('setAlignmentAnchor', $second->id)
        // The aligner closes: this frame IS the reference now.
        ->assertSet('showAlignModal', false);

    $second->refresh();

    // Reset to its original — no warp of its own, no stale fit — and the
    // whole sequence re-processes onto it.
    expect($fx['timelapse']->fresh()->anchor_frame_id)->toBe($second->id)
        ->and($second->aligned_path)->toBeNull()
        ->and($second->align_transform)->toBeNull()
        ->and($first->fresh()->align_transform)->toBeNull();

    Bus::assertChained([
        new \App\Jobs\AlignTimelapseFrame($second->id, reframe: true),
        new \App\Jobs\AlignTimelapseFrame($first->id, reframe: true),
    ]);
});

it('anchors on the composition the human adjusted, and matches the rest to it', function () {
    $python = manualAlignPython();

    if ($python === null) {
        $this->markTestSkipped('OpenCV venv not available on this machine.');
    }

    $fx = manualAlignFixture();
    $disk = Storage::disk('files');
    $dir = $disk->path("timelapse/{$fx['project']->id}");
    @mkdir($dir, 0777, true);

    (new Process([$python, '-c', <<<PY
import cv2, numpy as np
rng = np.random.default_rng(55)
ref = np.full((600, 800, 3), 220, np.uint8)
for _ in range(150):
    x, y = int(rng.integers(0, 740)), int(rng.integers(0, 540))
    w, h = int(rng.integers(12, 60)), int(rng.integers(12, 60))
    cv2.rectangle(ref, (x, y), (x + w, y + h), tuple(int(c) for c in rng.integers(0, 220, 3)), -1)
cv2.imwrite(r'{$dir}/a.jpg', ref)
cv2.imwrite(r'{$dir}/b.jpg', ref)
# the "original" is the same scene at 2x resolution
cv2.imwrite(r'{$dir}/original-b.jpg', cv2.resize(ref, (1600, 1200), interpolation=cv2.INTER_CUBIC))
PY]))->mustRun();

    $first = manualAlignFrameRow($fx['timelapse'], 'a.jpg', 1);
    $second = manualAlignFrameRow($fx['timelapse'], 'b.jpg', 2);
    $second->forceFill(['original_path' => "timelapse/{$fx['project']->id}/original-b.jpg"])->save();

    Bus::fake([\App\Jobs\AlignTimelapseFrame::class]);

    // Zoomed to 1.3 and levelled by 2 degrees: THAT is the canvas now.
    Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->call('setAlignmentAnchor', $second->id, 1.3, 0.0, 0.0, 2.0);

    $second->refresh();

    expect($fx['timelapse']->fresh()->anchor_frame_id)->toBe($second->id)
        // The composition was rendered and kept — not reset to as-shot.
        ->and($second->aligned_path)->not->toBeNull()
        ->and($second->align_transform['scale'])->toEqualWithDelta(1.3, 0.01)
        ->and($second->align_transform['rotation'])->toEqualWithDelta(2.0, 0.01);

    $disk->assertExists($second->aligned_path);

    // Everyone else re-processes onto it; the anchor is NOT in the chain, so
    // nothing re-derives the canvas the human just composed.
    Bus::assertChained([
        new \App\Jobs\AlignTimelapseFrame($first->id, reframe: true),
    ]);
});
