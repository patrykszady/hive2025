<?php

use App\Models\Client;
use App\Models\EmailTracking;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/** @return array{vendor: Vendor, user: User} */
function appJobsEmailTrackingTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

function appJobsEmailTrackingProject(Vendor $vendor, Client $client): Project
{
    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Kitchen Remodel',
        'client_id' => $client->id,
        'address' => '1 Oak St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
        'belongs_to_vendor_id' => $vendor->id,
    ]));
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    return $project;
}

it('needs a token for the Projects tab email-tracking card', function () {
    $this->getJson('/api/app/v1/projects/email-tracking')->assertUnauthorized();
});

it('shows a client\'s engagement, resolving a recipient to their name and excluding the team\'s own opens', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsEmailTrackingTeamUser();
    Sanctum::actingAs($user, ['app']);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);
    $project = appJobsEmailTrackingProject($vendor, $client);

    $clientUser = User::factory()->create(['email' => 'jason@example.test', 'first_name' => 'Jason']);

    EmailTracking::create([
        'belongs_to_vendor_id' => $vendor->id,
        'project_id' => $project->id,
        'message_id' => 'msg-1',
        'thread_id' => 'thread-1',
        'email_template_name' => 'Estimate Follow Up',
        'event_type' => 'sent',
        'recipient_emails' => ['jason@example.test', $user->email],
        'event_at' => now()->subHours(2),
    ]);
    EmailTracking::create([
        'belongs_to_vendor_id' => $vendor->id,
        'project_id' => $project->id,
        'message_id' => 'msg-1-open',
        'thread_id' => 'thread-1',
        'email_template_name' => 'Estimate Follow Up',
        'event_type' => 'opened',
        'recipient_emails' => ['jason@example.test'],
        'event_at' => now()->subHour(),
    ]);
    // Internal-only template — never shows on this card.
    EmailTracking::create([
        'belongs_to_vendor_id' => $vendor->id,
        'project_id' => $project->id,
        'message_id' => 'vp-1',
        'email_template_name' => 'Vendor Payment',
        'event_type' => 'sent',
        'recipient_emails' => ['acme@example.test'],
        'event_at' => now(),
    ]);

    $response = $this->getJson('/api/app/v1/projects/email-tracking')->assertOk();

    $rows = $response->json('data');
    expect($rows)->toHaveCount(1);
    expect($rows[0]['event_type'])->toBe('opened')
        ->and($rows[0]['event_count'])->toBe(2)
        ->and($rows[0]['email_template_name'])->toBe('Estimate Follow Up')
        ->and($rows[0]['project']['id'])->toBe($project->id)
        ->and($rows[0]['recipients']['emails'])->toBe(['jason@example.test'])
        ->and($rows[0]['recipients']['names'])->toBe(['Jason '.$clientUser->last_name]);
});

it('narrows to one client with ?client_id=', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsEmailTrackingTeamUser();
    Sanctum::actingAs($user, ['app']);

    $clientA = Client::factory()->create();
    $clientA->vendors()->attach($vendor->id);
    $projectA = appJobsEmailTrackingProject($vendor, $clientA);

    $clientB = Client::factory()->create();
    $clientB->vendors()->attach($vendor->id);
    $projectB = appJobsEmailTrackingProject($vendor, $clientB);

    EmailTracking::create([
        'belongs_to_vendor_id' => $vendor->id,
        'project_id' => $projectA->id,
        'message_id' => 'a-1',
        'email_template_name' => 'Estimate Follow Up',
        'event_type' => 'sent',
        'recipient_emails' => ['a@example.test'],
        'event_at' => now(),
    ]);
    EmailTracking::create([
        'belongs_to_vendor_id' => $vendor->id,
        'project_id' => $projectB->id,
        'message_id' => 'b-1',
        'email_template_name' => 'Estimate Follow Up',
        'event_type' => 'sent',
        'recipient_emails' => ['b@example.test'],
        'event_at' => now(),
    ]);

    $response = $this->getJson('/api/app/v1/projects/email-tracking?client_id='.$clientA->id)->assertOk();

    $rows = $response->json('data');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['project']['id'])->toBe($projectA->id);
});
