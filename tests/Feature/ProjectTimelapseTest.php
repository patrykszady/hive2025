<?php

use App\Livewire\Projects\TimelapseStudio;
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
use Livewire\Livewire;

uses(RefreshDatabase::class);

function timelapseFixture(string $role = 'Admin'): array
{
    Storage::fake('files');

    $vendor = Vendor::factory()->create();
    // The vendor.access middleware redirects users of unregistered vendors,
    // so HTTP-route assertions need a registered one.
    $vendor->forceFill([
        'business_type' => 'LLC',
        'registration' => ['registered' => true],
    ])->save();

    $user = new User();
    $user->forceFill([
        'first_name' => 'Crew',
        'last_name' => 'Member',
        'email' => 'timelapse.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => $role === 'Admin' ? 1 : 2]);

    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Timelapse Test',
        'client_id' => $client->id,
        'address' => '400 N Wheeling Rd',
        'city' => 'Prospect Heights',
        'state' => 'IL',
        'zip_code' => '60070',
        'belongs_to_vendor_id' => $vendor->id,
    ]));
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    return ['vendor' => $vendor, 'user' => $user, 'project' => $project];
}

it('stores a captured frame in the gsc-compatible tables', function () {
    $fx = timelapseFixture();

    Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->set('frame', UploadedFile::fake()->image('frame.jpg', 2400, 1800))
        ->assertHasNoErrors();

    // With no collection chosen the shot goes to the project's catch-all
    // album — never lost, never silently appended to some sequence.
    $timelapse = ProjectTimelapse::where('project_id', $fx['project']->id)
        ->where('title', 'Project Images')->firstOrFail();

    expect($timelapse->kind)->toBe('gallery')
        ->and($timelapse->display_mode)->toBe('slider');

    $frame = $timelapse->frames()->firstOrFail();

    expect($frame->disk)->toBe('files')
        ->and($frame->path)->toStartWith("timelapse/{$fx['project']->id}/")
        ->and($frame->sort_order)->toBe(1)
        ->and($frame->taken_by_user_id)->toBe($fx['user']->id);

    Storage::disk('files')->assertExists($frame->path);

    // Longest edge capped to MAX_EDGE.
    [$width] = getimagesizefromstring(Storage::disk('files')->get($frame->path));
    expect($width)->toBeLessThanOrEqual(TimelapseStudio::MAX_EDGE);
});

it('numbers frames sequentially so the gsc slider plays them in order', function () {
    $fx = timelapseFixture();

    $component = Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']]);

    $component->set('frame', UploadedFile::fake()->image('one.jpg', 800, 600));
    $component->set('frame', UploadedFile::fake()->image('two.jpg', 800, 600));
    $component->set('file', UploadedFile::fake()->image('three.jpg', 800, 600))
        ->call('uploadFile')->assertHasNoErrors();

    expect(ProjectTimelapseFrame::orderBy('id')->pluck('sort_order')->all())->toBe([1, 2, 3]);
});

it('lets a member delete their own frame but not someone else\'s', function () {
    $fx = timelapseFixture(role: 'Member');

    $timelapse = ProjectTimelapse::defaultFor($fx['project']);

    $other = User::query()->create([
        'first_name' => 'Other',
        'last_name' => 'Crew',
        'email' => 'other.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224666####'),
    ]);

    Storage::disk('files')->put("timelapse/{$fx['project']->id}/own.jpg", 'x');
    Storage::disk('files')->put("timelapse/{$fx['project']->id}/theirs.jpg", 'x');

    $own = ProjectTimelapseFrame::create([
        'project_timelapse_id' => $timelapse->id,
        'taken_by_user_id' => $fx['user']->id,
        'filename' => 'own.jpg',
        'path' => "timelapse/{$fx['project']->id}/own.jpg",
        'disk' => 'files',
        'sort_order' => 1,
    ]);
    $theirs = ProjectTimelapseFrame::create([
        'project_timelapse_id' => $timelapse->id,
        'taken_by_user_id' => $other->id,
        'filename' => 'theirs.jpg',
        'path' => "timelapse/{$fx['project']->id}/theirs.jpg",
        'disk' => 'files',
        'sort_order' => 2,
    ]);

    $component = Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']]);

    $component->call('deleteFrame', $own->id);
    expect(ProjectTimelapseFrame::find($own->id))->toBeNull();
    // Deleting removes the file too (gsc model behavior).
    Storage::disk('files')->assertMissing("timelapse/{$fx['project']->id}/own.jpg");

    $component->call('deleteFrame', $theirs->id)->assertStatus(403);
    expect(ProjectTimelapseFrame::find($theirs->id))->not->toBeNull();
});

it('streams a frame only to someone who can view the project', function () {
    $fx = timelapseFixture();

    $timelapse = ProjectTimelapse::defaultFor($fx['project']);
    Storage::disk('files')->put("timelapse/{$fx['project']->id}/f.jpg", 'jpegbytes');

    $frame = ProjectTimelapseFrame::create([
        'project_timelapse_id' => $timelapse->id,
        'filename' => 'f.jpg',
        'path' => "timelapse/{$fx['project']->id}/f.jpg",
        'disk' => 'files',
        'sort_order' => 1,
    ]);

    $this->actingAs($fx['user'])
        ->get(route('projects.timelapse.frame', $frame))
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'image/jpeg');

    // A user from an unrelated vendor: the project resolves out of scope.
    $strangerVendor = Vendor::factory()->create();
    $strangerVendor->forceFill([
        'business_type' => 'LLC',
        'registration' => ['registered' => true],
    ])->save();
    $stranger = new User();
    $stranger->forceFill([
        'first_name' => 'Stranger',
        'last_name' => 'Vendor',
        'email' => 'stranger.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224777####'),
        'primary_vendor_id' => $strangerVendor->id,
        'registration' => ['registered' => true],
    ]);
    $stranger->save();
    $strangerVendor->users()->attach($stranger->id, ['role_id' => 1]);

    $this->actingAs($stranger)
        ->get(route('projects.timelapse.frame', $frame))
        ->assertStatus(404);
});

it('opens the studio for a crew member of the project vendor', function () {
    $fx = timelapseFixture(role: 'Member');

    // The camera is closed on arrival — no unrequested permission prompt,
    // and no camera card at all: the collections take the whole width.
    $this->actingAs($fx['user'])
        ->get(route('projects.images', $fx['project']))
        ->assertSuccessful()
        ->assertSee('Project Images')
        // No viewfinder and no camera card — "Camera" itself still appears in
        // the permission callout's script, so the shutter is the tell.
        ->assertDontSee('Take Frame')
        ->assertDontSee('Upload a photo instead');
});

it('serves the untouched original on request, with a download name', function () {
    $fx = timelapseFixture();

    $timelapse = ProjectTimelapse::defaultFor($fx['project']);
    Storage::disk('files')->put("timelapse/{$fx['project']->id}/raw.jpg", 'original-bytes');
    Storage::disk('files')->put("timelapse/{$fx['project']->id}/aligned-raw.jpg", 'aligned-bytes');

    $frame = ProjectTimelapseFrame::create([
        'project_timelapse_id' => $timelapse->id,
        'filename' => 'raw.jpg',
        'path' => "timelapse/{$fx['project']->id}/raw.jpg",
        'aligned_path' => "timelapse/{$fx['project']->id}/aligned-raw.jpg",
        'disk' => 'files',
        'sort_order' => 3,
    ]);

    // Default view = the aligned copy.
    $this->actingAs($fx['user'])
        ->get(route('projects.timelapse.frame', $frame))
        ->assertSuccessful()
        ->assertSee('aligned-bytes', false);

    // ?original=1 = the raw shot, and download names it clearly.
    $this->actingAs($fx['user'])
        ->get(route('projects.timelapse.frame', $frame).'?original=1&download=1')
        ->assertSuccessful()
        ->assertSee('original-bytes', false)
        ->assertHeader('Content-Disposition', 'attachment; filename="timelapse-'.$fx['project']->id.'-frame-003-original.jpg"');
});

it('keeps several collections per project and shoots into the chosen one', function () {
    $fx = timelapseFixture();
    $user = $fx['user'];
    $project = $fx['project'];

    $component = Livewire::actingAs($user)->test(TimelapseStudio::class, ['project' => $project]);

    // A project starts with just its catch-all album, camera closed.
    expect($component->instance()->collections)->toHaveCount(1)
        ->and($component->instance()->collections->first()->title)->toBe('Project Images')
        ->and($component->instance()->collection)->toBeNull();

    $component->set('newTitle', 'Bathroom Timelapse')->call('createCollection');

    // Creating switches the camera to the new collection — you made it to use it.
    expect($component->instance()->collections)->toHaveCount(2)
        ->and($component->instance()->collection->title)->toBe('Bathroom Timelapse');

    // Shooting lands in the SELECTED collection, not the default.
    $component->set('file', UploadedFile::fake()->image('bath.jpg', 800, 600))->call('uploadFile');

    $bathroom = ProjectTimelapse::where('project_id', $project->id)->where('title', 'Bathroom Timelapse')->first();

    expect($bathroom->frames()->count())->toBe(1)
        ->and(ProjectTimelapse::where('project_id', $project->id)->where('title', 'Project Images')->first()->frames()->count())->toBe(0);
});

it('refuses a duplicate collection name on the same project', function () {
    $fx = timelapseFixture();
    $user = $fx['user'];
    $project = $fx['project'];

    Livewire::actingAs($user)->test(TimelapseStudio::class, ['project' => $project])
        ->set('newTitle', 'Kitchen')->call('createCollection')
        ->set('newTitle', 'Kitchen')->call('createCollection')
        ->assertHasErrors('newTitle');

    expect(ProjectTimelapse::where('project_id', $project->id)->where('title', 'Kitchen')->count())->toBe(1);
});

it('does not align the project album — it is not a sequence', function () {
    Queue::fake();
    $fx = timelapseFixture();

    $component = Livewire::actingAs($fx['user'])->test(TimelapseStudio::class, ['project' => $fx['project']]);

    $album = $component->instance()->collections->firstWhere('title', 'Project Images');

    // gs.construction only understands its own display modes; the album must
    // not claim one that would render it there as a before/after slider.
    expect($album->kind)->toBe('gallery')
        ->and($album->display_mode)->toBe('slider')
        ->and($album->isTimelapse())->toBeFalse();

    $component->call('selectCollection', $album->id)
        ->set('file', UploadedFile::fake()->image('photo.jpg', 800, 600))
        ->call('uploadFile');

    Queue::assertNotPushed(\App\Jobs\AlignTimelapseFrame::class);
});

it('only ever creates timelapses — static photos belong to the project album', function () {
    $fx = timelapseFixture();

    $component = Livewire::actingAs($fx['user'])->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->set('newTitle', 'Kitchen')->call('createCollection');

    expect($component->instance()->collection->kind)->toBe('timelapse');

    // And the one album can't be deleted out from under loose photos.
    $album = $component->instance()->collections->firstWhere('title', 'Project Images');

    $component->call('deleteCollection', $album->id)->assertForbidden();
});

it('records when a photo was taken before re-encoding strips the EXIF', function () {
    $fx = timelapseFixture();
    $user = $fx['user'];
    $project = $fx['project'];

    Livewire::actingAs($user)->test(TimelapseStudio::class, ['project' => $project])
        ->set('file', UploadedFile::fake()->image('shot.jpg', 800, 600))
        ->call('uploadFile');

    // No EXIF on a fake upload, so it falls back — but never null, because
    // after storage the true capture time is unrecoverable.
    expect(ProjectTimelapseFrame::latest('id')->first()->shot_at)->not->toBeNull();
});

it('sends an unaimed upload to the catch-all album, not a sequence', function () {
    $fx = timelapseFixture();

    // A timelapse exists, but the camera was never pointed at it.
    $component = Livewire::actingAs($fx['user'])->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->set('newTitle', 'Kitchen Timelapse')->call('createCollection')
        ->call('closeCamera')
        ->set('file', UploadedFile::fake()->image('loose.jpg', 800, 600))
        ->call('uploadFile');

    $general = ProjectTimelapse::where('project_id', $fx['project']->id)->where('title', 'Project Images')->firstOrFail();
    $kitchen = ProjectTimelapse::where('project_id', $fx['project']->id)->where('title', 'Kitchen Timelapse')->firstOrFail();

    expect($general->frames()->count())->toBe(1)
        ->and($kitchen->frames()->count())->toBe(0)
        ->and($component->instance()->collection)->toBeNull();
});

it('keeps the camera original untouched alongside the sequence copy', function () {
    $fx = timelapseFixture();

    // A 3000px shot: bigger than the sequence cap, so the two copies must differ.
    Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->set('frame', UploadedFile::fake()->image('huge.jpg', 3000, 2250))
        ->assertHasNoErrors();

    $frame = ProjectTimelapseFrame::latest('id')->firstOrFail();

    expect($frame->original_path)->not->toBeNull()
        ->and($frame->original_path)->not->toBe($frame->path);

    Storage::disk('files')->assertExists($frame->original_path);
    Storage::disk('files')->assertExists($frame->path);

    [$originalW] = getimagesizefromstring(Storage::disk('files')->get($frame->original_path));
    [$sequenceW] = getimagesizefromstring(Storage::disk('files')->get($frame->path));

    // The archive copy keeps every pixel; the sequence copy is capped.
    expect($originalW)->toBe(3000)
        ->and($sequenceW)->toBe(TimelapseStudio::MAX_EDGE);
});

it('serves the archive copy for ?original=1, and the aligned one otherwise', function () {
    $fx = timelapseFixture();

    Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->set('frame', UploadedFile::fake()->image('shot.jpg', 3000, 2250));

    $frame = ProjectTimelapseFrame::latest('id')->firstOrFail();

    $original = $this->actingAs($fx['user'])
        ->get(route('projects.timelapse.frame', ['frame' => $frame, 'original' => 1]));

    $display = $this->actingAs($fx['user'])->get(route('projects.timelapse.frame', $frame));

    $original->assertSuccessful();
    $display->assertSuccessful();

    [$originalW] = getimagesizefromstring($original->getContent());
    [$displayW] = getimagesizefromstring($display->getContent());

    expect($originalW)->toBe(3000)
        ->and($displayW)->toBe(TimelapseStudio::MAX_EDGE);
});

it('serves a small cached copy for ?thumb=1, built once and kept', function () {
    $fx = timelapseFixture();

    Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->set('frame', UploadedFile::fake()->image('shot.jpg', 3000, 2250));

    $frame = ProjectTimelapseFrame::latest('id')->firstOrFail();
    $url = route('projects.timelapse.frame', [$frame, 'thumb' => 1]);

    $thumb = $this->actingAs($fx['user'])->get($url);
    $thumb->assertSuccessful();

    [$width] = getimagesizefromstring($thumb->streamedContent());

    // A grid tile is ~120px; sending the 1920px sequence frame for each one is
    // what made "Show more" crawl.
    expect($width)->toBe(\App\Support\ImageThumbs::MAX_EDGE)
        ->and($thumb->headers->get('Cache-Control'))->toContain('immutable');

    // Second ask is served off disk — same bytes, no re-encode.
    $again = $this->actingAs($fx['user'])->get($url);

    expect($again->streamedContent())->toBe($thumb->streamedContent());

    // The cache lives outside the faked disk, so clear what this test wrote.
    array_map('unlink', glob(storage_path('app/thumbs/*.jpg')) ?: []);
});

it('points photo grids at the thumbnail while the lightbox keeps the original', function () {
    $fx = timelapseFixture();

    $client = \App\Models\Client::factory()->create();
    $fx['project']->forceFill(['client_id' => $client->id])->save();

    $thread = \App\Models\SmsGroupThread::create([
        'from_number' => '+12247354200',
        'client_id' => $client->id,
        'vendor_id' => $fx['vendor']->id,
        'participants' => ['+18475550101'],
    ]);

    \App\Models\SmsMessage::create([
        'thread_id' => $thread->id,
        'direction' => 'inbound',
        'from_number' => '+18475550101',
        'media_urls' => ['sms-media/2026/05/relative.jpg'],
    ]);

    $image = Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->instance()->messageImages->first();

    expect($image['thumb'])->toContain('thumb=1')
        ->and($image['url'])->not->toContain('thumb=1');
});

it('removes every copy when a frame is deleted', function () {
    $fx = timelapseFixture();

    Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->set('frame', UploadedFile::fake()->image('shot.jpg', 2400, 1800));

    $frame = ProjectTimelapseFrame::latest('id')->firstOrFail();
    $paths = array_filter([$frame->path, $frame->original_path]);

    $frame->delete();

    foreach ($paths as $path) {
        Storage::disk('files')->assertMissing($path);
    }
});

it('shows photos texted about the project alongside its own', function () {
    $fx = timelapseFixture();

    $client = \App\Models\Client::factory()->create();
    $fx['project']->forceFill(['client_id' => $client->id])->save();

    $thread = \App\Models\SmsGroupThread::create([
        'from_number' => '+12247354200',
        'client_id' => $client->id,
        'vendor_id' => $fx['vendor']->id,
        'participants' => ['+18475550101'],
    ]);

    // A known contact texting in: the card must name them, not their number.
    $contact = User::query()->create([
        'first_name' => 'Mark',
        'last_name' => 'Brodson',
        'email' => 'mark.texts.'.uniqid().'@example.com',
        'cell_phone' => '8475550142',
    ]);

    \App\Models\SmsMessage::create([
        'thread_id' => $thread->id,
        'direction' => 'inbound',
        'from_number' => '+1'.$contact->cell_phone,
        'body' => 'Here is the wall',
        'media_urls' => ['/storage/sms-media/2026/08/wall.jpg', '/storage/sms-media/2026/08/plans.pdf'],
    ]);

    $component = Livewire::actingAs($fx['user'])->test(TimelapseStudio::class, ['project' => $fx['project']]);

    $images = $component->instance()->messageImages;

    // The PDF on the same message is not an image and must not appear.
    expect($images)->toHaveCount(1)
        // Served through the authed streaming route, never the raw stored path
        // — the files live on the private disk.
        ->and($images->first()['url'])->toContain('/files/sms_media/')
        ->and($images->first()['url'])->toEndWith('2026/08/wall.jpg')
        // Named from the phone number, the way the messages screen does it.
        ->and($images->first()['sender'])->toBe('Mark')
        ->and($images->first()['label'])->toStartWith('Mark · ');

    $component->assertSee('Message Images');
});

it('shows no texted photos when the project has none', function () {
    $fx = timelapseFixture();

    $component = Livewire::actingAs($fx['user'])->test(TimelapseStudio::class, ['project' => $fx['project']]);

    expect($component->instance()->messageImages)->toBeEmpty();

    $component->assertDontSee('Message Images');
});

it('names the crew member who texted a photo out', function () {
    $fx = timelapseFixture();

    $client = \App\Models\Client::factory()->create();
    $fx['project']->forceFill(['client_id' => $client->id])->save();

    $thread = \App\Models\SmsGroupThread::create([
        'from_number' => '+12247354200',
        'client_id' => $client->id,
        'vendor_id' => $fx['vendor']->id,
        'participants' => ['+18475550101'],
    ]);

    \App\Models\SmsMessage::create([
        'thread_id' => $thread->id,
        'direction' => 'outbound',
        'sent_by_user_id' => $fx['user']->id,
        'body' => 'Cabinets went in',
        'media_urls' => ['/storage/sms-media/2026/08/cabinets.jpg'],
    ]);

    $images = Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->instance()->messageImages;

    expect($images->first()['sender'])->toContain($fx['user']->first_name);
});

it('serves texted photos through the media route whatever shape the stored url is', function () {
    $fx = timelapseFixture();

    $client = \App\Models\Client::factory()->create();
    $fx['project']->forceFill(['client_id' => $client->id])->save();

    $thread = \App\Models\SmsGroupThread::create([
        'from_number' => '+12247354200',
        'client_id' => $client->id,
        'vendor_id' => $fx['vendor']->id,
        'participants' => ['+18475550101'],
    ]);

    // Both shapes occur in real data. A bare path used to be emitted verbatim,
    // so the browser resolved it against /projects/{id}/images and 404'd —
    // which is why a whole thread's photos silently vanished from this card.
    \App\Models\SmsMessage::create([
        'thread_id' => $thread->id,
        'direction' => 'inbound',
        'from_number' => '+18475550101',
        'media_urls' => [
            'sms-media/2026/05/relative.jpg',
            '/storage/sms-media/2026/05/prefixed.jpg',
        ],
    ]);

    $images = Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->instance()->messageImages;

    expect($images)->toHaveCount(2);

    foreach ($images as $image) {
        expect($image['url'])->toStartWith('http')
            ->and($image['url'])->toContain('/files/sms_media/')
            ->and($image['url'])->toEndWith('.jpg');
    }
});

it('stamps a camera capture with the browser fix and shutter time', function () {
    $fx = timelapseFixture();

    $takenAt = now()->subSeconds(8)->startOfSecond();

    Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->set('captureMeta', [
            'lat' => 41.8850012,
            'lng' => -87.7940031,
            'accuracy' => 12.4,
            'takenAt' => $takenAt->toIso8601String(),
        ])
        ->set('frame', UploadedFile::fake()->image('frame.jpg', 1600, 1200))
        ->assertHasNoErrors();

    $frame = \App\Models\ProjectTimelapseFrame::latest('id')->first();

    expect($frame->latitude)->toEqualWithDelta(41.8850012, 0.0000001)
        ->and($frame->longitude)->toEqualWithDelta(-87.7940031, 0.0000001)
        ->and($frame->location_accuracy)->toBe(12)
        ->and($frame->shot_at->timestamp)->toBe($takenAt->timestamp);
});

it('drops off-globe coordinates and broken phone clocks instead of storing them', function () {
    $fx = timelapseFixture();

    Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->set('captureMeta', [
            'lat' => 999,
            'lng' => -87.79,
            'accuracy' => 5,
            'takenAt' => now()->addYears(2)->toIso8601String(),
        ])
        ->set('frame', UploadedFile::fake()->image('frame.jpg', 1600, 1200))
        ->assertHasNoErrors();

    $frame = \App\Models\ProjectTimelapseFrame::latest('id')->first();

    expect($frame->latitude)->toBeNull()
        ->and($frame->longitude)->toBeNull()
        // Rejected clock → the upload moment stands in.
        ->and($frame->shot_at->diffInMinutes(now()))->toBeLessThan(2);
});

it('converts EXIF degree/minute/second rationals to signed decimals', function () {
    expect(\App\Services\ProjectImageImporter::gpsToDecimal(['41/1', '52/1', '3456/100'], 'N'))
        ->toEqualWithDelta(41.87626667, 0.000001)
        ->and(\App\Services\ProjectImageImporter::gpsToDecimal(['87/1', '47/1', '2160/100'], 'W'))
        ->toEqualWithDelta(-87.78933333, 0.000001)
        ->and(\App\Services\ProjectImageImporter::gpsToDecimal(['33/1', '51/1', '0/0'], 'S'))->toBeNull();
});

it('shows photos from a message pinned to the project via Add to Project', function () {
    $fx = timelapseFixture();

    // A crew thread with NO client or project link — its photos would never
    // reach this project on their own.
    $thread = \App\Models\SmsGroupThread::create([
        'from_number' => '+12247354200',
        'vendor_id' => $fx['vendor']->id,
        'participants' => ['+17735550142'],
    ]);

    $message = \App\Models\SmsMessage::create([
        'thread_id' => $thread->id,
        'direction' => 'inbound',
        'from_number' => '+17735550142',
        'media_urls' => ['sms-media/2026/07/demo-day.jpg'],
    ]);

    \Illuminate\Support\Facades\DB::table('project_pinned_messages')->insert([
        'project_id' => $fx['project']->id,
        'sms_message_id' => $message->id,
        'added_by_user_id' => $fx['user']->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $images = Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->instance()->messageImages;

    expect($images)->toHaveCount(1)
        ->and($images->first()['url'])->toContain('/files/sms_media/')
        // Pinned photos keep their sender, resolved from the phone number.
        ->and($images->first()['sender'])->not->toBeEmpty();
});

it('shortens sender names to first names unless two people share one', function () {
    $short = TimelapseStudio::firstNames([
        1 => 'Mark Brodson',
        2 => 'Mark Smith',
        3 => 'Patryk Szady',
        4 => 'Mark & Gail Brodson',
        5 => '(773) 251-3666',
    ]);

    expect($short[1])->toBe('Mark Brodson')      // collides with Mark Smith
        ->and($short[2])->toBe('Mark Smith')
        ->and($short[3])->toBe('Patryk')
        ->and($short[4])->toBe('Mark & Gail')
        ->and($short[5])->toBe('(773) 251-3666'); // numbers pass through
});

it('files a queued capture into the collection it was shot in, not the one now open', function () {
    $fx = timelapseFixture();

    $component = Livewire::actingAs($fx['user'])->test(TimelapseStudio::class, ['project' => $fx['project']]);

    $component->set('newTitle', 'Kitchen Timelapse')->call('createCollection');
    $kitchen = ProjectTimelapse::where('project_id', $fx['project']->id)->where('title', 'Kitchen Timelapse')->firstOrFail();
    $general = ProjectTimelapse::where('project_id', $fx['project']->id)->where('title', 'Project Images')->firstOrFail();

    // The camera stamps the shot's collection into the filename; by the time
    // the upload lands, the user has switched the camera to the album.
    $component->call('selectCollection', $general->id)
        ->set('frame', UploadedFile::fake()->image("frame-{$kitchen->id}.jpg", 800, 600));

    expect($kitchen->frames()->count())->toBe(1)
        ->and($general->frames()->count())->toBe(0);

    // A filename pointing at another project's collection resolves to nothing
    // and falls back to the open collection.
    $foreign = ProjectTimelapse::withoutEvents(fn () => ProjectTimelapse::create([
        'project_id' => \App\Models\Project::withoutEvents(fn () => \App\Models\Project::create([
            'project_name' => 'Elsewhere', 'client_id' => \App\Models\Client::factory()->create()->id,
            'belongs_to_vendor_id' => Vendor::factory()->create()->id,
            'address' => '1 Other St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
        ]))->id,
        'title' => 'Not Yours', 'kind' => ProjectTimelapse::KIND_TIMELAPSE, 'display_mode' => 'slider', 'sort_order' => 1,
    ]));

    $component->set('frame', UploadedFile::fake()->image("frame-{$foreign->id}.jpg", 800, 600));

    expect($foreign->frames()->count())->toBe(0)
        ->and($general->frames()->count())->toBe(1);
});
