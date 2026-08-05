<?php

use App\Livewire\Projects\TimelapseStudio;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTimelapse;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The images page is the crew's page: anyone on the vendor shoots into it, and
 * the client whose job it is can look and add their own photos. Only deleting
 * stays narrow.
 */
function imagesFixture(): array
{
    Storage::fake('files');

    $vendor = Vendor::factory()->create();
    $vendor->forceFill([
        'business_type' => 'LLC',
        'registration' => ['registered' => true],
    ])->save();

    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Access Test',
        'client_id' => $client->id,
        'address' => '400 N Wheeling Rd',
        'city' => 'Prospect Heights',
        'state' => 'IL',
        'zip_code' => '60070',
        'belongs_to_vendor_id' => $vendor->id,
    ]));
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    return compact('vendor', 'client', 'project');
}

function imagesUser(Vendor $vendor, string $role): User
{
    $user = new User();
    $user->forceFill([
        'first_name' => ucfirst(strtolower($role)),
        'last_name' => 'User',
        'email' => 'images.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => $role === 'Admin' ? 1 : 2]);

    return $user;
}

function imagesClientUser(Client $client): User
{
    $user = new User();
    $user->forceFill([
        'first_name' => 'Home',
        'last_name' => 'Owner',
        'email' => 'owner.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        // No primary vendor + a client attached IS "browsing as client".
        'registration' => ['registered' => true],
    ]);
    $user->save();
    $user->clients()->attach($client->id);

    return $user;
}

it('opens the images page for a crew member who is not an admin', function () {
    $fx = imagesFixture();
    $member = imagesUser($fx['vendor'], 'Member');

    $this->actingAs($member)
        ->get(route('projects.images', $fx['project']))
        ->assertSuccessful();
});

it('opens the images page for the client whose project it is', function () {
    $fx = imagesFixture();
    $owner = imagesClientUser($fx['client']);

    $this->actingAs($owner)
        ->get(route('projects.images', $fx['project']))
        ->assertSuccessful();
});

it('lets a crew member add a photo and start a timelapse', function () {
    $fx = imagesFixture();
    $member = imagesUser($fx['vendor'], 'Member');

    Livewire::actingAs($member)
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->set('newTitle', 'Kitchen')
        ->call('createCollection')
        ->assertHasNoErrors()
        ->set('frame', UploadedFile::fake()->image('shot.jpg', 1200, 900))
        ->assertHasNoErrors();

    expect(ProjectTimelapse::where('project_id', $fx['project']->id)->where('title', 'Kitchen')->exists())->toBeTrue();
});

it('lets the client add a photo and start a timelapse on their own project', function () {
    $fx = imagesFixture();
    $owner = imagesClientUser($fx['client']);

    Livewire::actingAs($owner)
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->set('newTitle', 'Backyard')
        ->call('createCollection')
        ->assertHasNoErrors()
        ->set('frame', UploadedFile::fake()->image('shot.jpg', 1200, 900))
        ->assertHasNoErrors();

    expect(ProjectTimelapse::where('project_id', $fx['project']->id)->where('title', 'Backyard')->exists())->toBeTrue();
});

it('opens the images page for a crew member who joined mid-project', function () {
    $fx = imagesFixture();
    $fx['project']->forceFill(['created_at' => now()->subYears(2)])->save();

    $member = imagesUser($fx['vendor'], 'Member');
    $fx['vendor']->users()->updateExistingPivot($member->id, ['start_date' => now()->subMonth()]);

    // ProjectScope used to hide anything created before start_date less six
    // months, so this 404'd at route-model binding — the crew member could not
    // open the job at all, never mind shoot a progress photo into it.
    $this->actingAs($member)
        ->get(route('projects.images', $fx['project']))
        ->assertSuccessful();

    Livewire::actingAs($member)
        ->test(TimelapseStudio::class, ['project' => $fx['project']])
        ->set('frame', UploadedFile::fake()->image('shot.jpg', 1200, 900))
        ->assertHasNoErrors();
});

it('keeps a stranger out', function () {
    $fx = imagesFixture();
    $other = Client::factory()->create();
    $stranger = imagesClientUser($other);

    $this->actingAs($stranger)
        ->get(route('projects.images', $fx['project']))
        ->assertForbidden();
});
