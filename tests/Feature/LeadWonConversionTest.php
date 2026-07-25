<?php

use App\Jobs\MarkClientLeadWon;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function makeWonTestUser(): User
{
    return User::query()->create([
        'first_name' => 'Lead',
        'last_name' => 'Contact',
        'email' => 'won.test.'.uniqid().'@example.com',
        'cell_phone' => fake()->unique()->numerify('224777####'),
    ]);
}

/**
 * clients.vendor_id is unique (it marks a client row that IS a vendor), so
 * vendor ownership goes through the client_vendor pivot like the app does.
 */
function makeClientForVendor(Vendor $vendor): Client
{
    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    return $client;
}

/**
 * A lead whose contact belongs to $client, sitting in the given status.
 */
function makeLeadForClient(Vendor $vendor, ?Client $client, string $status = 'New', array $overrides = []): Lead
{
    $user = makeWonTestUser();

    if ($client) {
        $client->users()->attach($user->id);
    }

    $lead = Lead::create(array_merge([
        'date' => now(),
        'origin' => 'gs.construction',
        'user_id' => $user->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $user->id,
        'lead_data' => [
            'name' => 'Jane Homeowner',
            'address' => '123 Main St, Palatine, IL 60067',
        ],
    ], $overrides));

    $lead->statuses()->create([
        'title' => $status,
        'belongs_to_vendor_id' => $vendor->id,
    ]);

    return $lead;
}

it('marks a New lead Won when a project is created for its client', function () {
    $vendor = Vendor::factory()->create();
    $client = makeClientForVendor($vendor);
    $lead = makeLeadForClient($vendor, $client);

    (new MarkClientLeadWon($client->id, $vendor->id))->handle();

    expect($lead->fresh()->last_status->title)->toBe('Won');
});

it('leaves leads for other clients untouched', function () {
    $vendor = Vendor::factory()->create();
    $client = makeClientForVendor($vendor);
    $otherClient = makeClientForVendor($vendor);

    $lead = makeLeadForClient($vendor, $otherClient);

    (new MarkClientLeadWon($client->id, $vendor->id))->handle();

    expect($lead->fresh()->last_status->title)->toBe('New');
});

it('does not overwrite a human decision like Lost or Not a Fit', function (string $status) {
    $vendor = Vendor::factory()->create();
    $client = makeClientForVendor($vendor);
    $lead = makeLeadForClient($vendor, $client, $status);

    (new MarkClientLeadWon($client->id, $vendor->id))->handle();

    expect($lead->fresh()->last_status->title)->toBe($status);
})->with(['Lost', 'Not a Fit', 'Message 1']);

it('writes no duplicate status row when the lead is already Won', function () {
    $vendor = Vendor::factory()->create();
    $client = makeClientForVendor($vendor);
    $lead = makeLeadForClient($vendor, $client, 'Won');

    (new MarkClientLeadWon($client->id, $vendor->id))->handle();

    expect($lead->statuses()->count())->toBe(1);
});

it('queues the job when a project is created', function () {
    Queue::fake();

    $vendor = Vendor::factory()->create();
    $client = makeClientForVendor($vendor);
    $user = makeWonTestUser();
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);
    $user->update(['primary_vendor_id' => $vendor->id]);
    $this->actingAs($user);

    $project = Project::create([
        'client_id' => $client->id,
        'project_name' => 'Kitchen',
        'address' => '123 Main St',
        'city' => 'Palatine',
        'state' => 'IL',
        'zip_code' => 60067,
    ]);

    Queue::assertPushed(MarkClientLeadWon::class, fn ($job) => $job->clientId === $client->id
        && $job->vendorId === $project->belongs_to_vendor_id);
});

it('backfills New leads whose client already has a project', function () {
    $vendor = Vendor::factory()->create();
    $user = makeWonTestUser();
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);
    $user->update(['primary_vendor_id' => $vendor->id]);
    $this->actingAs($user);

    $withProject = makeClientForVendor($vendor);
    $withoutProject = makeClientForVendor($vendor);

    $convertedLead = makeLeadForClient($vendor, $withProject);
    $pendingLead = makeLeadForClient($vendor, $withoutProject);

    Project::withoutEvents(fn () => Project::create([
        'client_id' => $withProject->id,
        'project_name' => 'Bath',
        'address' => '9 Oak St',
        'city' => 'Palatine',
        'state' => 'IL',
        'zip_code' => 60067,
        'belongs_to_vendor_id' => $vendor->id,
    ]));

    $this->artisan('leads:backfill-won')->assertSuccessful();

    expect($convertedLead->fresh()->last_status->title)->toBe('Won')
        ->and($pendingLead->fresh()->last_status->title)->toBe('New');
});

it('changes nothing on a dry run', function () {
    $vendor = Vendor::factory()->create();
    $user = makeWonTestUser();
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);
    $user->update(['primary_vendor_id' => $vendor->id]);
    $this->actingAs($user);

    $client = makeClientForVendor($vendor);
    $lead = makeLeadForClient($vendor, $client);

    Project::withoutEvents(fn () => Project::create([
        'client_id' => $client->id,
        'project_name' => 'Bath',
        'address' => '9 Oak St',
        'city' => 'Palatine',
        'state' => 'IL',
        'zip_code' => 60067,
        'belongs_to_vendor_id' => $vendor->id,
    ]));

    $this->artisan('leads:backfill-won --dry-run')->assertSuccessful();

    expect($lead->fresh()->last_status->title)->toBe('New');
});
