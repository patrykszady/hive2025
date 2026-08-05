<?php

use App\Jobs\AlignTimelapseFrame;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTimelapse;
use App\Models\ProjectTimelapseFrame;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

function alignFixture(): array
{
    Storage::fake('files');

    $vendor = Vendor::factory()->create();
    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Align Test',
        'client_id' => $client->id,
        'address' => '400 N Wheeling Rd',
        'city' => 'Prospect Heights',
        'state' => 'IL',
        'zip_code' => '60070',
        'belongs_to_vendor_id' => $vendor->id,
    ]));

    $timelapse = ProjectTimelapse::create(['project_id' => $project->id]);

    return ['project' => $project, 'timelapse' => $timelapse];
}

function alignFrameRow(ProjectTimelapse $timelapse, string $filename, int $sortOrder): ProjectTimelapseFrame
{
    return ProjectTimelapseFrame::create([
        'project_timelapse_id' => $timelapse->id,
        'filename' => $filename,
        'path' => "timelapse/{$timelapse->project_id}/{$filename}",
        'disk' => 'files',
        'sort_order' => $sortOrder,
    ]);
}

function cvPython(): ?string
{
    $python = (string) config('services.timelapse_align.python');

    if (! is_executable($python)) {
        return null;
    }

    $probe = new Process([$python, '-c', 'import cv2']);
    $probe->run();

    return $probe->isSuccessful() ? $python : null;
}

it('registers a shifted frame onto the anchor and records the aligned copy', function () {
    $python = cvPython();

    if ($python === null) {
        $this->markTestSkipped('OpenCV venv not available on this machine.');
    }

    $fx = alignFixture();
    $disk = Storage::disk('files');
    $dir = $disk->path("timelapse/{$fx['project']->id}");
    @mkdir($dir, 0777, true);

    // A textured reference and a copy shifted 12px right / 7px down + slightly
    // rotated — the handheld wobble the aligner exists to remove. Generated
    // with the same python the job uses (the test already skips without it).
    $gen = new Process([$python, '-c', <<<PY
import cv2, numpy as np
rng = np.random.default_rng(7)
ref = np.full((600, 800, 3), 235, np.uint8)
for _ in range(140):
    x, y = int(rng.integers(0, 740)), int(rng.integers(0, 540))
    w, h = int(rng.integers(12, 60)), int(rng.integers(12, 60))
    color = tuple(int(c) for c in rng.integers(0, 220, 3))
    cv2.rectangle(ref, (x, y), (x + w, y + h), color, -1)
matrix = cv2.getRotationMatrix2D((400, 300), 1.5, 1.0)
matrix[0, 2] += 12
matrix[1, 2] += 7
shifted = cv2.warpAffine(ref, matrix, (800, 600), borderMode=cv2.BORDER_REPLICATE)
cv2.imwrite(r'{$dir}/anchor.jpg', ref)
cv2.imwrite(r'{$dir}/second.jpg', shifted)
PY]);
    $gen->mustRun();

    $anchor = alignFrameRow($fx['timelapse'], 'anchor.jpg', 1);
    $second = alignFrameRow($fx['timelapse'], 'second.jpg', 2);

    (new AlignTimelapseFrame($second->id))->handle();

    $second->refresh();

    expect($second->aligned_path)->toBe("timelapse/{$fx['project']->id}/aligned-second.jpg");
    expect($disk->exists($second->aligned_path))->toBeTrue();

    // The aligned copy must sit measurably closer to the anchor than the
    // shifted original does. Measured on the interior: the strip the warp
    // couldn't fill is black by design (it is not photo), and scoring an
    // honest black border against a bright anchor would say nothing about how
    // well the frames line up. Both images get the same window, so the
    // comparison stays fair.
    $measure = new Process([$python, '-c', <<<PY
import cv2, numpy as np
m = 40
ref = cv2.imread(r'{$dir}/anchor.jpg').astype(np.int16)[m:-m, m:-m]
orig = cv2.imread(r'{$dir}/second.jpg').astype(np.int16)[m:-m, m:-m]
aligned = cv2.imread(r'{$dir}/aligned-second.jpg').astype(np.int16)[m:-m, m:-m]
print(float(np.mean(np.abs(ref - orig))), float(np.mean(np.abs(ref - aligned))))
PY]);
    $measure->mustRun();
    [$before, $after] = array_map('floatval', explode(' ', trim($measure->getOutput())));

    expect($after)->toBeLessThan($before * 0.5);

    // The anchor gets a PHOTO-ONLY copy: identity geometry, colors eased to
    // the sequence profile — so its own quirks (a flare) don't stay the odd
    // frame out.
    (new AlignTimelapseFrame($anchor->id))->handle();
    $anchorAligned = $anchor->fresh()->aligned_path;
    expect($anchorAligned)->not->toBeNull();

    [$aw, $ah] = getimagesizefromstring(Storage::disk('files')->get($anchorAligned));
    [$ow, $oh] = getimagesizefromstring(Storage::disk('files')->get($anchor->path));
    expect([$aw, $ah])->toBe([$ow, $oh]);
});

it('refuses a warp that would invent more border than photo it moved', function () {
    $python = cvPython();

    if ($python === null) {
        $this->markTestSkipped('OpenCV venv not available on this machine.');
    }

    $fx = alignFixture();
    $disk = Storage::disk('files');
    $dir = $disk->path("timelapse/{$fx['project']->id}");
    @mkdir($dir, 0777, true);

    // Same scene shot from a long step aside: registering it onto the anchor
    // pulls a fifth of the canvas in from beyond the photo's own edge. There
    // is nothing real to put there, and the aligner used to smear the outermost
    // pixel across it — a frame that is largely invented content.
    $gen = new Process([$python, '-c', <<<PY
import cv2, numpy as np
rng = np.random.default_rng(11)
ref = np.full((600, 800, 3), 235, np.uint8)
for _ in range(160):
    x, y = int(rng.integers(0, 740)), int(rng.integers(0, 540))
    w, h = int(rng.integers(12, 60)), int(rng.integers(12, 60))
    color = tuple(int(c) for c in rng.integers(0, 220, 3))
    cv2.rectangle(ref, (x, y), (x + w, y + h), color, -1)
matrix = np.float32([[1, 0, -150], [0, 1, -95]])
drifted = cv2.warpAffine(ref, matrix, (800, 600), borderMode=cv2.BORDER_REPLICATE)
cv2.imwrite(r'{$dir}/anchor.jpg', ref)
cv2.imwrite(r'{$dir}/drifted.jpg', drifted)
PY]);
    $gen->mustRun();

    alignFrameRow($fx['timelapse'], 'anchor.jpg', 1);
    $drifted = alignFrameRow($fx['timelapse'], 'drifted.jpg', 2);

    (new AlignTimelapseFrame($drifted->id))->handle();

    // Kept as shot — its own framing, every pixel real — rather than warped
    // onto the anchor with a fabricated border.
    expect($drifted->fresh()->aligned_path)->toBeNull()
        ->and($disk->exists("timelapse/{$fx['project']->id}/aligned-drifted.jpg"))->toBeFalse();
});

it('fills what little border a kept alignment has with black, never smear', function () {
    $python = cvPython();

    if ($python === null) {
        $this->markTestSkipped('OpenCV venv not available on this machine.');
    }

    $fx = alignFixture();
    $disk = Storage::disk('files');
    $dir = $disk->path("timelapse/{$fx['project']->id}");
    @mkdir($dir, 0777, true);

    $gen = new Process([$python, '-c', <<<PY
import cv2, numpy as np
rng = np.random.default_rng(3)
ref = np.full((600, 800, 3), 235, np.uint8)
for _ in range(160):
    x, y = int(rng.integers(0, 740)), int(rng.integers(0, 540))
    w, h = int(rng.integers(12, 60)), int(rng.integers(12, 60))
    color = tuple(int(c) for c in rng.integers(0, 220, 3))
    cv2.rectangle(ref, (x, y), (x + w, y + h), color, -1)
shifted = cv2.warpAffine(ref, np.float32([[1, 0, -14], [0, 1, -9]]), (800, 600),
                         borderMode=cv2.BORDER_REPLICATE)
cv2.imwrite(r'{$dir}/anchor.jpg', ref)
cv2.imwrite(r'{$dir}/nudged.jpg', shifted)
PY]);
    $gen->mustRun();

    alignFrameRow($fx['timelapse'], 'anchor.jpg', 1);
    $nudged = alignFrameRow($fx['timelapse'], 'nudged.jpg', 2);

    (new AlignTimelapseFrame($nudged->id))->handle();

    expect($nudged->fresh()->aligned_path)->not->toBeNull();

    // The strip the warp couldn't fill is black, not a copy of the column
    // beside it — the scene here is bright (235), so smear would be far from 0.
    $measure = new Process([$python, '-c', <<<PY
import cv2, numpy as np
a = cv2.imread(r'{$dir}/aligned-nudged.jpg', cv2.IMREAD_GRAYSCALE)
print(float(a[:, -1].mean()), float(a[-1, :].mean()))
PY]);
    $measure->mustRun();
    [$rightEdge, $bottomEdge] = array_map('floatval', explode(' ', trim($measure->getOutput())));

    expect(min($rightEdge, $bottomEdge))->toBeLessThan(20.0);
});

it('leaves the frame unaligned when python is not available', function () {
    config(['services.timelapse_align.python' => '/nonexistent/python']);

    $fx = alignFixture();
    Storage::disk('files')->put("timelapse/{$fx['project']->id}/a.jpg", 'x');
    Storage::disk('files')->put("timelapse/{$fx['project']->id}/b.jpg", 'x');

    alignFrameRow($fx['timelapse'], 'a.jpg', 1);
    $second = alignFrameRow($fx['timelapse'], 'b.jpg', 2);

    (new AlignTimelapseFrame($second->id))->handle();

    expect($second->fresh()->aligned_path)->toBeNull();
});

it('serves the aligned copy once it exists and deletes it with the frame', function () {
    $fx = alignFixture();
    $disk = Storage::disk('files');
    $disk->put("timelapse/{$fx['project']->id}/raw.jpg", 'original-bytes');
    $disk->put("timelapse/{$fx['project']->id}/aligned-raw.jpg", 'aligned-bytes');

    $frame = alignFrameRow($fx['timelapse'], 'raw.jpg', 2);
    $frame->forceFill(['aligned_path' => "timelapse/{$fx['project']->id}/aligned-raw.jpg"])->save();

    expect($frame->display_path)->toBe("timelapse/{$fx['project']->id}/aligned-raw.jpg");

    $frame->delete();

    $disk->assertMissing("timelapse/{$fx['project']->id}/raw.jpg");
    $disk->assertMissing("timelapse/{$fx['project']->id}/aligned-raw.jpg");
});
