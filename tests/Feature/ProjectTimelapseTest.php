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

it('lets only owning-vendor admins delete frames — even the taker cannot', function () {
    $fx = timelapseFixture(role: 'Member');

    $timelapse = ProjectTimelapse::defaultFor($fx['project']);

    Storage::disk('files')->put("timelapse/{$fx['project']->id}/own.jpg", 'x');

    $own = ProjectTimelapseFrame::create([
        'project_timelapse_id' => $timelapse->id,
        'taken_by_user_id' => $fx['user']->id,
        'filename' => 'own.jpg',
        'path' => "timelapse/{$fx['project']->id}/own.jpg",
        'disk' => 'files',
        'sort_order' => 1,
    ]);

    // The crew member who SHOT it still can't remove it — curating the
    // project's record belongs to the owning vendor's admins.
    Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->call('deleteFrame', $own->id)
        ->assertForbidden();

    expect(ProjectTimelapseFrame::find($own->id))->not->toBeNull();

    // An Admin at the vendor that owns the project can, and it is SOFT.
    $admin = new User();
    $admin->forceFill([
        'first_name' => 'Owning',
        'last_name' => 'Admin',
        'email' => 'owning.admin.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224777####'),
        'primary_vendor_id' => $fx['vendor']->id,
        'registration' => ['registered' => true],
    ]);
    $admin->save();
    $fx['vendor']->users()->attach($admin->id, ['role_id' => 1]);

    Livewire::actingAs($admin)
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->call('deleteFrame', $own->id);

    expect(ProjectTimelapseFrame::find($own->id))->toBeNull()
        ->and(ProjectTimelapseFrame::withTrashed()->find($own->id)->trashed())->toBeTrue();
    // Soft delete keeps the file — only a force delete removes copies.
    Storage::disk('files')->assertExists("timelapse/{$fx['project']->id}/own.jpg");
});

it('locks image curation to the owning vendor — an outside admin can look but not touch', function () {
    $fx = timelapseFixture();

    $timelapse = \App\Models\ProjectTimelapse::create([
        'project_id' => $fx['project']->id,
        'title' => 'Back Wall',
        'kind' => 'timelapse',
    ]);
    Storage::disk('files')->put("timelapse/{$fx['project']->id}/a.jpg", 'a');
    Storage::disk('files')->put("timelapse/{$fx['project']->id}/b.jpg", 'b');
    $first = ProjectTimelapseFrame::create([
        'project_timelapse_id' => $timelapse->id, 'filename' => 'a.jpg',
        'path' => "timelapse/{$fx['project']->id}/a.jpg", 'disk' => 'files', 'sort_order' => 1,
    ]);
    $second = ProjectTimelapseFrame::create([
        'project_timelapse_id' => $timelapse->id, 'filename' => 'b.jpg',
        'path' => "timelapse/{$fx['project']->id}/b.jpg", 'disk' => 'files', 'sort_order' => 2,
    ]);

    // An Admin — but at ANOTHER vendor, collaborating on this project.
    $otherVendor = Vendor::factory()->create();
    $otherVendor->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();
    $outsider = new User();
    $outsider->forceFill([
        'first_name' => 'Sub',
        'last_name' => 'Admin',
        'email' => 'sub.admin.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224888####'),
        'primary_vendor_id' => $otherVendor->id,
        'registration' => ['registered' => true],
    ]);
    $outsider->save();
    $otherVendor->users()->attach($outsider->id, ['role_id' => 1]);
    $fx['project']->vendors()->attach($otherVendor->id, ['client_id' => $fx['project']->client_id]);

    expect($outsider->vendor_role)->toBe('Admin');

    $component = Livewire::actingAs($outsider)
        ->test(TimelapseStudio::class, ['project' => $fx['project']]);

    // They can SEE the photos…
    $component->assertSet('canManageImages', false)
        ->assertOk()
        // …but no management chrome is offered.
        ->assertDontSee('Edit timelapse')
        ->assertDontSee('Move frame earlier');

    // And every mutating call is refused server-side.
    foreach ([
        ['toggleEditCollection', [$timelapse->id]],
        ['deleteCollection', [$timelapse->id]],
        ['moveFrame', [$second->id, -1]],
        ['deleteFrame', [$first->id]],
        ['setAlignmentAnchor', [$second->id]],
        ['openFrameAligner', [$second->id]],
        ['clearAlignment', [$second->id]],
    ] as [$method, $args]) {
        Livewire::actingAs($outsider)
            ->test(TimelapseStudio::class, ['project' => $fx['project']])
            ->call($method, ...$args)
            ->assertForbidden();
    }

    expect($timelapse->fresh())->not->toBeNull()
        ->and($first->fresh())->not->toBeNull()
        ->and($timelapse->frames()->pluck('filename')->all())->toBe(['a.jpg', 'b.jpg']);
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

    // The raw shot lives at its own unguessable address now, and download
    // names it clearly. (?original=1 on the frame route is dead — see
    // ArchiveOriginalAccessTest.)
    $this->actingAs($fx['user'])
        ->get(route('projects.timelapse.original', ['token' => $frame->archive_token]).'?download=1')
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

it('serves the archive copy at its own token address, and the display copy otherwise', function () {
    $fx = timelapseFixture();

    Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->set('frame', UploadedFile::fake()->image('shot.jpg', 3000, 2250));

    $frame = ProjectTimelapseFrame::latest('id')->firstOrFail();

    $original = $this->actingAs($fx['user'])
        ->get(route('projects.timelapse.original', ['token' => $frame->archive_token]));

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

it('soft-deletes a frame keeping every copy, and removes them only on force delete', function () {
    $fx = timelapseFixture();

    Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->set('frame', UploadedFile::fake()->image('shot.jpg', 2400, 1800));

    $frame = ProjectTimelapseFrame::latest('id')->firstOrFail();
    $paths = array_filter([$frame->path, $frame->original_path]);

    // Soft delete: recoverable, files intact, hidden from the sequence.
    $frame->delete();
    expect($frame->fresh()->trashed())->toBeTrue()
        ->and($frame->timelapse->frames()->count())->toBe(0);

    foreach ($paths as $path) {
        Storage::disk('files')->assertExists($path);
    }

    // Force delete is the destructive one.
    $frame->forceDelete();

    foreach ($paths as $path) {
        Storage::disk('files')->assertMissing($path);
    }
});

it('reorders frames in edit mode and renumbers sort orders cleanly', function () {
    $fx = timelapseFixture();

    $timelapse = \App\Models\ProjectTimelapse::create([
        'project_id' => $fx['project']->id,
        'title' => 'Back Wall',
        'kind' => 'timelapse',
    ]);

    $frames = collect(['a', 'b', 'c'])->map(function ($name, $i) use ($fx, $timelapse) {
        Storage::disk('files')->put("timelapse/{$fx['project']->id}/{$name}.jpg", $name);

        return ProjectTimelapseFrame::create([
            'project_timelapse_id' => $timelapse->id,
            'filename' => "{$name}.jpg",
            'path' => "timelapse/{$fx['project']->id}/{$name}.jpg",
            'disk' => 'files',
            // Deliberately gappy orders — imports leave 0s and holes.
            'sort_order' => [0, 2, 5][$i],
        ]);
    });

    $component = Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']]);

    // Move the last frame one slot earlier: a, c, b — renumbered 1..3.
    $component->call('moveFrame', $frames[2]->id, -1);

    expect($timelapse->frames()->pluck('filename')->all())->toBe(['a.jpg', 'c.jpg', 'b.jpg'])
        ->and($timelapse->frames()->pluck('sort_order')->all())->toBe([1, 2, 3]);

    // Moving the first frame earlier is a quiet no-op.
    $component->call('moveFrame', $frames[0]->id, -1);
    expect($timelapse->frames()->pluck('filename')->all())->toBe(['a.jpg', 'c.jpg', 'b.jpg']);

    // Members cannot reorder.
    $member = timelapseFixture('Member');
    Storage::disk('files')->put("timelapse/{$member['project']->id}/m.jpg", 'm');
    $mt = \App\Models\ProjectTimelapse::create(['project_id' => $member['project']->id, 'title' => 'M', 'kind' => 'timelapse']);
    $mf = ProjectTimelapseFrame::create([
        'project_timelapse_id' => $mt->id, 'filename' => 'm.jpg',
        'path' => "timelapse/{$member['project']->id}/m.jpg", 'disk' => 'files', 'sort_order' => 1,
    ]);

    Livewire::actingAs($member['user'])
        ->test(TimelapseStudio::class, ['project' => $member['project']])
        ->call('moveFrame', $mf->id, 1)
        ->assertForbidden();
});

it('keeps frame thumbnails clean until a timelapse enters edit mode', function () {
    $fx = timelapseFixture();

    $timelapse = \App\Models\ProjectTimelapse::create([
        'project_id' => $fx['project']->id,
        'title' => 'Back Wall',
        'kind' => 'timelapse',
    ]);
    foreach (['a', 'b'] as $i => $name) {
        Storage::disk('files')->put("timelapse/{$fx['project']->id}/{$name}.jpg", $name);
        ProjectTimelapseFrame::create([
            'project_timelapse_id' => $timelapse->id, 'filename' => "{$name}.jpg",
            'path' => "timelapse/{$fx['project']->id}/{$name}.jpg", 'disk' => 'files', 'sort_order' => $i + 1,
        ]);
    }

    $component = Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']]);

    // No management chrome by default — not on the thumbnails, and no
    // delete / Select in the card header either.
    $component->assertDontSee('Align frame manually')
        ->assertDontSee('Move frame earlier')
        ->assertDontSee('Delete “Back Wall”')
        ->assertDontSee('Select photos in Back Wall');

    // …until the card enters edit mode.
    $component->call('toggleEditCollection', $timelapse->id)
        ->assertSet('editingCollectionId', $timelapse->id)
        ->assertSee('Align frame manually')
        ->assertSee('Move frame earlier')
        ->assertSee('Delete “Back Wall”')
        ->assertSee('Select photos in Back Wall');

    // Toggling again leaves edit mode.
    $component->call('toggleEditCollection', $timelapse->id)
        ->assertSet('editingCollectionId', null)
        ->assertDontSee('Move frame earlier')
        ->assertDontSee('Delete “Back Wall”');

    // Members never get edit mode.
    $member = timelapseFixture('Member');
    $mt = \App\Models\ProjectTimelapse::create(['project_id' => $member['project']->id, 'title' => 'M', 'kind' => 'timelapse']);

    Livewire::actingAs($member['user'])
        ->test(TimelapseStudio::class, ['project' => $member['project']])
        ->call('toggleEditCollection', $mt->id)
        ->assertForbidden();
});

it('soft-deletes a timelapse so it can be restored with its frames intact', function () {
    $fx = timelapseFixture();

    $timelapse = \App\Models\ProjectTimelapse::create([
        'project_id' => $fx['project']->id,
        'title' => 'Back Wall',
        'kind' => 'timelapse',
    ]);
    Storage::disk('files')->put("timelapse/{$fx['project']->id}/a.jpg", 'a');
    $frame = ProjectTimelapseFrame::create([
        'project_timelapse_id' => $timelapse->id, 'filename' => 'a.jpg',
        'path' => "timelapse/{$fx['project']->id}/a.jpg", 'disk' => 'files', 'sort_order' => 1,
    ]);

    Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->call('deleteCollection', $timelapse->id);

    // Hidden from the studio, but the row survives…
    expect(\App\Models\ProjectTimelapse::find($timelapse->id))->toBeNull()
        ->and(\App\Models\ProjectTimelapse::withTrashed()->find($timelapse->id)->trashed())->toBeTrue()
        // …and the frames were left completely untouched, file included.
        ->and($frame->fresh()->trashed())->toBeFalse();
    Storage::disk('files')->assertExists("timelapse/{$fx['project']->id}/a.jpg");

    // Restoring brings the whole timelapse back.
    \App\Models\ProjectTimelapse::withTrashed()->find($timelapse->id)->restore();
    expect(\App\Models\ProjectTimelapse::find($timelapse->id))->not->toBeNull();
});

it('converts a HEIC upload to JPEG, keeps its shot date, and can be moved to the front', function () {
    // The converter is the aligner venv's python + pillow-heif; without it
    // (CI without the venv) the ImageMagick fallback may still work, but the
    // EXIF assertion would not hold — so this test needs the real thing.
    $python = (string) config('services.timelapse_align.python');
    $probe = is_executable($python)
        ? new \Symfony\Component\Process\Process([$python, '-c', 'import pillow_heif, piexif'])
        : null;
    $probe?->run();

    if (! $probe?->isSuccessful()) {
        $this->markTestSkipped('HEIC-capable python venv not available on this machine.');
    }

    $fx = timelapseFixture();

    $component = Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->set('newTitle', 'Back Wall')
        ->call('createCollection')
        ->set('frame', UploadedFile::fake()->image('shot.jpg', 2400, 1800));

    $existing = ProjectTimelapseFrame::latest('id')->firstOrFail();

    $component->set('file', UploadedFile::fake()->createWithContent('before.heic', file_get_contents(base_path('tests/fixtures/frame.heic'))))
        ->call('uploadFile')
        ->assertHasNoErrors();

    $imported = $existing->timelapse->frames()->where('original_filename', 'before.jpg')->firstOrFail();

    // Uploads append; edit mode moves the "before" shot to the front.
    $component->call('moveFrame', $imported->id, -1);

    $frames = $existing->timelapse->frames()->get();
    $lead = $frames->first();

    expect($frames)->toHaveCount(2)
        ->and($lead->id)->toBe($imported->id)
        ->and($lead->original_filename)->toBe('before.jpg')
        // The archive copy is the converted full-frame JPEG.
        ->and(getimagesizefromstring(Storage::disk('files')->get($lead->original_path))[2])->toBe(IMAGETYPE_JPEG)
        // The HEIC's own EXIF date survived the conversion.
        ->and(\Carbon\Carbon::parse($lead->shot_at)->format('Y-m-d H:i'))->toBe('2026-07-01 08:30');
});

it('rejects a HEIC that cannot be converted instead of storing garbage', function () {
    $fx = timelapseFixture();

    Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->set('newTitle', 'Back Wall')
        ->call('createCollection')
        ->set('file', UploadedFile::fake()->createWithContent('bad.heic', "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic"))
        ->call('uploadFile')
        ->assertHasErrors(['file']);

    expect(ProjectTimelapseFrame::count())->toBe(0);
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

it('names unstamped outbound photos from their "-PS" signature, never the business number', function () {
    $fx = timelapseFixture();

    $client = \App\Models\Client::factory()->create();
    $fx['project']->forceFill(['client_id' => $client->id])->save();

    $thread = \App\Models\SmsGroupThread::create([
        'from_number' => '+12247354200',
        'client_id' => $client->id,
        'vendor_id' => $fx['vendor']->id,
        'participants' => ['+18475550101'],
    ]);

    $initials = strtoupper(mb_substr($fx['user']->first_name, 0, 1).mb_substr($fx['user']->last_name, 0, 1));

    // Sent from the shared number before sent_by_user_id existed — but signed.
    \App\Models\SmsMessage::create([
        'thread_id' => $thread->id,
        'direction' => 'outbound',
        'from_number' => '+12247354200',
        'text' => "Brick staining:\n-{$initials}",
        'media_urls' => ['/storage/sms-media/2026/08/brick.jpg'],
    ]);

    // Unsigned and unstamped — the company label, not "(224) 735-4200".
    \App\Models\SmsMessage::create([
        'thread_id' => $thread->id,
        'direction' => 'outbound',
        'from_number' => '+12247354200',
        'text' => 'Automated update',
        'media_urls' => ['/storage/sms-media/2026/08/auto.jpg'],
    ]);

    $images = Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->instance()->messageImages;

    $senders = $images->pluck('sender');
    // firstNames() shortens the company label to "GS" for the chips.
    expect($senders)->toContain($fx['user']->first_name)
        ->and($senders)->toContain('GS')
        ->and($senders->filter(fn ($s) => str_contains($s, '224')))->toBeEmpty();
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

it('answers the upload right away and leaves the pixel work to the queue', function () {
    $fx = timelapseFixture();

    Queue::fake([\App\Jobs\ProcessTimelapseFrame::class]);

    Livewire::actingAs($fx['user'])
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->set('frame', UploadedFile::fake()->image('frame-0.jpg', 3000, 2250))
        ->assertHasNoErrors();

    $frame = ProjectTimelapseFrame::latest('id')->firstOrFail();

    // The request wrote only the archive copy and the row; the sequence copy
    // belongs to the job.
    Storage::disk('files')->assertExists($frame->original_path);
    Storage::disk('files')->assertMissing($frame->path);
    Queue::assertPushed(\App\Jobs\ProcessTimelapseFrame::class, fn ($job) => $job->frameId === $frame->id);

    // Until the job runs, the frame still serves — the archive stands in.
    $pending = $this->actingAs($fx['user'])->get(route('projects.timelapse.frame', $frame));
    $pending->assertSuccessful();
    [$width] = getimagesizefromstring($pending->getContent());
    expect($width)->toBe(3000);

    // The job derives the capped sequence copy.
    (new \App\Jobs\ProcessTimelapseFrame($frame->id))->handle();

    Storage::disk('files')->assertExists($frame->path);
    [$seqWidth] = getimagesizefromstring(Storage::disk('files')->get($frame->path));
    expect($seqWidth)->toBe(TimelapseStudio::MAX_EDGE);
});

it('stores a camera frame through the direct upload endpoint', function () {
    $fx = timelapseFixture();

    Queue::fake([\App\Jobs\ProcessTimelapseFrame::class]);

    $component = Livewire::actingAs($fx['user'])->test(TimelapseStudio::class, ['project' => $fx['project']]);
    $component->set('newTitle', 'Back Timelapse')->call('createCollection');
    $kitchen = ProjectTimelapse::where('project_id', $fx['project']->id)->where('title', 'Back Timelapse')->firstOrFail();

    $response = $this->actingAs($fx['user'])->post(
        route('projects.timelapse.frame.store', $fx['project']),
        [
            'frame' => UploadedFile::fake()->image('shot.jpg', 1600, 1200),
            'collection_id' => $kitchen->id,
            'taken_at' => now()->subSeconds(5)->toIso8601String(),
            'lat' => 42.1181, 'lng' => -87.9773, 'accuracy' => 12,
        ],
    );

    $response->assertCreated();

    $frame = ProjectTimelapseFrame::findOrFail($response->json('frame_id'));

    expect($frame->project_timelapse_id)->toBe($kitchen->id)
        ->and((float) $frame->latitude)->toBe(42.1181)
        ->and($frame->shot_at)->not->toBeNull();

    Storage::disk('files')->assertExists($frame->original_path);
    Queue::assertPushed(\App\Jobs\ProcessTimelapseFrame::class);

    // A forged collection id (another project's) falls back to the catch-all
    // album — never someone else's sequence.
    $foreignProject = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Other', 'client_id' => \App\Models\Client::factory()->create()->id,
        'address' => '2 Other St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
        'belongs_to_vendor_id' => Vendor::factory()->create()->id,
    ]));
    $foreign = ProjectTimelapse::withoutEvents(fn () => ProjectTimelapse::create([
        'project_id' => $foreignProject->id, 'title' => 'Not Yours',
        'kind' => ProjectTimelapse::KIND_TIMELAPSE, 'display_mode' => 'slider', 'sort_order' => 1,
    ]));

    $second = $this->actingAs($fx['user'])->post(
        route('projects.timelapse.frame.store', $fx['project']),
        ['frame' => UploadedFile::fake()->image('shot2.jpg', 800, 600), 'collection_id' => $foreign->id],
    );

    $second->assertCreated();
    expect($foreign->frames()->count())->toBe(0)
        ->and(ProjectTimelapse::where('project_id', $fx['project']->id)->where('title', 'Project Images')->first()->frames()->count())->toBe(1);
});

it('refuses direct frame uploads from out-of-scope users', function () {
    $fx = timelapseFixture();

    $strangerVendor = Vendor::factory()->create();
    $strangerVendor->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();
    $stranger = new User();
    $stranger->forceFill([
        'first_name' => 'Stranger', 'last_name' => 'Vendor',
        'email' => 'stranger.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224888####'),
        'primary_vendor_id' => $strangerVendor->id,
        'registration' => ['registered' => true],
    ]);
    $stranger->save();
    $strangerVendor->users()->attach($stranger->id, ['role_id' => 1]);

    $this->actingAs($stranger)
        ->post(route('projects.timelapse.frame.store', $fx['project']), [
            'frame' => UploadedFile::fake()->image('shot.jpg', 800, 600),
        ])
        ->assertNotFound();
});
