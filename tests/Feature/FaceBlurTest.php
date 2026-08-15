<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTimelapse;
use App\Models\ProjectTimelapseFrame;
use App\Models\User;
use App\Models\Vendor;
use App\Services\FaceBlur;
use App\Services\ProjectImageImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

/** A project + gallery collection, built the way the other image tests do. */
function faceFixture(): array
{
    Storage::fake('files');

    $vendor = Vendor::factory()->create();
    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Face Blur Test',
        'client_id' => $client->id,
        'address' => '400 N Wheeling Rd',
        'city' => 'Prospect Heights',
        'state' => 'IL',
        'zip_code' => '60070',
        'belongs_to_vendor_id' => $vendor->id,
    ]));

    return [
        'project' => $project,
        'timelapse' => ProjectTimelapse::create([
            'project_id' => $project->id,
            'kind' => ProjectTimelapse::KIND_GALLERY,
        ]),
    ];
}

/** The OpenCV venv, or null when this machine has no aligner python. */
function blurPython(): ?string
{
    $python = (string) config('services.timelapse_align.python');

    return is_executable($python) ? $python : null;
}

/**
 * A synthetic "photo of a person": a face-like oval with eyes, nose, mouth on
 * a plain wall. YuNet detects it, which is the point — no real person's photo
 * lives in the test suite.
 */
function drawTestFace(string $python, string $path, int $faceSize = 200): void
{
    $process = new Process([$python, '-c', <<<PY
import cv2, numpy as np
img = np.full((900, 1200, 3), 200, np.uint8)
cx, cy, s = 600, 430, {$faceSize}
skin = (150, 180, 210)
cv2.ellipse(img, (cx, cy + int(s*0.95)), (int(s*0.42), int(s*0.5)), 0, 0, 360, skin, -1)
cv2.ellipse(img, (cx, cy), (int(s*0.60), int(s*0.80)), 0, 0, 360, skin, -1)
cv2.ellipse(img, (cx, cy - int(s*0.62)), (int(s*0.64), int(s*0.46)), 0, 180, 360, (55, 60, 75), -1)
for dx in (-1, 1):
    ex, ey = cx + dx*int(s*0.24), cy - int(s*0.12)
    cv2.ellipse(img, (ex, ey), (int(s*0.115), int(s*0.062)), 0, 0, 360, (245, 248, 250), -1)
    cv2.circle(img, (ex, ey), int(s*0.05), (70, 60, 50), -1)
    cv2.circle(img, (ex, ey), int(s*0.022), (20, 20, 20), -1)
    cv2.ellipse(img, (ex, ey - int(s*0.13)), (int(s*0.15), int(s*0.055)), 0, 190, 350, (70, 70, 85), int(s*0.028))
cv2.ellipse(img, (cx, cy + int(s*0.14)), (int(s*0.075), int(s*0.15)), 0, 240, 300, (120, 145, 180), int(s*0.025))
cv2.ellipse(img, (cx, cy + int(s*0.42)), (int(s*0.17), int(s*0.07)), 0, 0, 180, (120, 130, 180), -1)
img = cv2.GaussianBlur(img, (0, 0), 2.5)
cv2.imwrite(r'{$path}', img, [int(cv2.IMWRITE_JPEG_QUALITY), 95])
PY]);
    $process->mustRun();
}

/**
 * How many faces a detector still finds — the property that actually
 * matters. "Blurred enough" is not a sharpness number, it is that the
 * person can no longer be picked out of the photo.
 */
function faceCount(string $python, string $path): int
{
    $dir_of_script = base_path('scripts');
    $process = new Process([$python, '-c', <<<PY
import sys, cv2
sys.path.insert(0, r'{$dir_of_script}')
from blur_faces import detect, iou, MODEL
det = cv2.FaceDetectorYN.create(MODEL, '', (320, 320), 0.5, 0.3, 5000)
img = cv2.imread(r'{$path}')
found = [f for f in detect(img, det, 1.0) if f[4] >= 0.60]
for c in detect(img, det, 2.0):
    if c[4] >= 0.70 and all(iou(c, k) < 0.3 for k in found):
        found.append(c)
print(len(found))
PY]);
    $process->mustRun();

    return (int) trim($process->getOutput());
}

it('blurs a face on a display copy and leaves faceless images byte-identical', function () {
    $python = blurPython();

    if ($python === null) {
        $this->markTestSkipped('OpenCV venv not available on this machine.');
    }

    Storage::fake('files');
    $dir = Storage::disk('files')->path('face-blur-test');
    @mkdir($dir, 0777, true);
    $face = $dir.'/with-face.jpg';
    $plain = $dir.'/no-face.jpg';

    drawTestFace($python, $face);
    (new Process([$python, '-c', <<<PY
import cv2, numpy as np
rng = np.random.default_rng(5)
img = np.full((900, 1200, 3), 205, np.uint8)
for _ in range(120):
    x, y = int(rng.integers(0, 1150)), int(rng.integers(0, 850))
    cv2.rectangle(img, (x, y), (x + 40, y + 30), tuple(int(c) for c in rng.integers(0, 200, 3)), -1)
cv2.imwrite(r'{$plain}', img, [int(cv2.IMWRITE_JPEG_QUALITY), 95])
PY]))->mustRun();

    expect(faceCount($python, $face))->toBe(1);
    $plainHash = md5_file($plain);

    $result = FaceBlur::blur($face, $plain);

    expect($result['with-face.jpg'] ?? 0)->toBeGreaterThan(0)
        ->and($result['no-face.jpg'] ?? null)->toBe(0)
        // A faceless photo must not be silently re-encoded — it is untouched.
        ->and(md5_file($plain))->toBe($plainHash)
        // The person can no longer be picked out — and a second pass is a
        // no-op, so the backfill is safely re-runnable.
        ->and(faceCount($python, $face))->toBe(0);

    array_map('unlink', glob($dir.'/*.jpg'));
});

it('blurs the display copy on import but never the archive original', function () {
    $python = blurPython();

    if ($python === null) {
        $this->markTestSkipped('OpenCV venv not available on this machine.');
    }

    ['project' => $project, 'timelapse' => $timelapse] = faceFixture();

    $source = sys_get_temp_dir().'/face-import-'.uniqid().'.jpg';
    drawTestFace($python, $source);

    $frame = (new ProjectImageImporter)->storeImage($timelapse, $source, 'crew.jpg');

    $disk = Storage::disk($frame->disk);

    // The archive keeps the face — it is the evidentiary record.
    expect(faceCount($python, $disk->path($frame->original_path)))->toBe(1)
        // The copy every viewer is served does not.
        ->and(faceCount($python, $disk->path($frame->path)))->toBe(0);

    @unlink($source);
});

it('backfills faces on existing display copies and busts their cached URLs', function () {
    $python = blurPython();

    if ($python === null) {
        $this->markTestSkipped('OpenCV venv not available on this machine.');
    }

    ['project' => $project, 'timelapse' => $timelapse] = faceFixture();

    $disk = Storage::disk('files');
    $dir = $disk->path('timelapse/'.$project->id);
    @mkdir($dir, 0777, true);
    drawTestFace($python, $dir.'/legacy.jpg');

    $frame = ProjectTimelapseFrame::create([
        'project_timelapse_id' => $timelapse->id,
        'filename' => 'legacy.jpg',
        'path' => 'timelapse/'.$project->id.'/legacy.jpg',
        'disk' => 'files',
        'shot_at' => now(),
        'sort_order' => 1,
    ]);

    expect(faceCount($python, $dir.'/legacy.jpg'))->toBe(1);
    $stamp = $frame->updated_at;

    $this->travel(5)->seconds();
    $this->artisan('images:blur-faces', ['--project' => $project->id])->assertSuccessful();

    expect(faceCount($python, $dir.'/legacy.jpg'))->toBe(0)
        // Immutable URLs are keyed on updated_at — a rewritten file must get
        // a fresh one, or browsers keep serving the face.
        ->and($frame->fresh()->updated_at->gt($stamp))->toBeTrue();
});
