<?php

use App\Jobs\ProcessTimelapseFrame;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTimelapse;
use App\Models\ProjectTimelapseFrame;
use App\Models\User;
use App\Models\Vendor;
use App\Support\ImageThumbs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * What the Hive Contractors app's camera, playback and photo grids lean on:
 * phone-sized copies of a frame behind links that hold still, the frame the
 * next shot lines up against (and how it was shot), an upload that is safe
 * to send twice, and screens that open on one request.
 *
 * Storage is a throwaway folder per test: the thumbnail cache is written
 * with storage_path(), and these tests build real copies of real JPEGs.
 */
beforeEach(function () {
    config(['scout.driver' => null]);

    $this->captureStorage = sys_get_temp_dir().'/app-photo-capture-test-'.getmypid().'-'.uniqid();
    File::ensureDirectoryExists($this->captureStorage.'/app');
    app()->useStoragePath($this->captureStorage);

    Storage::fake('files');
});

afterEach(function () {
    File::deleteDirectory($this->captureStorage);
});

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * AppPaymentsTest's appPaymentsTeamUser().
 *
 * @return array{vendor: Vendor, admin: User, member: User}
 */
function appCaptureTeamUsers(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $admin = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($admin->id, ['role_id' => 1]);
    $member = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($member->id, ['role_id' => 2]);

    return compact('vendor', 'admin', 'member');
}

function appCaptureProject(Vendor $vendor): Project
{
    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Back Addition',
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

/**
 * A real JPEG, optionally carrying the EXIF a phone camera writes: the
 * rotation flag, the 35mm-equivalent focal length and a user comment. Built
 * by hand (one little-endian TIFF block spliced in after the JPEG's first
 * marker) so the test needs no EXIF-writing package.
 */
function appCaptureJpeg(int $width, int $height, ?int $orientation = null, ?int $focalLength35mm = null, ?string $comment = null): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width, (int) ($height / 2), imagecolorallocate($image, 90, 140, 200));
    ob_start();
    imagejpeg($image, null, 80);
    $jpeg = (string) ob_get_clean();
    imagedestroy($image);

    if ($orientation === null && $focalLength35mm === null && $comment === null) {
        return $jpeg;
    }

    $entry = fn (int $tag, int $type, int $count, string $value): string => pack('vvV', $tag, $type, $count).str_pad($value, 4, "\0");

    $ifd0 = [];
    if ($orientation !== null) {
        $ifd0[] = $entry(0x0112, 3, 1, pack('v', $orientation));
    }

    $exifIfdOffset = 8 + 2 + (count($ifd0) + 1) * 12 + 4;
    $ifd0[] = $entry(0x8769, 4, 1, pack('V', $exifIfdOffset));

    $exifEntryCount = ($comment !== null ? 1 : 0) + ($focalLength35mm !== null ? 1 : 0);
    $commentBytes = $comment !== null ? "ASCII\0\0\0".$comment : '';
    $commentOffset = $exifIfdOffset + 2 + $exifEntryCount * 12 + 4;

    $exifIfd = [];
    if ($comment !== null) {
        $exifIfd[] = $entry(0x9286, 7, strlen($commentBytes), pack('V', $commentOffset));
    }
    if ($focalLength35mm !== null) {
        $exifIfd[] = $entry(0xA405, 3, 1, pack('v', $focalLength35mm));
    }

    $tiff = "II*\0".pack('V', 8)
        .pack('v', count($ifd0)).implode('', $ifd0).pack('V', 0)
        .pack('v', count($exifIfd)).implode('', $exifIfd).pack('V', 0)
        .$commentBytes;

    $segment = "Exif\0\0".$tiff;

    return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($segment) + 2).$segment.substr($jpeg, 2);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function appCaptureFrame(Project $project, ProjectTimelapse $collection, string $name, int $sortOrder, array $attributes = [], ?string $bytes = null): ProjectTimelapseFrame
{
    $path = "timelapse/{$project->id}/{$name}.jpg";
    Storage::disk('files')->put($path, $bytes ?? appCaptureJpeg(64, 48));

    return ProjectTimelapseFrame::forceCreate(array_merge([
        'project_timelapse_id' => $collection->id,
        'filename' => "{$name}.jpg",
        'path' => $path,
        'disk' => 'files',
        'shot_at' => now(),
        'sort_order' => $sortOrder,
    ], $attributes));
}

it('hands out a thumbnail, a phone-screen copy and the full frame, with the size and version of what they show', function () {
    ['vendor' => $vendor, 'member' => $member] = appCaptureTeamUsers();
    Sanctum::actingAs($member, ['app']);
    $project = appCaptureProject($vendor);
    $timelapse = ProjectTimelapse::create(['project_id' => $project->id, 'title' => 'Back 1', 'kind' => ProjectTimelapse::KIND_TIMELAPSE]);
    $frame = appCaptureFrame($project, $timelapse, 'wide', 1, [], appCaptureJpeg(1920, 1440));

    $listed = $this->getJson("/api/app/v1/projects/{$project->id}/collections/{$timelapse->id}/frames")
        ->assertOk()
        ->json('data.0');

    expect($listed['id'])->toBe($frame->id)
        ->and($listed['width'])->toBe(1920)
        ->and($listed['height'])->toBe(1440)
        ->and($listed['version'])->toBe($frame->version)
        ->and($listed['aligned'])->toBeFalse();

    $full = $this->get($listed['url'])->assertOk();
    $screen = $this->get($listed['screen_url'])->assertOk();
    $thumb = $this->get($listed['thumb_url'])->assertOk();

    [$screenWidth, $screenHeight] = getimagesizefromstring($screen->streamedContent());
    [$thumbWidth] = getimagesizefromstring($thumb->streamedContent());

    expect($screenWidth)->toBe(ImageThumbs::SCREEN_EDGE)
        ->and($screenHeight)->toBe(960)
        ->and($thumbWidth)->toBe(ImageThumbs::MAX_EDGE)
        ->and(strlen($screen->streamedContent()))->toBeLessThan(strlen($full->getContent()))
        ->and(strlen($thumb->streamedContent()))->toBeLessThan(strlen($screen->streamedContent()))
        ->and($screen->headers->get('Cache-Control'))->toContain('immutable');

    // Built once and kept: the second ask is the same file.
    expect($this->get($listed['screen_url'])->streamedContent())->toBe($screen->streamedContent());
});

it('keeps a frame\'s links the same all day, and changes them when the frame is re-aligned', function () {
    $this->travelTo(now()->setTime(9, 0));

    ['vendor' => $vendor, 'member' => $member] = appCaptureTeamUsers();
    Sanctum::actingAs($member, ['app']);
    $project = appCaptureProject($vendor);
    $timelapse = ProjectTimelapse::create(['project_id' => $project->id, 'title' => 'Back 1', 'kind' => ProjectTimelapse::KIND_TIMELAPSE]);
    $frame = appCaptureFrame($project, $timelapse, 'a', 1);

    $url = "/api/app/v1/projects/{$project->id}/collections/{$timelapse->id}/frames";
    $morning = $this->getJson($url)->json('data.0');

    $this->travel(6)->hours();
    $afternoon = $this->getJson($url)->json('data.0');

    expect($afternoon['url'])->toBe($morning['url'])
        ->and($afternoon['thumb_url'])->toBe($morning['thumb_url'])
        ->and($afternoon['screen_url'])->toBe($morning['screen_url']);

    // Still good a full day after it was handed out.
    $this->travel(24)->hours();
    $this->get($morning['screen_url'])->assertOk();

    $this->travelBack();
    $this->travelTo(now()->setTime(15, 0));
    $before = $this->getJson($url)->json('data.0');

    $this->travel(5)->minutes();
    $frame->touch();
    $after = $this->getJson($url)->json('data.0');

    expect($after['version'])->toBeGreaterThan($before['version'])
        ->and($after['url'])->not->toBe($before['url'])
        ->and($after['thumb_url'])->not->toBe($before['thumb_url']);
});

it('opens a collection on one request: frames, the collection and the curation right, without a query per frame', function () {
    ['vendor' => $vendor, 'admin' => $admin, 'member' => $member] = appCaptureTeamUsers();
    $project = appCaptureProject($vendor);
    $timelapse = ProjectTimelapse::create(['project_id' => $project->id, 'title' => 'Kitchen', 'kind' => ProjectTimelapse::KIND_TIMELAPSE]);
    $url = "/api/app/v1/projects/{$project->id}/collections/{$timelapse->id}/frames";

    $queriesFor = function () use ($url, $member): int {
        Sanctum::actingAs($member, ['app']);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($url)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    foreach ([1, 2] as $i) {
        appCaptureFrame($project, $timelapse, "f{$i}", $i, ['taken_by_user_id' => User::factory()->create()->id]);
    }
    // The first request of a process also warms what later ones reuse.
    $queriesFor();
    $withTwo = $queriesFor();

    foreach ([3, 4, 5, 6, 7, 8] as $i) {
        appCaptureFrame($project, $timelapse, "f{$i}", $i, ['taken_by_user_id' => User::factory()->create()->id]);
    }
    $withEight = $queriesFor();

    expect($withEight)->toBe($withTwo);

    Sanctum::actingAs($member, ['app']);
    $this->getJson($url)
        ->assertOk()
        ->assertJsonCount(8, 'data')
        ->assertJsonPath('collection.id', $timelapse->id)
        ->assertJsonPath('collection.title', 'Kitchen')
        ->assertJsonPath('collection.frame_count', 8)
        ->assertJsonPath('collection.can_delete', false)
        ->assertJsonPath('meta.can_manage_images', false);

    Sanctum::actingAs($admin, ['app']);
    $this->getJson($url)
        ->assertOk()
        ->assertJsonPath('collection.can_delete', true)
        ->assertJsonPath('meta.can_manage_images', true);

    $this->getJson("/api/app/v1/projects/{$project->id}/collections")
        ->assertOk()
        ->assertJsonPath('meta.can_manage_images', true);

    Sanctum::actingAs($member, ['app']);
    $this->getJson("/api/app/v1/projects/{$project->id}/collections")
        ->assertOk()
        ->assertJsonPath('meta.can_manage_images', false);
});

it('lines the next shot up against the newest frame that sits on the sequence\'s canvas', function () {
    ['vendor' => $vendor, 'member' => $member] = appCaptureTeamUsers();
    Sanctum::actingAs($member, ['app']);
    $project = appCaptureProject($vendor);

    $timelapse = ProjectTimelapse::create(['project_id' => $project->id, 'title' => 'Back 1', 'kind' => ProjectTimelapse::KIND_TIMELAPSE]);
    $anchor = appCaptureFrame($project, $timelapse, 'anchor', 1);
    $registered = appCaptureFrame($project, $timelapse, 'registered', 2, ['aligned_path' => "timelapse/{$project->id}/aligned-registered.jpg"]);
    Storage::disk('files')->put($registered->aligned_path, appCaptureJpeg(64, 48));
    $refused = appCaptureFrame($project, $timelapse, 'refused', 3);

    $untouched = ProjectTimelapse::create(['project_id' => $project->id, 'title' => 'Front', 'kind' => ProjectTimelapse::KIND_TIMELAPSE]);
    appCaptureFrame($project, $untouched, 'front-1', 1);
    $frontLatest = appCaptureFrame($project, $untouched, 'front-2', 2);

    $empty = ProjectTimelapse::create(['project_id' => $project->id, 'title' => 'Garage', 'kind' => ProjectTimelapse::KIND_TIMELAPSE]);
    appCaptureFrame($project, ProjectTimelapse::generalFor($project), 'loose', 1);

    $collections = fn () => collect($this->getJson("/api/app/v1/projects/{$project->id}/collections")->assertOk()->json('data'))->keyBy('title');

    $listed = $collections();

    expect($listed['Back 1']['latest_frame']['id'])->toBe($refused->id)
        ->and($listed['Back 1']['reference_frame']['id'])->toBe($registered->id)
        ->and($listed['Back 1']['reference_frame']['aligned'])->toBeTrue()
        ->and($listed['Front']['reference_frame']['id'])->toBe($frontLatest->id)
        ->and($listed['Garage']['reference_frame'])->toBeNull()
        ->and($listed['Project Images']['latest_frame'])->not->toBeNull()
        ->and($listed['Project Images']['reference_frame'])->toBeNull();

    // With no registered copy at all, a chosen anchor is the canvas.
    $registered->forceFill(['aligned_path' => null])->save();
    $timelapse->forceFill(['anchor_frame_id' => $anchor->id])->save();

    expect($collections()['Back 1']['reference_frame']['id'])->toBe($anchor->id);
});

it('says how the reference frame was shot — lens and tilt — from the archive original', function () {
    ['vendor' => $vendor, 'member' => $member] = appCaptureTeamUsers();
    Sanctum::actingAs($member, ['app']);
    $project = appCaptureProject($vendor);

    $timelapse = ProjectTimelapse::create(['project_id' => $project->id, 'title' => 'Back 1', 'kind' => ProjectTimelapse::KIND_TIMELAPSE]);
    $original = "timelapse/{$project->id}/original-shot.jpg";
    Storage::disk('files')->put($original, appCaptureJpeg(80, 60, null, 13, '{"hive":1,"pitch":-2.46,"roll":0.31,"lens":"ultra-wide"}'));
    appCaptureFrame($project, $timelapse, 'shot', 1, ['original_path' => $original]);

    $browser = ProjectTimelapse::create(['project_id' => $project->id, 'title' => 'Browser', 'kind' => ProjectTimelapse::KIND_TIMELAPSE]);
    $plain = "timelapse/{$project->id}/original-canvas.jpg";
    Storage::disk('files')->put($plain, appCaptureJpeg(80, 60));
    appCaptureFrame($project, $browser, 'canvas', 1, ['original_path' => $plain]);

    $listed = collect($this->getJson("/api/app/v1/projects/{$project->id}/collections")->assertOk()->json('data'))->keyBy('title');

    expect($listed['Back 1']['reference_frame']['capture'])->toBe([
        'focal_length_35mm' => 13,
        'lens' => 'ultra-wide',
        'pitch' => -2.5,
        'roll' => 0.3,
    ])
        ->and($listed['Browser']['reference_frame']['capture'])->toBeNull()
        // A grid never pays for an EXIF read per photo.
        ->and($listed['Back 1']['latest_frame'])->not->toHaveKey('capture');
});

it('reports an unprocessed upload\'s size upright, from the archive original\'s rotation flag', function () {
    ['vendor' => $vendor, 'member' => $member] = appCaptureTeamUsers();
    Sanctum::actingAs($member, ['app']);
    $project = appCaptureProject($vendor);
    $album = ProjectTimelapse::generalFor($project);

    $original = "timelapse/{$project->id}/original-upright.jpg";
    Storage::disk('files')->put($original, appCaptureJpeg(80, 60, 6));
    ProjectTimelapseFrame::forceCreate([
        'project_timelapse_id' => $album->id,
        'filename' => 'upright.jpg',
        'path' => "timelapse/{$project->id}/upright.jpg",
        'original_path' => $original,
        'disk' => 'files',
        'sort_order' => 1,
    ]);

    $listed = $this->getJson("/api/app/v1/projects/{$project->id}/collections/{$album->id}/frames")->assertOk()->json('data.0');

    expect($listed['width'])->toBe(60)
        ->and($listed['height'])->toBe(80);
});

it('stores a shot once however many times the app has to send it', function () {
    Queue::fake();

    ['vendor' => $vendor, 'admin' => $admin, 'member' => $member] = appCaptureTeamUsers();
    Sanctum::actingAs($member, ['app']);
    $project = appCaptureProject($vendor);
    $timelapse = ProjectTimelapse::create(['project_id' => $project->id, 'title' => 'Back 1', 'kind' => ProjectTimelapse::KIND_TIMELAPSE]);

    $send = fn (string $uploadId) => $this->post("/api/app/v1/projects/{$project->id}/photos", [
        'photo' => UploadedFile::fake()->image('frame.jpg', 120, 90),
        'collection_id' => $timelapse->id,
        'client_upload_id' => $uploadId,
    ], ['Accept' => 'application/json']);

    $first = $send('shot-1a2b3c')->assertCreated();
    $again = $send('shot-1a2b3c')->assertOk();

    expect($again->json('data.id'))->toBe($first->json('data.id'))
        ->and($first->json('data.screen_url'))->toBeString()
        ->and($timelapse->frames()->count())->toBe(1);

    Queue::assertPushed(ProcessTimelapseFrame::class, 1);

    $send('shot-4d5e6f')->assertCreated();
    expect($timelapse->frames()->count())->toBe(2);

    // The id belongs to the person who sent it — a colleague reusing it
    // stores their own photo, they are not handed someone else's.
    Sanctum::actingAs($admin, ['app']);
    $theirs = $send('shot-1a2b3c')->assertCreated();

    expect($theirs->json('data.id'))->not->toBe($first->json('data.id'))
        ->and($timelapse->frames()->count())->toBe(3);
});

it('refuses an upload id that is not a plain token', function () {
    Queue::fake();

    ['vendor' => $vendor, 'member' => $member] = appCaptureTeamUsers();
    Sanctum::actingAs($member, ['app']);
    $project = appCaptureProject($vendor);

    $this->postJson("/api/app/v1/projects/{$project->id}/photos", [
        'photo' => UploadedFile::fake()->image('frame.jpg', 120, 90),
        'client_upload_id' => 'shot 1/../2',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['client_upload_id' => 'letters, numbers, dashes and underscores']);

    expect(ProjectTimelapseFrame::count())->toBe(0);
});
