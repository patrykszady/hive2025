<?php

use App\Jobs\HarmonizeTimelapseColors;
use App\Jobs\HarmonizeTimelapseFrameColor;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTimelapse;
use App\Models\ProjectTimelapseFrame;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

/*
 * Color harmonization: every frame's colors adjusted — whole image, nothing
 * regional — toward the sequence's alignment anchor, so scrubbing the
 * timelapse holds one consistent look. An earlier region-based (sky mask)
 * version washed over tree canopies and was rejected.
 */

function harmonizeFixture(string $role = 'Admin'): array
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
        'email' => 'harmonize.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => $role === 'Admin' ? 1 : 2]);

    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Harmonize Test',
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

function harmonizeFrameRow(ProjectTimelapse $timelapse, string $filename, int $sortOrder): ProjectTimelapseFrame
{
    return ProjectTimelapseFrame::create([
        'project_timelapse_id' => $timelapse->id,
        'filename' => $filename,
        'path' => "timelapse/{$timelapse->project_id}/{$filename}",
        'disk' => 'files',
        'sort_order' => $sortOrder,
    ]);
}

function harmonizePython(): ?string
{
    $python = (string) config('services.timelapse_align.python');

    if (! is_executable($python)) {
        return null;
    }

    $probe = new Process([$python, '-c', 'import cv2']);
    $probe->run();

    return $probe->isSuccessful() ? $python : null;
}

it('grades a frame toward the reference colors uniformly', function () {
    $python = harmonizePython();

    if ($python === null) {
        $this->markTestSkipped('OpenCV venv not available on this machine.');
    }

    $fx = harmonizeFixture();
    $disk = Storage::disk('files');
    $dir = $disk->path("timelapse/{$fx['project']->id}");
    @mkdir($dir, 0777, true);

    // Same textured scene, shot "brighter/warmer" vs "darker/cooler".
    (new Process([$python, '-c', <<<PY
import cv2, numpy as np
rng = np.random.default_rng(9)
base = np.zeros((600, 800, 3), np.float64)
base[:] = (110, 130, 150)
for _ in range(240):
    x, y = int(rng.integers(0, 740)), int(rng.integers(0, 540))
    w, h = int(rng.integers(10, 50)), int(rng.integers(8, 40))
    c = rng.integers(40, 210, 3).astype(np.float64)
    cv2.rectangle(base, (x, y), (x + w, y + h), c.tolist(), -1)
cv2.imwrite(r'{$dir}/anchor.jpg', np.clip(base * 1.12 + 14, 0, 255).astype(np.uint8))
cv2.imwrite(r'{$dir}/dull.jpg', np.clip(base * 0.86 - 8, 0, 255).astype(np.uint8))
PY]))->mustRun();

    $grade = new Process([
        $python, base_path('scripts/harmonize_frames.py'),
        "{$dir}/anchor.jpg", "{$dir}/dull.jpg", "{$dir}/graded.jpg",
    ]);
    $grade->mustRun();

    expect(json_decode(trim($grade->getOutput()), true)['ok'] ?? false)->toBeTrue();

    // The graded frame's overall luminance must land clearly closer to the
    // anchor's than the dull original was — clearly, but not all the way:
    // the grade is deliberately eased (see the strength test below).
    $measure = new Process([$python, '-c', <<<PY
import cv2, numpy as np
def L(p):
    return float(cv2.cvtColor(cv2.imread(p), cv2.COLOR_BGR2LAB)[:, :, 0].mean())
a, d, g = L(r'{$dir}/anchor.jpg'), L(r'{$dir}/dull.jpg'), L(r'{$dir}/graded.jpg')
print(abs(a - d), abs(a - g))
PY]);
    $measure->mustRun();
    [$before, $after] = array_map('floatval', explode(' ', trim($measure->getOutput())));

    expect($after)->toBeLessThan($before * 0.7);
});

it('chains a grade toward the anchor for every frame except the anchor itself', function () {
    $fx = harmonizeFixture();
    $disk = Storage::disk('files');
    $disk->put("timelapse/{$fx['project']->id}/a.jpg", 'a');
    $disk->put("timelapse/{$fx['project']->id}/b.jpg", 'b');
    $disk->put("timelapse/{$fx['project']->id}/c.jpg", 'c');

    $first = harmonizeFrameRow($fx['timelapse'], 'a.jpg', 1);
    $second = harmonizeFrameRow($fx['timelapse'], 'b.jpg', 2);
    $third = harmonizeFrameRow($fx['timelapse'], 'c.jpg', 3);

    // The chosen anchor is frame 2, not the default first frame.
    $fx['timelapse']->forceFill(['anchor_frame_id' => $second->id])->save();

    Bus::fake([HarmonizeTimelapseFrameColor::class]);

    (new HarmonizeTimelapseColors($fx['timelapse']->id))->handle();

    Bus::assertChained([
        new HarmonizeTimelapseFrameColor($first->id, $second->id),
        new HarmonizeTimelapseFrameColor($third->id, $second->id),
    ]);
});


it('eases the grade rather than forcing frames identical', function () {
    $python = harmonizePython();

    if ($python === null) {
        $this->markTestSkipped('OpenCV venv not available on this machine.');
    }

    $fx = harmonizeFixture();
    $dir = Storage::disk('files')->path("timelapse/{$fx['project']->id}");
    @mkdir($dir, 0777, true);

    (new Process([$python, '-c', <<<PY
import cv2, numpy as np
rng = np.random.default_rng(77)
base = np.zeros((600, 800, 3), np.float64)
base[:] = (110, 130, 150)
for _ in range(240):
    x, y = int(rng.integers(0, 740)), int(rng.integers(0, 540))
    w, h = int(rng.integers(10, 50)), int(rng.integers(8, 40))
    cv2.rectangle(base, (x, y), (x + w, y + h), rng.integers(40, 210, 3).astype(np.float64).tolist(), -1)
cv2.imwrite(r'{$dir}/anchor.jpg', np.clip(base * 1.15 + 16, 0, 255).astype(np.uint8))
cv2.imwrite(r'{$dir}/dull.jpg', np.clip(base * 0.85 - 10, 0, 255).astype(np.uint8))
PY]))->mustRun();

    $grade = new Process([$python, base_path('scripts/harmonize_frames.py'),
        "{$dir}/anchor.jpg", "{$dir}/dull.jpg", "{$dir}/graded.jpg"]);
    $grade->mustRun();

    $measure = new Process([$python, '-c', <<<PY
import cv2, numpy as np
def L(p):
    return float(cv2.cvtColor(cv2.imread(p), cv2.COLOR_BGR2LAB)[:, :, 0].mean())
print(L(r'{$dir}/anchor.jpg'), L(r'{$dir}/dull.jpg'), L(r'{$dir}/graded.jpg'))
PY]);
    $measure->mustRun();
    [$anchor, $dull, $graded] = array_map('floatval', explode(' ', trim($measure->getOutput())));

    $closed = (abs($anchor - $dull) - abs($anchor - $graded)) / abs($anchor - $dull);

    // Most of the gap closes — but NOT all of it. Forcing every frame to the
    // same numbers reads as flat and over-processed on a sequence whose light
    // genuinely changes.
    expect($closed)->toBeGreaterThan(0.35)
        ->and($closed)->toBeLessThan(0.95);
});
