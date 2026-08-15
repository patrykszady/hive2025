<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTimelapse;
use App\Models\ProjectTimelapseFrame;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/** A vendor with an Admin and a plain member, plus a project + frame. */
function archiveFixture(): array
{
    Storage::fake('files');

    $vendor = Vendor::factory()->create();
    $vendor->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();
    $client = Client::factory()->create();

    $makeUser = function (Vendor $vendor, int $roleId) {
        $user = User::factory()->create();
        $user->primary_vendor_id = $vendor->id;
        $user->registration = ['registered' => true];
        $user->save();
        $vendor->users()->attach($user->id, ['role_id' => $roleId]);

        return $user;
    };

    $admin = $makeUser($vendor, 1);
    $member = $makeUser($vendor, 2);
    $taker = $makeUser($vendor, 2);

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Archive Test',
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
    $disk->put("timelapse/{$project->id}/shot.jpg", 'BLURRED-DISPLAY-COPY');
    $disk->put("timelapse/{$project->id}/original-shot.jpg", 'UNBLURRED-ARCHIVE-ORIGINAL');

    $frame = ProjectTimelapseFrame::create([
        'project_timelapse_id' => $album->id,
        'taken_by_user_id' => $taker->id,
        'filename' => 'shot.jpg',
        'path' => "timelapse/{$project->id}/shot.jpg",
        'original_path' => "timelapse/{$project->id}/original-shot.jpg",
        'disk' => 'files',
        'shot_at' => now(),
        'sort_order' => 1,
    ]);

    return compact('vendor', 'project', 'frame', 'admin', 'member', 'taker');
}

it('gives every frame a unique, unguessable archive address', function () {
    ['frame' => $frame, 'project' => $project] = archiveFixture();

    $second = ProjectTimelapseFrame::create([
        'project_timelapse_id' => $frame->project_timelapse_id,
        'filename' => 'other.jpg',
        'path' => "timelapse/{$project->id}/other.jpg",
        'disk' => 'files',
        'sort_order' => 2,
    ]);

    expect(strlen($frame->archive_token))->toBe(48)
        ->and($frame->archive_token)->toMatch('/^[A-Za-z0-9]{48}$/')
        // Neighbouring rows must not yield neighbouring addresses: knowing
        // one frame's link tells you nothing about the next one's.
        ->and($frame->archive_token)->not->toBe($second->archive_token)
        ->and(substr($frame->archive_token, 0, 8))->not->toBe(substr($second->archive_token, 0, 8));
});

it('serves the archive original to the taker and to the owning vendor admin', function () {
    ['frame' => $frame, 'taker' => $taker, 'admin' => $admin] = archiveFixture();

    $url = route('projects.timelapse.original', ['token' => $frame->archive_token]);

    $this->actingAs($taker)->get($url)
        ->assertOk()
        ->assertSee('UNBLURRED-ARCHIVE-ORIGINAL')
        // The unblurred body must never sit in a shared cache.
        ->assertHeader('Cache-Control', 'no-store, private');

    $this->actingAs($admin)->get($url)->assertOk()->assertSee('UNBLURRED-ARCHIVE-ORIGINAL');
});

it('hides the archive original from everyone else, even project viewers', function () {
    ['frame' => $frame, 'member' => $member] = archiveFixture();

    $url = route('projects.timelapse.original', ['token' => $frame->archive_token]);

    // A vendor member who can see the project and its blurred photos still
    // may not pull the unblurred archive. 404, not 403 — a token in the
    // wrong hands should not confirm it names a real file.
    $this->actingAs($member)->get($url)->assertNotFound();

    // ...but the display copy is served to them as normal.
    $this->actingAs($member)
        ->get(route('projects.timelapse.frame', ['frame' => $frame]))
        ->assertOk()
        ->assertSee('BLURRED-DISPLAY-COPY');

    // A signed-out visitor gets nothing either — the project scope hides
    // the row before the gate is even consulted.
    $this->get($url)->assertNotFound();
});

it('no longer serves the archive through the guessable frame url', function () {
    ['frame' => $frame, 'admin' => $admin] = archiveFixture();

    // The old address — sequential id plus a flag — must hand back the
    // blurred display copy now, for the owning vendor's Admin included.
    $this->actingAs($admin)
        ->get(route('projects.timelapse.frame', ['frame' => $frame]).'?original=1')
        ->assertOk()
        ->assertSee('BLURRED-DISPLAY-COPY')
        ->assertDontSee('UNBLURRED-ARCHIVE-ORIGINAL');
});

it('404s an unknown token and one from a project the viewer cannot see', function () {
    ['admin' => $admin] = archiveFixture();
    ['frame' => $foreign] = archiveFixture();

    $this->actingAs($admin)
        ->get(route('projects.timelapse.original', ['token' => str_repeat('z', 48)]))
        ->assertNotFound();

    // Another vendor's project: the scope hides it before the gate is reached.
    $this->actingAs($admin)
        ->get(route('projects.timelapse.original', ['token' => $foreign->archive_token]))
        ->assertNotFound();
});

it('offers the original link in the lightbox only to those allowed to open it', function () {
    ['project' => $project, 'frame' => $frame, 'taker' => $taker, 'member' => $member] = archiveFixture();

    $studio = new \App\Livewire\Projects\TimelapseStudio;
    $studio->project = $project;
    $collection = ProjectTimelapse::with('frames')->find($frame->project_timelapse_id);

    auth()->login($taker);
    expect($studio->lightboxFrames($collection)[0]['original'])
        ->toContain($frame->archive_token);

    auth()->login($member);
    expect($studio->lightboxFrames($collection)[0]['original'])->toBeNull();
});
